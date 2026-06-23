#!/usr/bin/env python3
import ast
import sys
import json

class SymbolTracker(ast.NodeVisitor):
    def __init__(self):
        self.aliases = {}  # Maps alias name -> canonical module (e.g. 'x' -> 'os')
        self.imported_funcs = {}  # Maps local name -> canonical path (e.g. 'run' -> 'subprocess.run')

    def visit_Import(self, node):
        for name in node.names:
            local_name = name.asname or name.name
            self.aliases[local_name] = name.name
        self.generic_visit(node)

    def visit_ImportFrom(self, node):
        module = node.module or ""
        # Resolve dynamic namespaces (builtins and __builtins__ mapped to builtins)
        canonical_module = "builtins" if module in ("builtins", "__builtins__") else module
        for name in node.names:
            local_name = name.asname or name.name
            self.imported_funcs[local_name] = f"{canonical_module}.{name.name}"
        self.generic_visit(node)


class SkillSecurityVisitor(ast.NodeVisitor):
    def __init__(self, tracker):
        self.tracker = tracker
        self.findings = []  # List of dict structural findings
        self.seen_findings = set()  # Deduplication set (GAP 12)
        self.in_deferred_context = False

    def add_finding(self, severity, line, node_type, rule_id, message):
        # Deduplicate findings by line, rule_id, and node_type (GAP 12)
        finding_key = (line, rule_id, node_type)
        if finding_key not in self.seen_findings:
            self.seen_findings.add(finding_key)
            self.findings.append({
                "severity": severity,
                "line": line,
                "node_type": node_type,
                "rule_id": rule_id,
                "message": message,
                "deferred_execution": self.in_deferred_context  # Expose deferred context flag (GAP 11)
            })

    def _resolve_base_name(self, node, depth=0, max_depth=10):
        # Recursively resolve attribute chains, capping traversal depth to prevent stack overflow (GAP 10)
        if depth > max_depth:
            return None
        if isinstance(node, ast.Name):
            return self.tracker.aliases.get(node.id, node.id)
        elif isinstance(node, ast.Attribute):
            base = self._resolve_base_name(node.value, depth + 1, max_depth)
            if base:
                return f"{base}.{node.attr}"
        return None

    def visit_FunctionDef(self, node):
        # Track functions containing deferred logic
        old_context = self.in_deferred_context
        self.in_deferred_context = True
        self.generic_visit(node)
        self.in_deferred_context = old_context

    def visit_AsyncFunctionDef(self, node):
        self.visit_FunctionDef(node)

    def visit_Lambda(self, node):
        old_context = self.in_deferred_context
        self.in_deferred_context = True
        self.generic_visit(node)
        self.in_deferred_context = old_context

    def visit_Call(self, node):
        # 1. Direct and aliased dangerous builtins (eval, exec, __import__, getattr)
        if isinstance(node.func, ast.Name):
            func_id = node.func.id
            resolved_func = self.tracker.imported_funcs.get(func_id, func_id)
            
            # Resolve dynamic execution namespaces (e.g., builtins.eval, __builtins__.eval)
            if resolved_func in ("eval", "exec", "builtins.eval", "builtins.exec", "__builtins__.eval", "__builtins__.exec"):
                self.add_finding("CRITICAL", getattr(node, "lineno", -1), "ast.Call", "dangerous_builtin", f"Dangerous builtin call detected: {resolved_func}()")
            elif resolved_func == "__import__":
                self.add_finding("CRITICAL", getattr(node, "lineno", -1), "ast.Call", "dynamic_import", "Dynamic import call detected (__import__)")
            elif resolved_func.startswith("subprocess."):
                self.add_finding("CRITICAL", getattr(node, "lineno", -1), "ast.Call", "subprocess_spawn", f"Subprocess spawning detected: {resolved_func}")
            elif resolved_func in ("getattr", "builtins.getattr", "__builtins__.getattr"):
                self.add_finding("HIGH", getattr(node, "lineno", -1), "ast.Call", "dynamic_getattr", "Dynamic attribute resolution (getattr) detected")

        # Check for calls on the result of getattr (e.g. getattr(os, "system")("arg"))
        elif isinstance(node.func, ast.Call):
            if isinstance(node.func.func, ast.Name) and node.func.func.id in ("getattr", "builtins.getattr", "__builtins__.getattr"):
                self.add_finding("HIGH", getattr(node, "lineno", -1), "ast.Call", "dynamic_getattr_call", "Dynamic attribute resolution (getattr) call detected")

        # 2. Check attribute call chains (e.g., os.system(), subprocess.run()) (GAP 10)
        elif isinstance(node.func, ast.Attribute):
            self._check_attribute_call(node.func)

        self.generic_visit(node)

    def visit_Attribute(self, node):
        self._check_attribute_reference(node)
        self.generic_visit(node)

    def _check_attribute_call(self, attr_node):
        # Resolve target path (accounting for imports like 'import subprocess as sp') (GAP 10)
        resolved_path = self._resolve_base_name(attr_node)
        if not resolved_path:
            return

        if resolved_path in ("os.system", "os.popen", "os.spawn") or resolved_path.startswith("os.") and resolved_path.split(".")[1] in ("system", "popen", "spawn"):
            self.add_finding("HIGH", getattr(attr_node, "lineno", -1), "ast.Attribute", "dangerous_os_execution", f"Dangerous execution call detected: {resolved_path}()")
        elif resolved_path in ("subprocess.run", "subprocess.Popen", "subprocess.call", "subprocess.check_output", "subprocess.check_call") or resolved_path.startswith("subprocess."):
            self.add_finding("CRITICAL", getattr(attr_node, "lineno", -1), "ast.Attribute", "subprocess_execution", f"Subprocess execution method detected: {resolved_path}()")
        elif resolved_path == "importlib.import_module":
            self.add_finding("MEDIUM", getattr(attr_node, "lineno", -1), "ast.Attribute", "dynamic_import_lib", "Dynamic import loader detected: importlib.import_module()")

    def _check_attribute_reference(self, attr_node):
        # Flag dynamic attribute getters (e.g. getattr(os, "system"))
        if isinstance(attr_node.value, ast.Call) and isinstance(attr_node.value.func, ast.Name) and attr_node.value.func.id == "getattr":
            self.add_finding("HIGH", getattr(attr_node, "lineno", -1), "ast.Attribute", "dynamic_getattr_reference", "Dynamic attribute resolution (getattr) detected")


def validate_skill(filepath, block_levels=None):
    if block_levels is None:
        block_levels = ["CRITICAL"] # Configurable severity blocking

    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()
            tree = ast.parse(content, filename=filepath)
    except SyntaxError as e:
        print(f"Error parsing skill syntax: {e}")
        return 1

    tracker = SymbolTracker()
    tracker.visit(tree)

    visitor = SkillSecurityVisitor(tracker)
    visitor.visit(tree)

    if visitor.findings:
        print("Skill security scan completed with findings:")
        severity_rank = {"CRITICAL": 3, "HIGH": 2, "MEDIUM": 1, "LOW": 0}
        visitor.findings.sort(key=lambda x: severity_rank.get(x["severity"], 0), reverse=True)
        for finding in visitor.findings:
            print(f"[{finding['severity']}] Line {finding['line']}: {finding['message']} (Rule: {finding['rule_id']}, Node: {finding['node_type']})")
        
        # Block integration on configured levels
        if any(finding["severity"] in block_levels for finding in visitor.findings):
            return 1
    else:
        print("Skill security validation passed.")
    return 0

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: validate_skill_security.py <skill-path>")
        sys.exit(1)
    sys.exit(validate_skill(sys.argv[1]))

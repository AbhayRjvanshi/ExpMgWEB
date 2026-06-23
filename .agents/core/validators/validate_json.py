import json
import sys
import math
from pathlib import Path

def tier1_scan_schema(schema, visited=None):
    """
    Scans the JSON schema recursively for cycle detections (recursive definitions)
    and unsupported schema keywords. Uses DFS active recursion stack tracking.
    """
    if visited is None:
        visited = set()
    
    if not isinstance(schema, dict):
        return
        
    schema_id = id(schema)
    if schema_id in visited:
        raise ValueError("Recursive cycle detected in schema")
    
    visited.add(schema_id)
    
    try:
        # Check for and reject unsupported keywords to avoid dangerous ambiguity
        for unsupported in ("oneOf", "$ref", "dependencies"):
            if unsupported in schema:
                raise NotImplementedError(f"Unsupported schema keyword: {unsupported}")
            
        # Recurse through properties
        if "properties" in schema and isinstance(schema["properties"], dict):
            for prop, sub_schema in schema["properties"].items():
                tier1_scan_schema(sub_schema, visited)
                
        # Recurse through items (array elements)
        if "items" in schema:
            if isinstance(schema["items"], dict):
                tier1_scan_schema(schema["items"], visited)
            elif isinstance(schema["items"], list):
                for sub_schema in schema["items"]:
                    tier1_scan_schema(sub_schema, visited)

        # Recurse through allOf, anyOf
        for key in ("allOf", "anyOf"):
            if key in schema and isinstance(schema[key], list):
                for sub_schema in schema[key]:
                    tier1_scan_schema(sub_schema, visited)

        # Recurse through definitions, $defs, patternProperties
        for key in ("definitions", "$defs", "patternProperties"):
            if key in schema and isinstance(schema[key], dict):
                for sub_schema in schema[key].values():
                    tier1_scan_schema(sub_schema, visited)

        # Recurse through additionalProperties
        if "additionalProperties" in schema and isinstance(schema["additionalProperties"], dict):
            tier1_scan_schema(schema["additionalProperties"], visited)
    finally:
        # Backtrack DFS node to clear it for sibling paths
        visited.remove(schema_id)

def tier1_validate_data(data, schema, path=""):
    """
    Recursively validates data against custom magnitude constraints,
    non-finite float values (NaN/Infinity), and property counts.
    Enforces strict JSON typing (booleans are not counted as integers).
    """
    # Reject boolean values for integer/number schemas explicitly
    if isinstance(data, bool):
        if isinstance(schema, dict) and schema.get("type") in ("integer", "number"):
            raise TypeError(f"Type error at {path}: boolean is not allowed for integer/number schemas")

    # 1. Check numeric magnitudes, NaN/Infinity
    if isinstance(data, (int, float)) and not isinstance(data, bool):
        if isinstance(data, float) and not math.isfinite(data):
            raise ValueError("Non-finite numeric value detected")
        if abs(data) > 10**12:
            raise ValueError("Numeric magnitude exceeds permitted limit")
            
    # 2. Check property counts
    if isinstance(data, dict):
        if len(data.keys()) > 50000:
            raise ValueError("Object property count exceeds limit")
            
        prop_schemas = {}
        if isinstance(schema, dict):
            prop_schemas = schema.get("properties", {})
            if not isinstance(prop_schemas, dict):
                prop_schemas = {}
                
        for k, v in data.items():
            sub_schema = prop_schemas.get(k, {})
            tier1_validate_data(v, sub_schema, f"{path}.{k}")
            
    if isinstance(data, list):
        item_schema = {}
        if isinstance(schema, dict):
            item_schema = schema.get("items", {})
            if not isinstance(item_schema, dict):
                item_schema = {}
                
        for idx, item in enumerate(data):
            tier1_validate_data(item, item_schema, f"{path}[{idx}]")

def validate_json():
    if len(sys.argv) < 3:
        print("Usage: validate_json.py <json-file> <schema-file>")
        return 1
    
    json_path = Path(sys.argv[1])
    schema_path = Path(sys.argv[2])
    
    if not json_path.exists():
        print(f"Error: JSON file not found: {json_path}")
        return 1
    if not schema_path.exists():
        print(f"Error: Schema file not found: {schema_path}")
        return 1
        
    try:
        from jsonschema import validate, ValidationError
    except ImportError:
        print("jsonschema dependency missing")
        return 1

    try:
        data = json.loads(json_path.read_text(encoding='utf-8'))
        schema = json.loads(schema_path.read_text(encoding='utf-8'))
        
        # Run Tier-1 checks before full jsonschema validation
        tier1_scan_schema(schema)
        tier1_validate_data(data, schema)
        
        validate(instance=data, schema=schema)
        print("Validation Successful.")
        return 0
    except ValidationError as e:
        print(f"Validation Error: {e.message}")
        return 1
    except Exception as e:
        print(f"Error during validation: {e}")
        return 1

if __name__ == "__main__":
    sys.exit(validate_json())

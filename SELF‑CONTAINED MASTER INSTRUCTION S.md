SELF‑CONTAINED MASTER INSTRUCTION SET
For Gemini CLI: Build a Portable Meta‑Skill System
Purpose
Create a system of four meta‑skills that any AI coding agent (Gemini CLI, Claude, Cursor, etc.) can use to:

Discover what skills a project needs.

Discover missing MCPs/plugins/tools.

Architect new skills automatically.

Plan UI design collaboratively with a human.

Critical design rule: Separate policy (what to do, no tool names) from mechanism (how a runtime does it, inside adapters). The system is portable across runtimes.

Honest limitations: This system requires file read/write, shell command execution (or equivalent), and human‑in‑loop input. It will not work on headless or API‑only agents without additional orchestration.

1. Directory Structure (Create Exactly This)
text
.agents/
├── core/                                 # Portable, runtime‑agnostic
│   ├── global-policy.md
│   ├── skill-template.md
│   ├── contracts/                        # JSON Schema files
│   ├── permissions/                      # Write-permission definitions per skill type
│   │   ├── meta-skills.json
│   │   ├── generated-skills.json
│   │   ├── adapters.json
│   │   └── validators.json
│   ├── validators/                       # Validation scripts (secure)
│   │   ├── validate_json.sh
│   │   ├── validate_design_tokens.sh
│   │   ├── validate_policy.sh            # Lints POLICY sections for forbidden tool names
│   │   └── policy-blocklist.txt          # Forbidden terms list – update when adding runtimes
│   ├── runtime-profiles/                 # JSON files describing each runtime
│   ├── config.json                       # Configuration (registry URL, cache TTL)
│   └── docs/                             # Documentation
├── adapters/                             # Runtime‑specific execution logic
│   ├── gemini-cli/
│   ├── claude/
│   ├── cursor/
│   └── staging/                          # Proposed adapters from unlisted runtimes
├── skills/
│   ├── meta/                             # The four system meta‑skills (never overwritten by skill-architect)
│   │   ├── project-skill-discovery/
│   │   ├── mcp-plugin-discovery/
│   │   ├── skill-architect/
│   │   └── design-system-planner/
│   └── generated/                        # Project‑specific skills created by skill-architect
├── orchestration/                        # Shared state and outputs (neutral name)
└── .allow_network                        # Optional flag file. If present, network fetches are permitted.
                                          # If absent, all skills run offline. Create with: touch .agents/.allow_network
All paths are relative to the project root (or ~/.agents/ if installed globally). The orchestration/ directory is used for inter‑skill data handoff.

## 2. Phase 0: Pre-Build Verification (Mandatory First Step)

Before writing any file, run these four checks and record the results:

1. Run `python3 --version` — confirm Python 3 is available. Note the version.
2. Run `pip show jsonschema 2>/dev/null || echo "jsonschema not installed"` — 
   note whether the jsonschema library is installed.
3. Run `jq --version 2>/dev/null || echo "jq not available"` — note whether 
   jq is available as a fallback validator.
4. Run `touch .agents_write_test && rm .agents_write_test && echo "write OK"` — 
   confirm write access to the project root.

**Decision rules:**
- If Python 3 is unavailable AND jq is unavailable: stop immediately and 
  report to the human. The validator scripts cannot run. Do not proceed.
- If Python 3 is available but jsonschema is not installed: report this to 
  the human and ask whether to run `pip install jsonschema` before proceeding. 
  Do not assume permission to install packages.
- If write access fails: stop and report to the human.
- If all checks pass or jsonschema is confirmed installable: proceed to 
  creating the runtime profile JSON files below.

Then create a runtime profile for each target platform:

File: .agents/core/runtime-profiles/gemini-cli.json

json
{
  "runtime": "gemini-cli",
  "supports_auto_context_loading": true,
  "context_file": "GEMINI.md",
  "supports_file_write": true,
  "supports_shell_execution": true,
  "supports_structured_human_input": true,
  "human_input_tool": "ask_user",
  "supports_exit_code_validation": true,
  "notes": "State must be stored in orchestration/phase.json manually.",
  "tool_mappings": {
    "list_directory": "glob",
    "read_file": "read_file",
    "write_json": "write_file",
    "ask_human": "ask_user — extract selection from response payload",
    "run_validator": "run_shell_command — capture exit code; non-zero means halt",
    "network_fetch": "run_shell_command with curl"
  }
}
File: .agents/core/runtime-profiles/claude.json

json
{
  "runtime": "claude",
  "supports_auto_context_loading": true,
  "context_file": "CLAUDE.md",
  "supports_file_write": true,
  "supports_shell_execution": true,
  "supports_structured_human_input": false,
  "human_input_tool": "conversational_turn",
  "supports_exit_code_validation": true,
  "notes": "Human input is conversational — ask explicitly and wait for reply. No structured radio buttons. Shell execution via bash_tool.",
  "tool_mappings": {
    "list_directory": "bash_tool with find or ls",
    "read_file": "bash_tool with cat",
    "write_json": "create_file or str_replace",
    "ask_human": "conversational turn — state options clearly, wait for explicit reply; do not assume a default",
    "run_validator": "bash_tool — capture exit code; non-zero means halt",
    "network_fetch": "bash_tool with curl"
  }
}
File: .agents/core/runtime-profiles/cursor.json

json
{
  "runtime": "cursor",
  "supports_auto_context_loading": true,
  "context_file": ".cursorrules",
  "supports_file_write": true,
  "supports_shell_execution": true,
  "supports_structured_human_input": false,
  "human_input_tool": "inline_editor_prompt",
  "supports_exit_code_validation": true,
  "notes": "Human input via inline editor prompt. Confirmation required before generating any diff. Shell execution via integrated terminal.",
  "tool_mappings": {
    "list_directory": "integrated file explorer or terminal find",
    "read_file": "open in editor context",
    "write_json": "create or edit file via editor",
    "ask_human": "inline editor prompt — wait for confirmation before generating any diff",
    "run_validator": "integrated terminal — non-zero exit means halt",
    "network_fetch": "terminal curl"
  }
}
Config File (.agents/core/config.json)
Create this file on first install:

json
{
  "mcp_registry_url": "https://modelcontextprotocol.io",
  "mcp_cache_max_age_days": 7
}
This file is the single source of truth for configurable values. Skills must read from it rather than hardcoding values.

3. The Universal Skill File Format (7 Mandatory Sections)
Every SKILL.md file in skills/meta/ must have these sections in this order. No omissions.

markdown
## POLICY
(plain language, no runtime specifics)

## CONTRACTS
(JSON Schemas and file paths)

## ADAPTER HINTS
(runtime‑specific mappings, including self‑extension protocol as an HTML comment)

## FAILURE STATES
(what fails, how to recover)

## SAFETY RULES
(forbidden actions, anti‑hallucination)

## HUMAN OVERRIDE RULES
(where approval is mandatory)

## VERSIONING
(version, compatibility, last validated date)
Example good POLICY (from project-skill-discovery):

text
Read the project’s root directory. Use file listing tools to map structure.
Read dependency manifests (package.json, composer.json, go.mod, requirements.txt).
Do NOT read every file. Instead, after reading manifests, read 2–3 representative files from each major domain (e.g., one controller, one model, one service) to infer actual patterns.
Output a JSON file (see CONTRACTS) to `orchestration/skill_requirements.json`.
Then run the validator script. If validation fails, halt and report error.
Bad POLICY (not portable – reject):

text
Use glob, then ask_user, then run_shell_command with jq.

4. CONTRACTS: JSON Schemas (Place in .agents/core/contracts/)
4.1 skill_requirements.schema.json
json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "required": ["project_type", "required_skills"],
  "properties": {
    "project_type": {"type": "string"},
    "required_skills": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["skill_name", "justification"],
        "properties": {
          "skill_name": {"type": "string"},
          "justification": {"type": "string"},
          "dependencies": {"type": "array", "items": {"type": "string"}}
        }
      }
    }
  }
}
4.2 mcp_recommendations.schema.json
json
{
  "type": "object",
  "required": ["recommended_mcps", "source"],
  "properties": {
    "recommended_mcps": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["package", "reasoning"],
        "properties": {
          "package": {"type": "string"},
          "args": {"type": "array", "items": {"type": "string"}},
          "env_requirements": {"type": "array", "items": {"type": "string"}},
          "reasoning": {"type": "string"}
        }
      }
    },
    "source": {
      "type": "string",
      "enum": ["cache", "live"]
    },
    "error_message": {
      "type": "string",
      "description": "Populated when no cache and no network are available. Empty string otherwise."
    }
  }
}
Note: source is required – no optionality. error_message is optional.

4.3 skill_plan.schema.json
json
{
  "type": "object",
  "required": ["generated_skills", "next_phase", "capability_tags"],
  "properties": {
    "generated_skills": {"type": "array", "items": {"type": "string"}},
    "warnings": {"type": "array", "items": {"type": "string"}},
    "next_phase": {"type": "string", "enum": ["PHASE_3_DESIGN", "PHASE_4_CODE", "PHASE_ERROR"]},
    "capability_tags": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["skill_name", "tags"],
        "properties": {
          "skill_name": {"type": "string"},
          "tags": {"type": "array", "items": {"type": "string"}}
        }
      }
    }
  }
}
4.4 design_tokens.schema.json
json
{
  "type": "object",
  "required": ["human_approved_choice", "typography", "spacing", "colors"],
  "properties": {
    "typography": {"type": "object"},
    "spacing": {"type": "object"},
    "colors": {"type": "object"},
    "human_approved_choice": {"type": "string", "minLength": 1},
    "timestamp": {"type": "string"}
  }
}
5. Validator Scripts (SECURE – No Shell Injection)
Place both scripts in .agents/core/validators/. Make them executable (chmod +x).

5.1 validate_json.sh – Secure and Robust
bash
#!/bin/bash
# validate_json.sh <json-file> <schema-file>
# Returns 0 if valid, 1 otherwise.
# Uses Python jsonschema if available, else jq required‑field check.
# Security: passes filenames via environment, not string interpolation.

JSON_FILE="$1"
SCHEMA_FILE="$2"

if [ -z "$JSON_FILE" ] || [ -z "$SCHEMA_FILE" ]; then
    echo "Usage: validate_json.sh <json-file> <schema-file>" >&2
    exit 1
fi

# Export to environment to avoid shell injection
export VALIDATE_JSON_FILE="$JSON_FILE"
export VALIDATE_SCHEMA_FILE="$SCHEMA_FILE"

# Try Python first
if command -v python3 &> /dev/null; then
    python3 - << 'PYEOF'
import json, sys, os
try:
    from jsonschema import validate, ValidationError
    HAS_JSONSCHEMA = True
except ImportError:
    HAS_JSONSCHEMA = False

json_file = os.environ.get('VALIDATE_JSON_FILE')
schema_file = os.environ.get('VALIDATE_SCHEMA_FILE')

if not json_file or not schema_file:
    sys.exit(1)

try:
    with open(json_file) as f:
        data = json.load(f)
    with open(schema_file) as f:
        schema = json.load(f)
except Exception as e:
    print(f"ERROR: Could not read files: {e}", file=sys.stderr)
    sys.exit(1)

if HAS_JSONSCHEMA:
    try:
        validate(instance=data, schema=schema)
        sys.exit(0)
    except ValidationError as e:
        print(f"Validation error: {e.message}", file=sys.stderr)
        sys.exit(1)
else:
    # Fallback: indicate to shell that we need jq
    sys.exit(2)
PYEOF
    PYTHON_EXIT=$?
    if [ $PYTHON_EXIT -eq 0 ]; then
        exit 0
    elif [ $PYTHON_EXIT -eq 2 ]; then
        # Fallback to jq
        :
    else
        exit 1
    fi
fi

# Fallback: jq required‑field check
if ! command -v jq &> /dev/null; then
    echo "ERROR: Neither Python (with jsonschema) nor jq is available." >&2
    echo "Please install jq or run: pip install jsonschema" >&2
    exit 1
fi

# NOTE: This jq fallback only checks top-level required fields.
# Nested required fields (inside items or properties) are not validated.
# Use Python + jsonschema for full schema compliance.
REQUIRED_FIELDS=$(jq -r '.required[]?' "$SCHEMA_FILE" 2>/dev/null)
if [ -z "$REQUIRED_FIELDS" ]; then
    echo "WARNING: No required fields defined in schema. Only checking if JSON is valid." >&2
    jq empty "$JSON_FILE" 2>/dev/null
    exit $?
fi

MISSING=""
for field in $REQUIRED_FIELDS; do
    if ! jq -e ".$field" "$JSON_FILE" > /dev/null 2>&1; then
        MISSING="$MISSING $field"
    fi
done

if [ -n "$MISSING" ]; then
    echo "ERROR: Missing required fields:$MISSING" >&2
    exit 1
fi

echo "Fallback validation passed (required fields exist)."
exit 0
5.2 validate_design_tokens.sh – Fixed with Python fallback and secure jq
bash
#!/bin/bash
# validate_design_tokens.sh <json-file>
# Checks that human_approved_choice exists and is non‑empty.

JSON_FILE="$1"

if [ -z "$JSON_FILE" ]; then
    echo "Usage: validate_design_tokens.sh <json-file>" >&2
    exit 1
fi

if ! command -v jq &> /dev/null; then
    echo "WARNING: jq not found. Using Python fallback." >&2
    if command -v python3 &> /dev/null; then
        export VALIDATE_DT_FILE="$JSON_FILE"
        python3 - << 'PYEOF'
import json, sys, os
json_file = os.environ.get('VALIDATE_DT_FILE')
try:
    with open(json_file) as f:
        data = json.load(f)
    choice = data.get('human_approved_choice', '')
    if not choice or not choice.strip():
        print("ERROR: human_approved_choice is missing or empty.", file=sys.stderr)
        sys.exit(1)
    sys.exit(0)
except Exception as e:
    print(f"ERROR: {e}", file=sys.stderr)
    sys.exit(1)
PYEOF
        exit $?
    else
        echo "ERROR: Neither jq nor Python available. Cannot validate." >&2
        exit 1
    fi
fi

# jq available
export VALIDATE_DT_FILE="$JSON_FILE"
CHOICE=$(jq -r '.human_approved_choice // ""' "$VALIDATE_DT_FILE")
if [ -z "$CHOICE" ] || [ "$CHOICE" = "null" ]; then
    echo "ERROR: human_approved_choice is missing or empty." >&2
    exit 1
fi

echo "Design tokens validation passed (human_approved_choice only)."
exit 0
5.3 validate_policy.sh – Policy linting
Create .agents/core/validators/validate_policy.sh:

bash
#!/bin/bash
# validate_policy.sh [<skills-directory>]
# Scans all SKILL.md files for forbidden tool names in POLICY sections.
# Returns 0 if clean, 1 if violations found.

SKILLS_DIR="${1:-$(dirname "$0")/../../skills}"
BLOCKLIST="$(dirname "$0")/policy-blocklist.txt"

if [ ! -f "$BLOCKLIST" ]; then
    echo "ERROR: Blocklist not found at $BLOCKLIST" >&2
    exit 1
fi

VIOLATIONS=0

while IFS= read -r -d '' skillfile; do
    # Extract only the POLICY section content
    policy_content=$(awk '/^## POLICY/{found=1; next} found && /^## /{exit} found{print}' "$skillfile")

    [ -z "$policy_content" ] && continue

    while IFS= read -r term; do
        # Skip empty lines and comments
        [[ -z "$term" || "$term" == \#* ]] && continue

        if echo "$policy_content" | grep -qw "$term"; then
            echo "VIOLATION: '$term' found in POLICY section of $skillfile" >&2
            VIOLATIONS=$((VIOLATIONS + 1))
        fi
    done < "$BLOCKLIST"
done < <(find "$SKILLS_DIR" -name "SKILL.md" -print0)

if [ $VIOLATIONS -gt 0 ]; then
    echo "ERROR: $VIOLATIONS violation(s) found. POLICY sections must not contain runtime-specific tool names." >&2
    exit 1
fi

echo "Policy lint passed. No forbidden terms found."
exit 0
Make it executable.

5.4 policy-blocklist.txt
Create .agents/core/validators/policy-blocklist.txt:

text
# Runtime-specific tool names forbidden in POLICY sections.
# Add new entries as new runtimes are adopted.
# Lines starting with # are comments. One term per line.

# Gemini CLI
ask_user
run_shell_command
glob
read_file
write_file

# Claude
bash_tool
str_replace
create_file

# Cursor
generate_diff

# Generic runtime-specific terms
exit_plan_mode
invoke_skill
activate_skill

6. Global Policy & State Machine (With Retry Limits)

6.1 File: .agents/core/global-policy.md
Must contain:

markdown
# Global Orchestration Policy

## Phases
1. PHASE_1_DISCOVERY – run project-skill-discovery and mcp-plugin-discovery
2. PHASE_2_ARCHITECT – run skill-architect
3. PHASE_3_DESIGN – run design-system-planner (requires human approval)
4. PHASE_4_CODE – coding skills allowed

## State File
`.agents/orchestration/phase.json` has:
```json
{
  "current_phase": "PHASE_1_DISCOVERY",
  "status": "PENDING",
  "last_error": null,
  "retry_count": 0,
  "max_retries": 3
}
```

## Transition Rules
- A phase may advance only after its output JSON is validated by the corresponding validator script.

- If validation fails: increment retry_count. If retry_count < max_retries, set status = "PARTIAL_RECOVERY" and allow retry (after human fixes input). If retry_count >= max_retries, set status = "ERROR_HALTED" and do not retry automatically.

- Human must manually edit phase.json to reset retry_count to 0 and status to "PENDING" to recover.

## Global Directives
- Never write implementation code before reaching PHASE_4_CODE.

- Always read .agents/orchestration/phase.json at session start.

- Policy sections in skills must never contain runtime‑specific tool names.

- Before any file write operation, read the relevant permission file in .agents/core/permissions/ for the current skill type. Verify the target path falls under may_write_to. If the path appears in may_NOT_write_to or is not listed in may_write_to, halt immediately and report a permission violation. Do not proceed until the violation is resolved by human intervention.

---

### 6.2 Example `phase.json` (create as a template)

```json
{
  "current_phase": "PHASE_1_DISCOVERY",
  "status": "PENDING",
  "last_error": null,
  "retry_count": 0,
  "max_retries": 3
}
7. The Four Meta‑Skills (Complete Specifications)
For each skill, you will write a SKILL.md following the 7‑section format (POLICY, CONTRACTS, ADAPTER HINTS, FAILURE STATES, SAFETY RULES, HUMAN OVERRIDE RULES, VERSIONING). Below are the mandatory contents for each section.

7.1 project-skill-discovery (in skills/meta/project-skill-discovery/SKILL.md)
POLICY:

text
Read the project root directory. Use file listing tools to map directory structure.
Read dependency manifests: package.json, composer.json, go.mod, requirements.txt, Cargo.toml, build.gradle, etc. (as many as exist).
Do NOT read every file. Instead, after reading manifests, select 2–3 representative files from each major domain (e.g., one controller, one model, one service, one test file) and read them to infer actual architecture patterns (e.g., ORM vs raw SQL, framework usage).
Output a JSON file (see CONTRACTS) to `.agents/orchestration/skill_requirements.json`.
Then run `../core/validators/validate_json.sh` with the output and the schema. If exit code ≠ 0, halt and report error.
CONTRACTS:

Input: none (implicit – current working directory)

Output: .agents/orchestration/skill_requirements.json using schema skill_requirements.schema.json

Validator: validate_json.sh skill_requirements.json skill_requirements.schema.json

ADAPTER HINTS:
```html
<!--
UNLISTED PLATFORM PROTOCOL:
1. Read POLICY. This is your source of truth.
2. Read CONTRACTS. Your output must match exactly.
3. Map required actions to your native tools:
   - read files
   - write JSON
   - run validation command
4. Do NOT edit this SKILL.md directly.
5. Write your adapter proposal to: .agents/adapters/staging/<your-platform>.adapter.md
6. Present proposal to human. Wait for explicit approval.
7. After approval, append your block (starting with "### <platform>") to this ADAPTER HINTS section.
8. Never delete, edit, or reorder existing content. Only append.
-->
```

### gemini-cli
- List directory / find files: `glob`
- Read a file: `read_file`
- Write JSON output: `write_file`
- Run a validator script: `run_shell_command` — capture exit code; non-zero means halt

### claude
- List directory / find files: `bash_tool` with `find` or `ls`
- Read a file: `bash_tool` with `cat`
- Write JSON output: `create_file` or `str_replace`
- Run a validator script: `bash_tool` — capture exit code; non-zero means halt

### cursor
- List directory / find files: integrated file explorer or terminal `find`
- Read a file: open in editor context
- Write JSON output: create or edit file via editor
- Run a validator script: integrated terminal — non-zero exit means halt

FAILURE STATES:

No manifests found → set project_type = "unknown", required_skills empty, but still pass validation.

Sampling fails (file read error) → log warning, continue with manifest‑only inference.

SAFETY RULES:

Never read more than 50 files total.

Never follow symlinks outside the project root.

Never execute any code.

HUMAN OVERRIDE RULES: None required.

VERSIONING: 1.0.0 – compatible with Gemini CLI, Claude, Cursor.

7.2 mcp-plugin-discovery (in skills/meta/mcp-plugin-discovery/SKILL.md)
POLICY:

text
Read `.agents/orchestration/skill_requirements.json`.
Before writing recommendations, read `.agents/orchestration/skill_registry.json`. 
Do not recommend MCP tooling that directly duplicates functionality already covered by a skill in the registry.
Check local cache at `.agents/orchestration/mcp_cache.json`. 
If cache exists and its `last_updated` timestamp is within the number of days specified by `mcp_cache_max_age_days` in `.agents/core/config.json`, use it 
and set source = "cache".
If cache is missing or older than the configured age, set source = "cache", output an empty recommendations array, and populate `error_message` with: 
"Cache is stale or missing. Live registry fetch is not supported in v1.0. 
Populate `.agents/orchestration/mcp_cache.json` manually with known MCP packages and re-run this skill."
Do not attempt any network fetch. The `.agents/.allow_network` flag and `mcp_registry_url` in config.json are reserved for v1.1.

The cache format:
{
  "last_updated": "2025-01-01T00:00:00Z",
  "entries": {
    "database": [{"package": "...", "args": [...], "env_requirements": [...], "reasoning": "..."}],
    "observability": [...],
    "testing": [...],
    "deployment": [...]
  }
}
From the cache data, select recommendations that match the project's detected gaps (e.g., if project uses a database but no MCP for that DB is present in the cache, recommend it).

Write output to `.agents/orchestration/mcp_recommendations.json` using the schema in CONTRACTS. The output must always include a `source` field set to "cache" and an `error_message` field (empty string if cache was valid and used, populated if cache was missing or stale).
Run validator. If validation fails, halt.

CONTRACTS:

Input: .agents/orchestration/skill_requirements.json

Output: .agents/orchestration/mcp_recommendations.json with schema mcp_recommendations.schema.json. Note: source is required.

Validator: validate_json.sh mcp_recommendations.json mcp_recommendations.schema.json

Starter file: create .agents/orchestration/mcp_cache.json with this content on first install:

json
{
  "last_updated": "1970-01-01T00:00:00Z",
  "entries": {}
}
The epoch timestamp ensures the cache is always treated as stale on first run, triggering the error_message fallback in v1.0. Populate this file manually with known MCP packages before running this skill.

ADAPTER HINTS:
```html
<!--
UNLISTED PLATFORM PROTOCOL:
1. Read POLICY. This is your source of truth.
2. Read CONTRACTS. Your output must match exactly.
3. Map required actions to your native tools:
   - read files
   - write JSON
   - run validation command
4. Do NOT edit this SKILL.md directly.
5. Write your adapter proposal to: .agents/adapters/staging/<your-platform>.adapter.md
6. Present proposal to human. Wait for explicit approval.
7. After approval, append your block (starting with "### <platform>") to this ADAPTER HINTS section.
8. Never delete, edit, or reorder existing content. Only append.
-->
```

### gemini-cli
- Read a file: `read_file`
- Write JSON output: `write_file`
- Run a validator script: `run_shell_command` — capture exit code; non-zero means halt

### claude
- Read a file: `bash_tool` with `cat`
- Write JSON output: `create_file` or `str_replace`
- Run a validator script: `bash_tool` — capture exit code; non-zero means halt

### cursor
- Read a file: open in editor context
- Write JSON output: create or edit file via editor
- Run a validator script: integrated terminal — non-zero exit means halt

FAILURE STATES:

Cache missing or stale → output empty recommendations array, set source = "cache", populate error_message with the standard v1.0 message. Do not halt — this is a recoverable state.

SAFETY RULES:

Never attempt any network fetch in v1.0. Live registry fetch is deferred to v1.1 pending registry API documentation.

The `.allow_network` flag file and `mcp_registry_url` config value are reserved for future use. Do not read or act on them in this version

HUMAN OVERRIDE RULES: None — cache is read-only in v1.0. If the cache is missing or stale, the skill outputs an error_message and empty recommendations. The human must manually populate `mcp_cache.json` with known MCP packages.

VERSIONING: 1.0.0.
Note: Live network fetch deferred to v1.1. Cache must be populated manually 
in this version.

7.3 skill-architect (in skills/meta/skill-architect/SKILL.md)
POLICY:

text
Read `.agents/orchestration/skill_requirements.json` and `.agents/orchestration/mcp_recommendations.json`.
Read all files in `.agents/core/runtime-profiles/`. When generating ADAPTER HINTS for any new skill file, use the `tool_mappings` object in each profile to populate the per-runtime tool mappings in the ADAPTER HINTS section — one subsection per runtime profile found. Only include the actions the generated skill actually uses.
Read `.agents/orchestration/skill_registry.json`.
For each required skill, assign capability tags based on its justification and domain (e.g., ["security", "php"] for a PHP security skill, ["database", "postgres"] for a Postgres query skill).
If no skills are approved for generation, write capability_tags as an empty array [] in skill_plan.json.
Before generating, check if any existing skill in the registry shares 2 or more tags with the new skill. If so, add a warning to skill_plan.json under the warnings field and present the overlap to the human before proceeding. Do not block generation — only warn and require acknowledgement.
Before writing any file, select the permission file based on the target path:
- For writes to `.agents/skills/generated/`: read `.agents/core/permissions/generated-skills.json`
- For writes to `.agents/orchestration/`: read `.agents/core/permissions/meta-skills.json`
Confirm the target path falls under `may_write_to` in the relevant file. If the 
path appears in `may_NOT_write_to` or is not listed in `may_write_to`, halt 
immediately and report a permission violation. Do not proceed until the violation 
is resolved by human intervention.
Generate a new `SKILL.md` file for each approved skill using the template at 
`.agents/core/skill-template.md`. Place each in `.agents/skills/generated/<skill_name>/SKILL.md`.
Do NOT overwrite an existing skill file unless the human explicitly approves.
Never write to `.agents/skills/meta/`.
After generation, update skill_registry.json: add each new skill with its name, 
path, tags, and current timestamp. Update last_updated.
CONTRACTS:

Inputs: two JSON files

Output: .agents/orchestration/skill_plan.json using skill_plan.schema.json

Validator: validate_json.sh skill_plan.json skill_plan.schema.json

Starter file: create .agents/orchestration/skill_registry.json with this content on first install:

json
{
  "last_updated": "1970-01-01T00:00:00Z",
  "skills": []
}
Each entry added by skill-architect will follow this shape:

json
{
  "skill_name": "example-skill",
  "path": "skills/generated/example-skill/SKILL.md",
  "tags": ["tag1", "tag2"],
  "created": "2025-01-01T00:00:00Z"
}

ADAPTER HINTS:
```html
<!--
UNLISTED PLATFORM PROTOCOL:
1. Read POLICY. This is your source of truth.
2. Read CONTRACTS. Your output must match exactly.
3. Map required actions to your native tools:
   - read files
   - write JSON
   - ask human (for overlap acknowledgement and conflict resolution)
   - run validation command
4. Do NOT edit this SKILL.md directly.
5. Write your adapter proposal to: .agents/adapters/staging/<your-platform>.adapter.md
6. Present proposal to human. Wait for explicit approval.
7. After approval, append your block (starting with "### <platform>") to this ADAPTER HINTS section.
8. Never delete, edit, or reorder existing content. Only append.
-->
```

### gemini-cli
- List directory contents: `glob` (use to enumerate `.agents/core/runtime-profiles/`)
- Read a file: `read_file`
- Write JSON output: `write_file`
- Ask human for overlap acknowledgement or conflict resolution: `ask_user` — extract selection from response payload; do not proceed without explicit reply
- Run a validator script: `run_shell_command` — capture exit code; non-zero means halt

### claude
- List directory contents: `bash_tool` with `ls`
- Read a file: `bash_tool` with `cat`
- Write JSON output: `create_file` or `str_replace`
- Ask human for overlap acknowledgement or conflict resolution: conversational turn — state the conflict clearly, wait for explicit reply; do not assume a default
- Run a validator script: `bash_tool` — capture exit code; non-zero means halt

### cursor
- List directory contents: integrated file explorer or terminal `find`
- Read a file: open in editor context
- Write JSON output: create or edit file via editor
- Ask human for overlap acknowledgement or conflict resolution: inline editor prompt — wait for explicit confirmation before proceeding
- Run a validator script: integrated terminal — non-zero exit means halt

FAILURE STATES:

Template missing → cannot proceed; set status = ERROR_HALTED.

Skill name collision → request human override (see below).

SAFETY RULES:

Never generate a skill that duplicates an existing skill’s name without approval.

Never write SKILL.md files outside .agents/skills/generated/.

Never read from or write to .agents/skills/meta/.

HUMAN OVERRIDE RULES:

If a skill already exists, the agent must present the conflict to the human and wait for a response: “Overwrite, skip, or rename?” Only after explicit approval can overwrite occur.

VERSIONING: 1.0.0.

7.4 design-system-planner (in skills/meta/design-system-planner/SKILL.md)
POLICY:

text
Read `.agents/orchestration/skill_requirements.json`. Extract `project_type` and any `justification` fields.
If `project_type` indicates a web app or mobile app, assume general public audience unless the justification specifies otherwise.
Do NOT browse the web or make external API calls unless explicitly authorized by the user via a confirmation prompt (see HUMAN OVERRIDE RULES).
Generate three distinct design options. Each option must differ in at least two of: typography (font family, scale), spacing (density, rhythm), color harmony (palette, contrast), layout grid (columns, breakpoints). For each option, write a one‑sentence UX rationale.
Present the options to the human using the runtime’s native ask‑human tool (see ADAPTER HINTS). Wait for a selection. The selection must be one of the three options (e.g., “Option A”).
After receiving the selection, write a JSON file to `.agents/orchestration/design_tokens.json`. The file must include `human_approved_choice`, `typography`, `spacing`, and `colors`, all populated based on the chosen option. All four fields are required by both the schema and the validator.
Run the design‑tokens validator (`validate_design_tokens.sh`). Then run `validate_json.sh design_tokens.json design_tokens.schema.json`. If either exits non‑zero, halt and report error.
CONTRACTS:

Input: .agents/orchestration/skill_requirements.json

Output: .agents/orchestration/design_tokens.json with schema design_tokens.schema.json

Validators (run both, in order):

validate_design_tokens.sh design_tokens.json
validate_json.sh design_tokens.json design_tokens.schema.json
Both must exit 0. If either fails, halt.
ADAPTER HINTS:
```html
<!--
UNLISTED PLATFORM PROTOCOL:
1. Read POLICY. This is your source of truth.
2. Read CONTRACTS. Your output must match exactly.
3. Map required actions to your native tools:
   - read files
   - write JSON
   - ask human (mandatory — present options and wait for selection)
   - run validation command
   - network fetch (conditional — only if human authorizes)
4. Do NOT edit this SKILL.md directly.
5. Write your adapter proposal to: .agents/adapters/staging/<your-platform>.adapter.md
6. Present proposal to human. Wait for explicit approval.
7. After approval, append your block (starting with "### <platform>") to this ADAPTER HINTS section.
8. Never delete, edit, or reorder existing content. Only append.
-->
```

### gemini-cli
- Read a file: `read_file`
- Write JSON output: `write_file`
- Ask human for design selection: `ask_user` — present all three options, extract selection from response payload; never proceed without explicit selection
- Run a validator script: `run_shell_command` — capture exit code; non-zero means halt
- Network fetch (conditional — only if human explicitly authorizes via confirmation prompt): `run_shell_command` with `curl`

### claude
- Read a file: `bash_tool` with `cat`
- Write JSON output: `create_file` or `str_replace`
- Ask human for design selection: conversational turn — present all three options clearly, wait for explicit reply; do not assume a default; do not proceed without selection
- Run a validator script: `bash_tool` — capture exit code; non-zero means halt
- Network fetch (conditional — only if human explicitly authorizes via confirmation prompt): `bash_tool` with `curl`

### cursor
- Read a file: open in editor context
- Write JSON output: create or edit file via editor
- Ask human for design selection: inline editor prompt — present all three options, wait for confirmation before generating any diff
- Run a validator script: integrated terminal — non-zero exit means halt
- Network fetch (conditional — only if human explicitly authorizes via confirmation prompt): terminal `curl`

FAILURE STATES:

No human response after timeout (runtime‑dependent) → abort and set phase to ERROR_HALTED.

User rejects all options → ask to re‑generate (allowed once), then abort if still rejected.

SAFETY RULES:

Never finalize without an explicit human selection.

Never use default values for human_approved_choice.

HUMAN OVERRIDE RULES:

Mandatory for the final selection.

Also mandatory for enabling web browsing (if any).

VERSIONING: 1.0.0.

7a. Permission Files (Place in .agents/core/permissions/)
meta-skills.json
json
{
  "skill_type": "meta",
  "description": "The four system meta-skills. Read broadly, write only to orchestration.",
  "may_write_to": [
    ".agents/orchestration/",
    ".agents/skills/generated/"
  ],
  "may_read_from": [
    ".agents/orchestration/",
    ".agents/core/",
    ".agents/skills/meta/"
  ],
  "may_NOT_write_to": [
    ".agents/skills/meta/",
    ".agents/core/validators/",
    ".agents/core/contracts/",
    ".agents/adapters/"
  ]
}
generated-skills.json
json
{
  "skill_type": "generated",
  "description": "Project-specific skills created by skill-architect.",
  "may_write_to": [
    ".agents/skills/generated/"
  ],
  "may_read_from": [
    ".agents/orchestration/",
    ".agents/core/skill-template.md"
  ],
  "may_NOT_write_to": [
    ".agents/skills/meta/",
    ".agents/core/",
    ".agents/adapters/",
    ".agents/orchestration/"
  ]
}
adapters.json
json
{
  "skill_type": "adapter",
  "description": "Runtime-specific adapter files. May only propose to staging.",
  "may_write_to": [
    ".agents/adapters/staging/"
  ],
  "may_read_from": [
    ".agents/core/",
    ".agents/orchestration/phase.json"
  ],
  "may_NOT_write_to": [
    ".agents/skills/",
    ".agents/core/validators/",
    ".agents/core/contracts/"
  ]
}
validators.json
json
{
  "skill_type": "validator",
  "description": "Validation scripts. Never modified by any skill or agent action. Human edits only.",
  "may_write_to": [],
  "may_read_from": [
    ".agents/orchestration/",
    ".agents/core/contracts/"
  ],
  "may_NOT_write_to": [
    ".agents/"
  ],
  "note": "Validators are protected artifacts. Any modification requires direct human action outside the agent workflow."
}
8. Self‑Extension Protocol & ADAPTER HINTS Mappings
Copy this HTML comment into the `ADAPTER HINTS` section of every `SKILL.md` in `skills/meta/`, adapting the action list in step 3 to include only the actions that skill actually uses:
	
html
<!--
UNLISTED PLATFORM PROTOCOL:
1. Read POLICY. This is your source of truth.
2. Read CONTRACTS. Your output must match exactly.
3. Map required actions to your native tools:
   - read files
   - write JSON
   - ask human (or conversational turn)
   - run validation command
4. Do NOT edit this SKILL.md directly.
5. Write your adapter proposal to: .agents/adapters/staging/<your-platform>.adapter.md
6. Present proposal to human. Wait for explicit approval.
7. After approval, append your block (starting with "### <platform>") to this ADAPTER HINTS section.
8. Never delete, edit, or reorder existing content. Only append.
-->
After the HTML comment, add one subsection per known runtime. The subsection maps each generic action in that skill's POLICY to the runtime's native tools. Use this standard mapping:

gemini-cli
List directory / find files: glob

Read a file: read_file

Write JSON output: write_file

Ask human for input or selection: ask_user — extract selection from response payload

Run a validator script: run_shell_command — capture exit code; non-zero means halt

Network fetch (if skill permits): run_shell_command with curl

claude
List directory / find files: bash_tool with find or ls

Read a file: bash_tool with cat

Write JSON output: create_file or str_replace

Ask human for input or selection: conversational turn — state options clearly, wait for explicit reply before proceeding; do not assume a default

Run a validator script: bash_tool — capture exit code; non-zero means halt

Network fetch (if skill permits): bash_tool with curl

cursor
List directory / find files: integrated file explorer or terminal find

Read a file: open in editor context

Write JSON output: create or edit file via editor

Ask human for input or selection: inline editor prompt — wait for confirmation before generating any diff

Run a validator script: integrated terminal — non-zero exit means halt

Network fetch (if skill permits): terminal curl

For each skill, add only the actions that skill actually uses. Do not copy unused mappings. Example: project-skill-discovery never asks the human, so omit the human‑input line from its ADAPTER HINTS.

9. The Skill Template (core/skill-template.md)
Create a file with the seven section headers and placeholder text. Example:

markdown
## POLICY
[Describe what this skill must do – no tool names]

## CONTRACTS
[Input/output schemas, file paths]

## ADAPTER HINTS
<!-- Self‑extension protocol comment (from Section 8) -->
[Per‑runtime tool mappings, using only actions needed by this skill]

## FAILURE STATES
[What fails, how to recover]

## SAFETY RULES
[Forbidden actions, anti‑hallucination]

## HUMAN OVERRIDE RULES
[Where approval is mandatory]

## VERSIONING
[Version, compatibility]
10. Runtime Adapters (Executable Files and Documentation)
10.1 adapters/gemini-cli/ADAPTER.md (documentation reference)
markdown
# Adapter for Gemini CLI

Mapping generic actions to Gemini tools:

- List files: `glob`
- Read file: `read_file`
- Write JSON: `write_file`
- Ask human with structured options: `ask_user` – the response payload contains a selection ID.
- Run validation command: `run_shell_command` – capture exit code.
- State persistence: read/write `.agents/orchestration/phase.json` using `read_file` and `write_file`.

The agent must check `phase.json` at the start of each session and before executing any coding task.
10.2 adapters/gemini-cli/GEMINI.md (executable adapter – copy to project root)
markdown
# Gemini CLI Orchestration Adapter
<!-- adapter-version: 1.0.0 -->
<!-- last-validated: <fill in date when run> -->
<!-- conformance-test: adapters/gemini-cli/conformance-test.md -->

At the start of every session, read `.agents/orchestration/phase.json`.
Check `current_phase` and `status`.
If `status` is `ERROR_HALTED`, stop and inform the user before doing anything else.
If `current_phase` is not `PHASE_4_CODE`, do not write any implementation code.
Follow the phase transition rules in `.agents/core/global-policy.md`.

## Tool Mappings
- List files: `glob`
- Read file: `read_file`
- Write JSON: `write_file`
- Ask human: `ask_user`
- Run validator: `run_shell_command` — capture exit code; treat non-zero as failure.

## Activating Skills
Before beginning any phase task, load the relevant skill file so its POLICY
section is in context.

**Primary method** (if your Gemini CLI version supports it):
`activate_skill .agents/skills/meta/<skill-name>/SKILL.md`
`activate_skill .agents/skills/generated/<skill-name>/SKILL.md`

**Fallback method** (always works):
Use `read_file` on the SKILL.md path. Treat the POLICY section as binding
instructions for the duration of that task. Consult the ADAPTER HINTS section
for Gemini-specific tool mappings. Do not begin any phase task without first
reading the relevant skill file by one of these two methods.

To verify which method your runtime supports: attempt `activate_skill` on any
SKILL.md path. If the command is not recognized, use `read_file` for all
subsequent skill loading. Note which method works and use it consistently for
the entire session.

10.3 adapters/claude/CLAUDE.md (executable adapter – copy to project root)
markdown
# Claude Orchestration Adapter
<!-- adapter-version: 1.0.0 -->
<!-- last-validated: <fill in date when run> -->
<!-- conformance-test: adapters/claude/conformance-test.md -->

At the start of every session, read `.agents/orchestration/phase.json`.
Check `current_phase` and `status`.
If `status` is `ERROR_HALTED`, stop and inform the user before doing anything else.
If `current_phase` is not `PHASE_4_CODE`, do not write any implementation code.
Follow the phase transition rules in `.agents/core/global-policy.md`.

## Tool Mappings
- Read file: `bash_tool` with `cat`
- Write JSON: `create_file` or `str_replace`
- Ask human: conversational turn — ask explicitly and wait for reply before proceeding
- Run validator: `bash_tool` — capture exit code; treat non-zero as failure.

## Activating Skills
To use a skill: read the SKILL.md at the relevant path using `bash_tool` with `cat`.
Treat the POLICY section as binding instructions for the duration of that task.
Consult the ADAPTER HINTS section for Claude-specific tool mappings.
Do not begin a phase task without first reading the relevant skill file.
10.4 adapters/cursor/.cursorrules (executable adapter – copy to project root)
text
# Cursor Orchestration Adapter
# adapter-version: 1.0.0
# last-validated: <fill in date when run>
# conformance-test: adapters/cursor/conformance-test.md

# At session start, read .agents/orchestration/phase.json.
# If status is ERROR_HALTED, stop and notify user.
# If current_phase is not PHASE_4_CODE, do not write implementation code.
# Human input: use inline editor prompt; wait for confirmation before generating any diff.
# Validation: run validator scripts via terminal; treat non-zero exit as failure.

# Activating Skills
# To use a meta-skill: open .agents/skills/meta/<skill-name>/SKILL.md
# To use a generated skill: open .agents/skills/generated/<skill-name>/SKILL.md
# Read POLICY section first. Follow its instructions exactly.
# Consult ADAPTER HINTS for Cursor-specific tool mappings.
# Always read the relevant skill file before beginning any phase task.
10.5 Conformance test files
Create adapters/gemini-cli/conformance-test.md:

markdown
# Conformance Test: gemini-cli Adapter
<!-- adapter-version: 1.0.0 -->
<!-- last-validated: <fill in date when run> -->

Run this checklist manually whenever the adapter file is modified.
All boxes must be checked before the adapter is considered conformant.

## Phase Enforcement
- [ ] Agent reads `.agents/orchestration/phase.json` at session start
- [ ] Agent halts and notifies user if `status` is `ERROR_HALTED`
- [ ] Agent refuses to write implementation code if `current_phase` is not `PHASE_4_CODE`

## Output Contract Compliance
- [ ] `skill_requirements.json` passes `validate_json.sh` against its schema
- [ ] `mcp_recommendations.json` passes `validate_json.sh` against its schema
- [ ] `skill_plan.json` passes `validate_json.sh` against its schema
- [ ] `design_tokens.json` passes `validate_design_tokens.sh` and `validate_json.sh` against its schema

## Human Approval Gate
- [ ] `design-system-planner` halts before writing `design_tokens.json`
- [ ] `human_approved_choice` is never written without explicit human input
- [ ] Overwrite confirmation is requested before overwriting any existing generated skill

## Validation Enforcement
- [ ] Validator exit code 1 halts the current phase
- [ ] `retry_count` increments on each failed validation
- [ ] `ERROR_HALTED` is set when `retry_count >= max_retries`

## Version History
| Version | Date | Change |
|---------|------|--------|
| 1.0.0 | <date> | Initial |
Copy the same file to adapters/claude/conformance-test.md and adapters/cursor/conformance-test.md (changing only the title line to “claude adapter” and “cursor adapter” respectively).

11. Documentation (core/docs/README.md)
Create this README explaining the architecture and installation:

markdown
# Portable Meta‑Skill System

## Design Rationale
This system separates **policy** (what to do) from **mechanism** (how a specific runtime does it). Every skill’s `POLICY` section uses only generic verbs: read, write, ask, validate. Runtime‑specific tool names (e.g., `ask_user`, `bash_tool`) go in `ADAPTER HINTS`.

This allows the same skill to work on Gemini CLI, Claude, Cursor, and others – each runtime provides its own adapter.

## Installation
1. Copy the entire `.agents/` folder to your project root (or `~/.agents/` for global use).
2. Ensure the validator dependencies are installed: Python with `jsonschema` (`pip install jsonschema`) OR `jq`.
3. The `.agents/.allow_network` flag file is reserved for v1.1 (live MCP registry fetch). Do not create it in v1.0 — no skill uses it yet. All v1.0 skills run in cache-only mode by default.
4. For Gemini CLI: copy `adapters/gemini-cli/GEMINI.md` to your project root.
5. For Claude: copy `adapters/claude/CLAUDE.md` to your project root.
6. For Cursor: copy `adapters/cursor/.cursorrules` to your project root.
   These files tell the runtime to check phase.json at session start and enforce phase rules.
Note: treat the root copy of each adapter file as the live file. 
The adapters/ copy is the installation source. When an adapter is 
updated, manually replace the root copy and re-run its conformance test.

## Usage
- Start a session. The global policy (loaded via adapter) will read `.agents/orchestration/phase.json` to determine the current phase.
- The four meta‑skills are invoked in order: DISCOVERY → ARCHITECT → DESIGN → CODE.
- Never skip phases. Each phase’s output is validated before proceeding.
- If a phase fails three times, the system halts and requires human intervention.

## Resetting the Pipeline
If the system reaches `ERROR_HALTED` and you need to restart, manually edit `.agents/orchestration/phase.json` to:
```json
{
  "current_phase": "PHASE_1_DISCOVERY",
  "status": "PENDING",
  "last_error": null,
  "retry_count": 0,
  "max_retries": 3
}
Then delete all output files in .agents/orchestration/ except these three, which are safe to preserve:

phase.json

mcp_cache.json

skill_registry.json

To restart from a specific mid-pipeline phase instead of the beginning, set current_phase to the desired phase value (PHASE_2_ARCHITECT, PHASE_3_DESIGN, or PHASE_4_CODE) and status to "PENDING". Only do this if the prerequisite phase outputs still exist and are valid.

Runtime Profiles
Files in core/runtime-profiles/ describe each supported runtime's capabilities (file write support, shell execution, human input mechanism, etc.). They are consumed by skill-architect when generating ADAPTER HINTS for new skills. They are also a human-readable reference for understanding platform differences. To add support for a new runtime, add its profile JSON here before running skill-architect.

Adding a New Runtime
If your runtime is not listed, follow the self‑extension protocol in any skill’s ADAPTER HINTS. It will guide you to create a staging proposal and, after human approval, append your adapter to the skill file.

```

---

## 12. Test Procedure (To Be Performed After Building)

Before declaring completion, run these tests:

1. **Validator correctness:**
   - Create a valid `skill_requirements.json` – run `validate_json.sh` – must exit 0.
   - Create an invalid one (missing required field) – must exit 1.
   - Test fallback: uninstall Python `jsonschema` (or use a machine without it) – must still detect missing fields using jq.

2. **Retry limit behavior:**
   - Set `phase.json` with `retry_count = 3`, `max_retries = 3`, `status = "PARTIAL_RECOVERY"`. Simulate a validation failure. The agent must **not** retry; it must set status to `ERROR_HALTED` and inform the human.

3. **MCP cache fallback:**
   - Rename `mcp_cache.json` to `mcp_cache.json.bak` temporarily.
   - Run `validate_json.sh` against a manually constructed `mcp_recommendations.json` that has `source: "cache"`, an empty `recommended_mcps` array, and a non-empty `error_message`. Must exit 0.
   - Run `validate_json.sh` against a version missing the `source` field. Must exit 1.
   - Restore `mcp_cache.json.bak` to `mcp_cache.json`.
   - Confirm `mcp-plugin-discovery` POLICY does not contain the words "fetch", "curl", or "network" as action verbs (they may appear in comments or notes but not as instructions): 
 grep -n "run_shell_command\|curl" \
    .agents/skills/meta/mcp-plugin-discovery/SKILL.md
     Must return 0 results from the POLICY section. Use the awk extraction method from validate_policy.sh to isolate the POLICY section first.

4. **Design system concrete flow:**
   - Verify that `design-system-planner` SKILL.md requires human input before 
  writing `design_tokens.json`. Run:
  
  grep -n "Wait for a selection\|halt\|wait for" \
    .agents/skills/meta/design-system-planner/SKILL.md
  
  The command must return at least one result. Confirm that the matching line 
  appears in the POLICY section and occurs before any line that references 
  writing `design_tokens.json`. If no result is returned, the POLICY section 
  is missing the human gate — fix it before proceeding.

      - Verify that the POLICY section requires all four fields in the output. Run:
  
  grep -c "human_approved_choice\|typography\|spacing\|colors" \
    .agents/skills/meta/design-system-planner/SKILL.md
  
  Must return 4 or higher. If lower, the POLICY section is incomplete.
   - After writing `design_tokens.json`, both validators must pass. If either fails, the phase must not advance.
   - Create a design_tokens.json with valid `human_approved_choice` but missing `typography`. Run `validate_design_tokens.sh` — must exit 0. Run `validate_json.sh` against its schema — must exit 1. Confirm the phase does not advance.

5. **Self‑extension protocol simulation:**
   - Create a fake runtime “test-runtime”. Have the agent follow the protocol. The staging file must contain a `### test-runtime` header; after merging, the `ADAPTER HINTS` section must contain that header as the final entry; all content preceding the new adapter block must be semantically identical — no lines removed, reordered, or rephrased.

6. **Policy lint:**
   - Run `validate_policy.sh` against `skills/meta/`. Must exit 0.
   - Temporarily add `ask_user` to any POLICY section. Run again — must exit 1 and name the exact file and term.
   - Remove the term. Run again — must exit 0.
   - Confirm the script also catches terms added to skills in `skills/generated/`.

7. **Adapter conformance:**
   - Open `adapters/gemini-cli/conformance-test.md`. Walk through every checkbox. All must pass.
   - Repeat for `adapters/claude/conformance-test.md` and `adapters/cursor/conformance-test.md`.
   - Rule: any time an adapter file is modified in the future, its conformance test must be re-run and the `last-validated` date updated before the change is accepted.

8. **Skill overlap detection:**
   - Manually add an entry to `skill_registry.json` with tags `["security", "php"]`.
   - Run skill-architect with a new skill whose justification would produce the same tags.
   - The agent must add a warning to `skill_plan.json` and present the overlap before generating.
   - After generation, confirm `skill_registry.json` has been updated with the new skill's entry and a current timestamp.

9. **Permission enforcement:**
   - Instruct skill-architect to write a skill to `.agents/skills/meta/`. It must refuse and report a permission violation without writing anything.
   - Instruct it to write to `.agents/core/validators/`. It must refuse.
   - Confirm a valid write to `.agents/skills/generated/` succeeds normally.
   - Confirm skill-architect can write to `.agents/orchestration/` without permission errors.

10. **global-policy.md structural verification:**
    - Open `.agents/core/global-policy.md` and confirm the following are
      present exactly as specified:
      - `## State File` heading exists
      - A ```json code fence immediately follows `## State File`
      - The JSON block contains all five fields: `current_phase`, `status`,
        `last_error`, `retry_count`, `max_retries`
      - The closing ``` fence exists before the next heading
      - `## Transition Rules` heading exists with `-` bullet points beneath it
      - `## Global Directives` heading exists with `-` bullet points beneath it
    - Run this verification automatically using bash:
```bash
      grep -c "^## State File" .agents/core/global-policy.md | grep -q "^1$" \
        && grep -c "^## Transition Rules" .agents/core/global-policy.md | grep -q "^1$" \
        && grep -c "^## Global Directives" .agents/core/global-policy.md | grep -q "^1$" \
        && grep -c '^```json' .agents/core/global-policy.md | grep -q "^1$" \
        && echo "global-policy.md structure OK" \
        || echo "ERROR: global-policy.md structure is malformed" >&2
```
    - Must print `global-policy.md structure OK`. Any other output is a
      failure. Fix the file before proceeding.

All tests must pass. If any fails, fix the corresponding file before final output.

---

## 13. Final Checklist (Execute Before Delivery)

- [ ] All directories from Section 1 exist.
- [ ] Runtime profiles exist for gemini‑cli, claude, cursor.
- [ ] `validate_json.sh` and `validate_design_tokens.sh` are written, executable, and secure (environment variables, no injection).
- [ ] `validate_policy.sh` and `policy-blocklist.txt` exist in `core/validators/` and `validate_policy.sh` is executable.
- [ ] Running `validate_policy.sh` against `skills/` exits 0 with no violations.
- [ ] All four JSON schemas are in `core/contracts/`.
- [ ] Each of the four meta‑skills has a complete `SKILL.md` in `skills/meta/` with all 7 sections, and the `ADAPTER HINTS` section contains the self‑extension comment and per‑runtime tool mappings.
- [ ] No `POLICY` section contains any runtime‑specific tool names (no `ask_user`, `run_shell_command`, `glob`, `bash_tool`).
- [ ] `global-policy.md` includes the retry limit rules and phase definitions.
- [ ] Test 10 (global-policy.md structural verification) passes — all required headings present, JSON block fenced, bullets on Transition Rules and Global Directives.
- [ ] `phase.json` template exists in `orchestration/` with `retry_count` and `max_retries`.
- [ ] `mcp-plugin-discovery` POLICY uses cache-only logic (no network fetch). `source` is required in schema and always set to "cache" in v1.0. `error_message` is present in schema and populated when cache is missing or stale.
- [ ] `mcp-plugin-discovery` POLICY does not contain network fetch instructions. `mcp_registry_url` and `.allow_network` are referenced only in config and reserved for v1.1.
- [ ] `mcp-plugin-discovery` POLICY includes cross‑check against `skill_registry.json` to avoid recommending tooling already covered by skills.
- [ ] `mcp_cache.json` starter file exists in `orchestration/` with `last_updated` set to epoch timestamp.
- [ ] `design-system-planner` POLICY does not contain the word “research” as a vague action; it says exactly what to read and how to generate options.
- [ ] `skill-template.md` exists and has 7 sections (no standalone `## SELF-EXTENSION PROTOCOL`).
- [ ] `README.md` in `core/docs/` explains the design rationale and installation, including the `.allow_network` flag.
- [ ] The adapter files `GEMINI.md`, `CLAUDE.md`, and `.cursorrules` are present in their respective `adapters/` subdirectories and include version headers.
- [ ] GEMINI.md `Activating Skills` section includes both `activate_skill` primary method and `read_file` fallback method, with instructions for determining which to use.
- [ ] `conformance-test.md` exists in each of `adapters/gemini-cli/`, `adapters/claude/`, and `adapters/cursor/`.
- [ ] All conformance test checkboxes pass for all three adapters.
- [ ] The directory structure shows `.allow_network` at the root of `.agents/`.
- [ ] `skill_registry.json` starter file exists in `orchestration/` with an empty `skills` array.
- [ ] skill-architect POLICY includes overlap detection against the registry and registry update logic, as well as permission enforcement (and the permission check occurs **before** generation, with path‑based selection).
- [ ] `core/permissions/` directory exists with all four permission files: `meta-skills.json`, `generated-skills.json`, `adapters.json`, `validators.json`.
- [ ] Each permission file correctly separates `may_write_to` from `may_NOT_write_to` for its skill type.
- [ ] `meta-skills.json` includes `.agents/skills/generated/` in `may_write_to`.
- [ ] Test procedure (Section 12) passes completely.
- [ ] `core/config.json` exists with `mcp_registry_url` and `mcp_cache_max_age_days` defined.
- [ ] No root-level `.agents/staging/` directory exists. The only staging directory is `.agents/adapters/staging/`.
- [ ] All three adapter files (`GEMINI.md`, `CLAUDE.md`, `.cursorrules`) contain an `Activating Skills` section.
- [ ] `skill_plan.schema.json` has `capability_tags` in its `required` array.
- [ ] `design_tokens.schema.json` has `typography`, `spacing`, and `colors` in its `required` array.
- [ ] `skill_plan.schema.json` `next_phase` enum includes `"PHASE_ERROR"`.
- [ ] `core/docs/README.md` contains a `## Resetting the Pipeline` section.
- [ ] `core/docs/README.md` contains a `## Runtime Profiles` section explaining that `skill-architect` reads them.
- [ ] `global-policy.md` `## Global Directives` includes the permission file check before any write operation.
- [ ] `mcp-plugin-discovery` POLICY references `core/config.json` for registry URL and cache age, not hardcoded values.
- [ ] `design-system-planner` POLICY requires `typography`, `spacing`, and `colors` in the output and runs both validators.

---

## 14. Reference — Completed Example SKILL.md

This is the fully populated `SKILL.md` for `project-skill-discovery`. It shows exactly what a finished skill file looks like with all seven sections populated. Use it as the reference output to verify that Section 7 files are built correctly.

**File path:** `.agents/skills/meta/project-skill-discovery/SKILL.md`

```markdown
## POLICY
Read the project root directory. Use file listing tools to map directory structure.
Read dependency manifests: package.json, composer.json, go.mod, requirements.txt,
Cargo.toml, build.gradle, etc. (as many as exist).
Do NOT read every file. Instead, after reading manifests, select 2–3 representative
files from each major domain (e.g., one controller, one model, one service, one test
file) and read them to infer actual architecture patterns (e.g., ORM vs raw SQL,
framework usage).
Output a JSON file (see CONTRACTS) to `.agents/orchestration/skill_requirements.json`.
Then run `../core/validators/validate_json.sh` with the output and the schema.
If exit code ≠ 0, halt and report error.

## CONTRACTS
- Input: none (implicit – current working directory)
- Output: `.agents/orchestration/skill_requirements.json`
  Schema: `.agents/core/contracts/skill_requirements.schema.json`
- Validator: `validate_json.sh skill_requirements.json skill_requirements.schema.json`

## ADAPTER HINTS

<!--
UNLISTED PLATFORM PROTOCOL:
1. Read POLICY. This is your source of truth.
2. Read CONTRACTS. Your output must match exactly.
3. Map required actions to your native tools:
   - read files
   - write JSON
   - run validation command
4. Do NOT edit this SKILL.md directly.
5. Write your adapter proposal to: .agents/adapters/staging/<your-platform>.adapter.md
6. Present proposal to human. Wait for explicit approval.
7. After approval, append your block (starting with "### <platform>") to this ADAPTER HINTS section.
8. Never delete, edit, or reorder existing content. Only append.
-->

### gemini-cli
- List directory / find files: `glob`
- Read a file: `read_file`
- Write JSON output: `write_file`
- Run a validator script: `run_shell_command` — capture exit code; non-zero means halt

### claude
- List directory / find files: `bash_tool` with `find` or `ls`
- Read a file: `bash_tool` with `cat`
- Write JSON output: `create_file` or `str_replace`
- Run a validator script: `bash_tool` — capture exit code; non-zero means halt

### cursor
- List directory / find files: integrated file explorer or terminal `find`
- Read a file: open in editor context
- Write JSON output: create or edit file via editor
- Run a validator script: integrated terminal — non-zero exit means halt

## FAILURE STATES
- No manifests found → set `project_type = "unknown"`, required_skills empty,
  but still write and validate the output file.
- Sampling fails (file read error) → log warning, continue with manifest-only
  inference. Do not halt.

## SAFETY RULES
- Never read more than 50 files total.
- Never follow symlinks outside the project root.
- Never execute any code found in the project.

## HUMAN OVERRIDE RULES
None required for this skill. It is fully automated.

## VERSIONING
Version: 1.0.0
Compatible with: Gemini CLI, Claude, Cursor
Last validated: <fill in date when built>
```

---

## Build Order (Execute Steps in This Exact Sequence)

Before executing, read the entire document once. Then build in this order:

1. Create all directories from Section 1
2. Create runtime profile JSON files (Section 2)
3. Create `core/config.json` (Section 2, Config block)
4. Write all four JSON schemas to `core/contracts/` (Section 4)
5. Write `validate_json.sh`, `validate_design_tokens.sh`, `validate_policy.sh`, and `policy-blocklist.txt` to `core/validators/` (Section 5)
  5a. After writing all four files in `core/validators/`, run the following 
    commands to make the scripts executable:
    chmod +x .agents/core/validators/validate_json.sh
    chmod +x .agents/core/validators/validate_design_tokens.sh
    chmod +x .agents/core/validators/validate_policy.sh
    
    Then verify by running:
    ls -la .agents/core/validators/*.sh
    
    All three `.sh` files must show `-rwxr-xr-x` or equivalent executable 
    permission. If any file is not executable, re-run the chmod command for 
    that file before proceeding to step 6.
6. Write `global-policy.md` to `core/` (Section 6.1)
7. Write `phase.json` starter to `orchestration/` (Section 6.2)
8. Write all four permission files to `core/permissions/` (Section 7a)
9. Write `skill-template.md` to `core/` (Section 9)
9b. Before writing any SKILL.md file, re-read the following sections in full:
    - Section 7 (all four skill specifications)
    - Section 8 (ADAPTER HINTS mappings and self-extension protocol)
    - Section 14 (reference example SKILL.md)
    Do not rely on earlier context. Treat these three sections as the active specification for all four SKILL.md files. Write all four skills before moving to step 10.
10. Write the four meta-skill `SKILL.md` files to `skills/meta/` (Section 7) — all dependencies from steps 4–9 now exist
11. Write adapter documentation and executable files to `adapters/` (Section 10)
12. Write conformance test files to each adapter subdirectory (Section 10.5)
13. Write `README.md` to `core/docs/` (Section 11)
14. Write starter files: `orchestration/mcp_cache.json` (Section 7.2 CONTRACTS) and `orchestration/skill_registry.json` (Section 7.3 CONTRACTS)
15. Run the full test procedure (Section 12)
16. Complete the checklist (Section 13)
17. Confirm completion by running a full directory listing of `.agents/` and printing the result. Then compare every file and folder in the listing against the expected structure in Section 1. Report any file that is missing, misnamed, or placed in the wrong directory before considering the build complete.

**Do not begin step 10 before steps 4–9 are complete.** The SKILL.md files reference schemas, validators, permission files, and the template — all of which must exist first.

**Test failure recovery rule:**

If a test fails:
1. Read the error output carefully. Identify the exact file and field that caused the failure.
2. If the fix is a direct correction of a value already specified in this document (wrong path, missing required field, incorrect JSON value): fix it autonomously, re-run the failing test once, and continue if it passes.
3. If the test fails again after one fix attempt: stop immediately. Report to the human with:
   - The exact test number and name
   - The exact error output
   - The fix you attempted
   - Why it did not work
   Do not attempt a second fix. Do not move to the next test. Wait for human input.
4. If the fix requires generating any content not specified in this document: 
   do not attempt it. Stop and report immediately.

One fix attempt per test failure. No exceptions.

---

## Final Instruction to Gemini

Execute the above steps in order. Write every file exactly as specified. Do not skip any section. Do not invent runtime‑specific tool names inside POLICY sections. Use the exact directory paths. After finishing, run the test procedure and confirm all tests pass. After all tests pass, confirm completion by running a full directory listing 
of `.agents/` and printing the result. Then compare every file and folder in the listing against the expected structure in Section 1. Report any file that is missing, misnamed, or placed in the wrong directory before considering the build complete.

If any ambiguity arises, ask for clarification before proceeding. This is a production‑grade system – precision matters.

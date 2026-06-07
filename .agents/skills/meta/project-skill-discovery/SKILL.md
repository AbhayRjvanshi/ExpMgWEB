## POLICY
Read the project root directory. Use file listing tools to map directory structure.
Read dependency manifests: package.json, composer.json, go.mod, requirements.txt,
Cargo.toml, build.gradle, etc. (as many as exist).
Do NOT read every file. Instead, after reading manifests, select 2–3 representative
files from each major domain (e.g., one controller, one model, one service, one test
file) and read them to infer actual architecture patterns (e.g., ORM vs raw SQL,
framework usage).
Output a JSON file (see CONTRACTS) to `.agents/orchestration/skill_requirements.json`.
Then run `python .agents/core/validators/validate_json.py .agents/orchestration/skill_requirements.json .agents/core/contracts/skill_requirements.schema.json`.
If exit code ≠ 0, halt and report error.

## CONTRACTS
- Input: none (implicit – current working directory)
- Output: `.agents/orchestration/skill_requirements.json`
  Schema: `.agents/core/contracts/skill_requirements.schema.json`
- Validator: `python .agents/core/validators/validate_json.py .agents/orchestration/skill_requirements.json .agents/core/contracts/skill_requirements.schema.json`

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
Last validated: 2026-06-07

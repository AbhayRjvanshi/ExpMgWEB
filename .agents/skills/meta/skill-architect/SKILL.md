## POLICY
Read `.agents/orchestration/skill_requirements.json` and `.agents/orchestration/mcp_recommendations.json`.
Read all files in `.agents/core/runtime-profiles/`. When generating ADAPTER HINTS for any new skill file, use the `tool_mappings` object in each profile to populate the per-runtime tool mappings in the ADAPTER HINTS section — one subsection per runtime profile found. Only include the actions the generated skill actually uses.
Read `.agents/orchestration/skill_registry.json`.
For each required skill, assign capability tags based on its justification and domain (e.g., ["security", "php"] for a PHP security skill, ["database", "postgres"] for a Postgres query skill).
If no skills are approved for generation, write capability_tags as an empty array [] in skill_plan.json.
Before generating, check if any existing skill in the registry shares 2 or more tags with the new skill. If so, add a warning to skill_plan.json under the warnings field and present the overlap to the human before proceeding. Do not block generation — only warn and require acknowledgement.
Before writing any file, select the permission file based on the target path:
- For writes to `.agents/skills/generated/`: read `.agents/core/permissions/generated-skills.json`
- For writes to `.agents/orchestration/`: read `.agents/core/permissions/meta-skills.json`
- For writes to `.agents/skills/external/`: read `.agents/core/permissions/external-skills.json`
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

## CONTRACTS
Inputs: two JSON files
Output: .agents/orchestration/skill_plan.json using skill_plan.schema.json
Validator: `python .agents/core/validators/validate_json.py .agents/orchestration/skill_plan.json .agents/core/contracts/skill_plan.schema.json`
Starter file: create .agents/orchestration/skill_registry.json with this content on first install:
{
  "last_updated": "1970-01-01T00:00:00Z",
  "skills": []
}

## ADAPTER HINTS
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
- Run a validator script: `bash_tool` — capture exit code; non-zero means halt

### cursor
- List directory contents: integrated file explorer or terminal `find`
- Read a file: open in editor context
- Write JSON output: create or edit file via editor
- Ask human for overlap acknowledgement or conflict resolution: inline editor prompt — wait for explicit confirmation before proceeding
- Run a validator script: integrated terminal — non-zero exit means halt

## FAILURE STATES
- Template missing → cannot proceed; set status = ERROR_HALTED.
- Skill name collision → request human override (see below).

## SAFETY RULES
- Never generate a skill that duplicates an existing skill’s name without approval.
- Never write SKILL.md files outside .agents/skills/generated/.
- Never read from or write to .agents/skills/meta/.

## HUMAN OVERRIDE RULES
- If a skill already exists, the agent must present the conflict to the human and wait for a response: “Overwrite, skip, or rename?” Only after explicit approval can overwrite occur.

## VERSIONING
Version: 1.0.0
Compatible with: Gemini CLI, Claude, Cursor
Last validated: 2026-06-07

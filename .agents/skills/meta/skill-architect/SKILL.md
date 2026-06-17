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
Confirm the target path falls under `may_write_to` in the relevant file. If the path appears in `may_NOT_write_to` or is not listed in `may_write_to`, halt immediately and report a permission violation. Do not proceed until the violation is resolved by human intervention.

Generate a new `SKILL.md` file for each approved skill using the template at `.agents/core/skill-template.md`. Place each in `.agents/skills/generated/<skill_name>/SKILL.md`.
Do NOT overwrite an existing skill file unless the human explicitly approves.
Never write to `.agents/skills/meta/`.
After generation, update skill_registry.json: add each new skill with its name, path, tags, and current timestamp. Update last_updated.

### Snapshotting Baseline Policy
After generating/updating approved skills and updating `skill_registry.json`, the agent MUST run:
`python .agents/core/validators/generate_snapshot.py`
If a snapshot already exists, rerun with the `--regenerate` flag to update codebase statistics while preserving user justifications:
`python .agents/core/validators/generate_snapshot.py --regenerate`

### Layer 2 Human Confirmation Loop
Before proceeding, the agent must check for any skill or MCP justifications in `project_snapshot.json` where `confirmed_by_human` is `false`.
For each unconfirmed justification:
1. Present the discovery evidence and justification to the user.
2. Request confirmation: "Is this skill/MCP still required for the project? If yes, please provide a brief plain-language reason why."
3. If confirmed:
   - Set `confirmed_by_human = true`
   - Set `confirmed_at` to the current UTC timestamp (ISO 8601)
   - Save the user's response in `human_confirmed_reason`
4. If the user does not confirm or wishes to reject it, proceed to the Retirement Gate for that item.
5. Save the updated `project_snapshot.json`.

### Staleness Reviews
Whenever a prominence check completes, the agent must check if any active skill or MCP has a `prominence_verdict` of `LOW` or `MINIMAL` in `prominence_report.json`.
If low/minimal prominence items exist:
1. Run `python .agents/core/validators/generate_impact_brief.py` to compile the `impact_brief_report.json`.
2. For each candidate in the brief, present the 4-part brief (Usage Map, Original Justification, Cost of Removal, Options A/B/C/D) to the user and prompt them for a decision:
   - **Option A (Keep as-is)**: Ask for a reason, write it to `human_confirmed_reason`, and set `confirmed_by_human = true` and update `confirmed_at`.
   - **Option B (Keep but update scope)**: Prepare updates to tags or scope limit parameters in `skill_registry.json` and regeneration directives.
   - **Option C (Replace)**: Plan a replacement design to regenerate a different skill/MCP that better fits current requirements.
   - **Option D (Retire)**: Initiate the Retirement Gate.

### Retirement Gates
When retiring a skill or MCP (either via Option D or rejection at confirmation):
1. The agent MUST NOT silently or immediately delete files or justifications. The agent must present a summary of the plan (e.g. files to delete, registry and snapshot lines to clean up) and prompt the user for a final confirmation: "Are you sure you want to retire <name>?"
2. Only after explicit human confirmation can the actual mutations proceed:
   - For Skills:
     - Remove the skill entry from `skill_registry.json`.
     - Delete the skill's policy file at `.agents/skills/generated/<skill_name>/SKILL.md`.
     - Remove the skill from `skill_justifications` in `project_snapshot.json`.
   - For MCPs:
     - Remove the MCP from `mcp_justifications` in `project_snapshot.json`.
3. Save the updated files.
4. After saving, run mandatory validation on both mutated files:
   - `python .agents/core/validators/validate_json.py .agents/orchestration/project_snapshot.json .agents/core/contracts/project_snapshot.schema.json`
   - `python .agents/core/validators/validate_json.py .agents/orchestration/skill_registry.json`
   If either exits non-zero, halt immediately and report the exact error. Do not continue. The mutation must be manually inspected and repaired before proceeding.

## CONTRACTS
Inputs: two JSON files
Outputs:
- .agents/orchestration/skill_plan.json using skill_plan.schema.json
- .agents/orchestration/project_snapshot.json using project_snapshot.schema.json (updated/regenerated via generate_snapshot.py)
- .agents/orchestration/impact_brief_report.json (generated via generate_impact_brief.py during staleness reviews)
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
Version: 1.1.0
Compatible with: Gemini CLI, Claude, Cursor
Last validated: 2026-06-12

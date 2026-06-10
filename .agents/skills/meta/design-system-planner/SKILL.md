## POLICY

**Step 1 — Network pre-flight**
Check for `.agents/.allow_network`. If the file does not exist, halt immediately. Instruct the human to create it by running:
`touch .agents/.allow_network`
Do not proceed with any step until this file is confirmed to exist.

**Step 2 — Context extraction**
Read `.agents/orchestration/skill_requirements.json`. Extract `project_type` and all `justification` fields. Also read the project's `README.md` if it exists at the project root. Record both.

**Step 3 — Audience clarification**
If the target audience is not described in `skill_requirements.json` or `README.md`, ask the human to describe the target audience before proceeding. 
Do not assume. Record the human's response before moving to Step 4.

**Step 4 — Design skills discovery**
Search the skills.sh registry and similar agent skill registries for design-related SKILL.md files relevant to the project type and target audience.

For each skill found, record:
- Skill name
- What it does and what design area it covers
- Source URL
- Popularity indicators if available (most popular, least popular, trending)

Present the complete list to the human with all recorded details and source links. Wait for the human to select which skills to install, if any.

Before installing, ask the human to choose the installation location:
- Local: `.agents/skills/generated/` — applies only to this project, travels with the repository, must be reinstalled in other projects
- Global: `~/.agents/skills/` — available across all projects on this machine, maintained separately from the project

Present both options with this explanation. Wait for the human's choice.

After the human chooses location and skills, fetch each selected skill's SKILL.md content and write it to the correct path. Confirm each installation to the human.

Before installation, the agent MUST validate every selected skill against:
`.agents/core/skill_trust_registry.json`

The validation process must:
* calculate a trust score,
* detect blocked behaviors,
* validate compatibility requirements,
* validate required sections,
* and determine whether sandboxing is required.

If a skill is classified as:
* `trusted` → installation may proceed after human approval
* `restricted` → installation may proceed only in sandbox mode
* `untrusted` → installation must be blocked unless the human explicitly overrides

If sandboxing is required, the skill must follow:
`.agents/core/skill_sandbox_policy.json`

The agent must record:
* trust classification,
* trust score,
* detected risks,
* requested permissions,
* and sandbox status
inside:
`.agents/orchestration/design_research_session.json`

If no skills are selected, note this and proceed to Step 5.

**Step 5 — Path selection**
Present the human with two options and wait for their choice:
Path A: Deep internet research — the agent researches design ecosystems, competitor sites, inspiration sources, and design principles across the internet to find suitable design elements for this project.
Path B: DESIGN.md search — the agent searches for existing DESIGN.md files from projects similar to this one and presents links for the human to review and select from.

Do not proceed until the human makes a choice.

**Step 6 — PATH A: Deep design research**
Initialize a session tracking file at `.agents/orchestration/design_research_session.json` (see Stage 3 for reset).

Research the following source categories in sequence. For each source visited, record the URL in `visited_urls` before fetching its content:
a. Design principle and guideline resources — search for design system documentation, UX principle references, accessibility guidelines, and visual design standards relevant to the project type and audience.
b. Competitor and similar-purpose websites — search for websites that serve the same purpose as this project. Fetch and analyze their visual language: color choices, typography, layout structure, navigation patterns, component styles.
c. Design inspiration and showcase sources — search Dribbble, Behance, Awwwards, Mobbin, and similar showcase platforms for specific UI elements that match the project's purpose and audience expectations.
d. Free design template libraries — search for template collections that address this project type. Note recurring design patterns, component structures, and layout conventions.
e. Target audience research — search for studies, articles, or resources describing the visual expectations, preferences, and behaviors of the identified target audience.

For each piece of information collected, record:
- The design element (color system, typography choice, spacing pattern, layout structure, component style, etc.)
- The exact source URL it came from

After all research categories are complete, present findings to the human as a full discussion covering:
- The agent's reasoning process and why certain findings are relevant
- Everything found that could work for this project's design
- A structured list of all design elements organized by category, each with its direct source URL
- The agent's recommendation on which combinations would work best and why

Wait for the human's response and discussion. Do not write any output files at this stage.

If the human is not satisfied with the research findings:
Option 1 — Re-run research: Read `design_research_session.json` to get all previously visited URLs. Increment the round counter. Run the research again, explicitly avoiding all URLs in `visited_urls`. Add new URLs to the session file as they are visited.
Option 2 — Direct human input: Ask the human for specific direction — which websites to check, which specific elements they want researched, or if they have their own design vision to describe and discuss. Incorporate their input fully and proceed accordingly.

When the human confirms they are satisfied with a design direction, produce a complete specification covering every agreed design element.

Before final confirmation, the agent MUST run the `design-evaluator` skill.
The report must be written to `.agents/orchestration/design_evaluation_report.json`.
The human MUST review the evaluation report before final design confirmation.

**Step 7 — PATH B: DESIGN.md search**
Search the internet for DESIGN.md files from projects similar to this one in purpose, audience, and technology stack.
Present only links at this stage — do not fetch or install any DESIGN.md file. For each found file, provide:
- Project name and brief description
- Why it may be suitable for this project
- Direct link to the DESIGN.md file

Wait for the human's review and input.

If the human selects a DESIGN.md file:
1. Fetch its full content.
2. Write it to: `.agents/orchestration/design.md`
3. Extract all structured design information into: `.agents/orchestration/design_md_extracted.json`
4. Validate the extracted structure using: `bash .agents/core/validators/validate_design_md.sh .agents/orchestration/design_md_extracted.json`
5. Halt immediately if validation fails.
6. If validation succeeds: extract all design tokens, generate `design_tokens.json`, and summarize all extracted systems to the human.

**Step 8 — Output**
After the human's final confirmation (Path A) or successful DESIGN.md extraction (Path B), write `.agents/orchestration/design_tokens.json`.
Include:
- `human_approved_choice`: a string describing the confirmed design direction
- `typography`: object with all typography decisions
- `spacing`: object with spacing scale and rules
- `colors`: object with full color system and hex values
- `path_used`: either "research", "design_md", or "user_input"
- `source_references`: array of objects, each with `element` and `source_url`
- `installed_skills`: array of skill names installed in Step 4 (empty array if none)
- `design_md_path`: path to installed DESIGN.md if Path B was used, otherwise omit this field

**Step 9 — Validation**
Run `python .agents/core/validators/validate_design_tokens.py 
.agents/orchestration/design_tokens.json`.
Then run `python .agents/core/validators/validate_json.py 
.agents/orchestration/design_tokens.json 
.agents/core/contracts/design_tokens.schema.json`.

Both must exit 0. If either fails, halt and report the exact error before doing anything else.

## CONTRACTS
Input: `.agents/orchestration/skill_requirements.json`
Input (optional): Project `README.md` at project root
Input (conditional): `.agents/orchestration/design.md` if Path B installs one
Output: `.agents/orchestration/design_tokens.json`
Schema: `.agents/core/contracts/design_tokens.schema.json`
Session tracking file (internal, not a final output): `.agents/orchestration/design_research_session.json`

Validators (run both in order):
1. `python .agents/core/validators/validate_design_tokens.py 
.agents/orchestration/design_tokens.json`
2. `python .agents/core/validators/validate_json.py 
.agents/orchestration/design_tokens.json 
.agents/core/contracts/design_tokens.schema.json`
Both must exit 0. If either fails, halt.

## ADAPTER HINTS
<!--
UNLISTED PLATFORM PROTOCOL:
1. Read POLICY. This is your source of truth.
2. Read CONTRACTS. Your output must match exactly.
3. Map required actions to your native tools:
   - read files
   - write JSON
   - search the web
   - fetch web page content as markdown
   - ask human (mandatory at multiple gates)
   - run validation command
4. Do NOT edit this SKILL.md directly.
5. Write your adapter proposal to: .agents/adapters/staging/<your-platform>.adapter.md
6. Present proposal to human. Wait for explicit approval.
7. After approval, append your block (starting with "### <platform>") to this ADAPTER HINTS section.
8. Never delete, edit, or reorder existing content. Only append.
-->

### gemini-cli
- Read a file: `read_file`
- Write JSON output: `write_file`
- Search the web: `google_web_search`
- Fetch web page content as markdown: fetch MCP server (`@modelcontextprotocol/server-fetch`) — converts web pages to markdown for analysis
- Ask human at any gate: `ask_user` — extract selection from response payload; never proceed without explicit reply
- Run a validator script: `run_shell_command` — capture exit code; non-zero means halt
- Session tracking: `write_file` to `.agents/orchestration/design_research_session.json` after each URL visit

### claude
- Read a file: `bash_tool` with `cat`
- Write JSON output: `create_file` or `str_replace`
- Search the web: `web_search` tool
- Fetch web page content: `web_fetch` tool
- Ask human at any gate: conversational turn — state the question clearly, wait for explicit reply; do not assume a default; do not proceed without selection
- Run a validator script: `bash_tool` — capture exit code; non-zero means halt
- Session tracking: `create_file` or `str_replace` to update session file after each URL visit

### cursor
- Read a file: open in editor context
- Write JSON output: create or edit file via editor
- Search the web: integrated browser or terminal `curl` with search API
- Fetch web page content: terminal `curl`
- Ask human at any gate: inline editor prompt — wait for explicit confirmation before proceeding; never generate output without selection
- Run a validator script: integrated terminal — non-zero exit means halt
- Session tracking: create or edit session file after each URL visit

## FAILURE STATES
- `.allow_network` absent → halt at Step 1. Do not proceed. Instruct human to create the file.
- skills.sh or registry unreachable → report to human, skip Step 4, continue to Step 5.
- No design skills found on registry → report this to human, continue to Step 5.
- Skill installation fails (fetch error or write error) → report the specific skill that failed, skip it, continue with any remaining selections.
- Research source unreachable (individual URL fails) → log the failed URL in the session file, skip it, continue with next source.
- All research sources fail → halt and report to human. Do not present empty findings as valid research.
- Human rejects research twice → do not re-run a third time automatically. Ask the human to provide direct input.
- No DESIGN.md files found after two Path B searches → ask human to provide direct design input.
- Validation fails after writing design_tokens.json → halt, report exact error, do not advance phase.

## SAFETY RULES
- Never proceed past Step 1 without `.agents/.allow_network` confirmed present.
- Never present research findings without source URLs. Every design element must have a traceable source link.
- Never write `design_tokens.json` without explicit human confirmation.
- Never use default or hallucinated values for any design token.
- Never install a skill without the human selecting it explicitly.
- Never choose local vs global installation without asking the human.
- Never re-run research using previously visited URLs. Always read the session file before starting a new research round.
- Never present more than what was actually found.
- Never advance to Step 8 from Path A without the human's explicit confirmation of the full specification.
- Never fetch and install a DESIGN.md file without the human selecting it.

## HUMAN OVERRIDE RULES
This skill has six mandatory human gates. The agent must halt at each one and wait for explicit input before proceeding:
Gate 1 — Audience confirmation (Step 3)
Gate 2 — Skills selection (Step 4)
Gate 3 — Path selection (Step 5)
Gate 4 — Research findings review (Step 6, Path A)
Gate 5 — Final specification confirmation (Step 6, Path A)
Gate 6 — DESIGN.md selection (Step 7, Path B)

## VERSIONING
Version: 1.1.0
Requires: `.agents/.allow_network` flag file
Compatible with: Gemini CLI (with fetch MCP), Claude (with web tools)
Network access: explicitly permitted by global-policy.md whitelist

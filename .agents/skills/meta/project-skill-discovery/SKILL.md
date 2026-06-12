## POLICY

**Step 0 — Pre-flight check (codebase detection)**
Before doing anything else, scan for signs of an existing codebase.

Check for any of the following signals:
- Source code files anywhere in the project tree: `.php`, `.js`, `.ts`, `.py`, `.rb`, `.go`, `.java`, `.cs`, `.cpp`
- Dependency manifests at project root: `composer.json`, `package.json`, `requirements.txt`, `go.mod`, `Cargo.toml`, `build.gradle`
- Environment files: `.env`, `.env.example`
- Recognizable project directories: `src/`, `app/`, `public/`, `lib/`, `controllers/`, `models/`, `views/`

If two or more of these signals are found → codebase exists. Proceed to Step 1.
If fewer than two signals are found → no codebase detected. Enter Description Mode below before proceeding to Step 1.

---

**Description Mode — Stage 1: Scan for description resources**
Look for any of the following at the project root:
- `PROJECT.md`, `SPEC.md`, `BRIEF.md`, `README.md`
- A `/docs` folder — read all `.md` files inside it
- Any file whose name contains `spec`, `brief`, `plan`, `overview`, or `description` (case-insensitive)

Read every resource found in full. Extract whatever maps to these fields:
- What the project does → `project_type`
- What domains it covers → `detected_domains`
- What technology stack it will use → `detected_stack`
- What skills will be needed → `required_skills`

Record what was found and what is still unknown after reading.
If no description resources exist at all, record `description_resources_found: false` and go directly to Stage 2.

---

**Description Mode — Stage 2: Ask about remaining unknowns**
For every field still unknown after Stage 1, ask the user directly.
Ask one question at a time. Wait for a full response before asking the next.
Do not re-ask anything already resolved in Stage 1.

Ask in this order:
1. What does this project do? (if `project_type` still unknown)
2. Who will use it? (helps confirm domains)
3. What domains does it cover? (if `detected_domains` still empty — present the allowed domain list as options)
4. What language will the project be written in? (if `stack.language` unknown)
5. Will it use a framework, and if so which one? (if `stack.framework` unknown)
6. What database will it use? (if `stack.database` unknown)
7. What package manager will be used? (if `stack.package_manager` unknown)
8. Are there any external integrations planned — email, payments, storage, caching? (resolves remaining domains)

Stop as soon as all required fields are resolved. Do not ask unnecessary questions.

---

**Description Mode — Stage 3: Help the user decide on unknowns**
If the user answers "I don't know" or "not sure" to any Stage 2 question, do not skip that field.
Instead, provide a decision guide specific to this project:
- Present 2–4 realistic options for that field
- Give one sentence per option explaining why it fits or does not fit this specific project based on what the user has already described
- Ask the user to choose

Example — user does not know which database to use for a PHP expense-sharing app:
  "For a PHP expense-sharing app with multiple users and transaction history, here are the main options:
   - MySQL: Most common with PHP, strong support, well-suited for relational data like expenses, users, and balances. Recommended.
   - PostgreSQL: More advanced query features, slightly more setup with PHP. Worth it if complex reporting is planned.
   - SQLite: Zero setup, good for prototypes only. Not suitable for multi-user production apps.
   Which would you like to use?"

After the user chooses, record the decision and move to the next unknown field.

Once all fields are resolved through Stages 1–3:
- Proceed to Step 1 using the collected information as project context in place of file scanning
- Record `codebase_found: false` and `source: "description_mode"` in the output alongside all normal fields

---

**Step 1 — Map directory structure**
Read the project root directory. Use file listing tools to build a full map of the
folder structure. Record top-level directories and their immediate children.
Do not read file contents yet.

**Step 2 — Read README.md**
If `README.md` exists at the project root, read it in full.
Extract and record:
- What the project does (purpose)
- Who it is for (audience, if mentioned)
- Any technology stack explicitly named
- Any integrations or external services mentioned
If README.md does not exist, record `readme_found: false` and continue.

**Step 3 — Read environment file**
Check for `.env` at the project root. If it exists, read it.
If `.env` does not exist, check for `.env.example`. Read whichever is found.
Extract and record the names of all environment variable keys (not values).
Keys reveal external integrations:
- DB_HOST, DATABASE_URL → database domain
- MAIL_HOST, SMTP_, SENDGRID_ → email domain
- STRIPE_, PAYPAL_, RAZORPAY_ → payment domain
- AWS_KEY, S3_, CLOUDINARY_ → storage domain
- REDIS_, CACHE_ → caching domain
- JWT_SECRET, AUTH_ → authentication domain
Do not record values. Record key names only.
If neither `.env` nor `.env.example` exists, record `env_file_found: false` and continue.

**Step 4 — Read dependency manifests**
Read all of the following that exist:
- `composer.json` (PHP)
- `package.json` (Node/JS)
- `requirements.txt` (Python)
- `go.mod` (Go)
- `Cargo.toml` (Rust)
- `build.gradle` (Java)

From each manifest, extract:
- Language and runtime
- Framework (Laravel, Symfony, Express, Django, etc.)
- Database drivers or ORM libraries
- Authentication libraries
- Testing libraries
- Any other notable dependencies

If no manifests exist, record `manifests_found: false`. Set `project_type = "unknown"`.
Continue — do not halt.

**Step 5 — Sample representative code files**
Using the directory map from Step 1, identify major domains present
(e.g., controllers/, models/, services/, tests/, routes/, config/).
From each domain folder, select 2–3 representative files.
Read them to infer actual architecture patterns:
- ORM vs raw SQL
- MVC vs flat structure
- REST API vs server-rendered
- Authentication patterns
- File upload handling
- Any external API calls

Stop reading files once total files read reaches 45 (leaving buffer before the 50-file safety cap).

**Step 6 — Compile detected_domains**
From all evidence gathered in Steps 2–5, compile a `detected_domains` array.
Include a domain only if it is supported by at least one piece of evidence
(env key, manifest dependency, or code pattern). Do not add domains speculatively.

Use only values from this allowed list:
`database`, `authentication`, `file-management`, `email`, `payment`,
`api`, `testing`, `caching`, `logging`, `deployment`, `storage`,
`messaging`, `search`, `media`

**Step 7 — Compile detected_stack**
From manifests and code samples, record:
- `language`: primary language detected (e.g., "php", "javascript", "python")
- `framework`: primary framework detected (e.g., "laravel", "vanilla-php", "express")
- `database`: database type detected (e.g., "mysql", "postgresql", "sqlite", "none-detected")
- `package_manager`: package manager detected (e.g., "composer", "npm", "pip")

If any field cannot be determined, set it to `"unknown"`.

**Step 8 — Write output and validate**
Write all collected data to `.agents/orchestration/skill_requirements.json`.
The output must include: `project_type`, `detected_domains`, `detected_stack`, `required_skills`.
Then run:
python .agents/core/validators/validate_json.py .agents/orchestration/skill_requirements.json .agents/core/contracts/skill_requirements.schema.json
If exit code ≠ 0, halt and report the error. Do not advance the phase.

## CONTRACTS
- Input: none (implicit — current working directory)
- Reads (if present): `README.md`, `.env` or `.env.example`, dependency manifests, sample code files
- Reads (description mode): `PROJECT.md`, `SPEC.md`, `BRIEF.md`, `/docs/*.md`, or any spec-named file — used when no codebase is detected
- Human input required (description mode only): agent asks user directly for any fields not resolvable from description resources
- Output: `.agents/orchestration/skill_requirements.json`
  Schema: `.agents/core/contracts/skill_requirements.schema.json`
  Required fields: `project_type`, `detected_domains`, `detected_stack`, `required_skills`
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
- Ask human (Description Mode): `ask_user` — one question at a time; wait for explicit reply before proceeding to next question

### claude
- List directory / find files: `bash_tool` with `find` or `ls`
- Read a file: `bash_tool` with `cat`
- Write JSON output: `create_file` or `str_replace`
- Run a validator script: `bash_tool` — capture exit code; non-zero means halt
- Ask human (Description Mode): present question directly in conversation — wait for user reply before proceeding to next question

### cursor
- List directory / find files: integrated file explorer or terminal `find`
- Read a file: open in editor context
- Write JSON output: create or edit file via editor
- Run a validator script: integrated terminal — non-zero exit means halt
- Ask human (Description Mode): inline editor prompt — wait for explicit reply before proceeding to next question

## FAILURE STATES
- No manifests found → set `project_type = "unknown"`, `detected_stack` fields to "unknown", `detected_domains` to empty array. Still write and validate. Do not halt.
- README.md not found → record `readme_found: false`, continue with manifests and sampling. Do not halt.
- No .env or .env.example found → record `env_file_found: false`, continue. Do not halt.
- File sampling fails (read error on a code file) → log warning, skip that file, continue with remaining files. Do not halt.
- Validation fails on output → halt. Report exact validation error. Do not advance phase.
- No codebase and no description resources found → record `description_resources_found: false`, proceed directly to Description Mode Stage 2. Do not halt.
- User cannot answer a Stage 2 question and declines the Stage 3 guidance → record that field as `"unknown"` and continue. Do not halt. Flag the unknown field in the output so skill-architect is aware.
- User abandons Description Mode mid-way → halt. Record `status: "incomplete"` in output. Do not write a partial `skill_requirements.json`. Do not advance phase.

## SAFETY RULES
- Never read more than 50 files total.
- Never follow symlinks outside the project root.
- Never execute any code found in the project.

## HUMAN OVERRIDE RULES
- In Description Mode, human input is required at Stage 2 and Stage 3. The agent must wait for a full response before proceeding to the next question. Do not batch questions.
- The agent must never guess or assume a stack choice on behalf of the user. If unknown and the user declines guidance, record as "unknown" — do not fill in a value.
- If the user provides a description resource (PROJECT.md, etc.) mid-session after Stage 2 has started, stop questioning, read the resource, and resume from whatever fields are still unresolved.

## VERSIONING
Version: 1.2.0
Compatible with: Gemini CLI, Claude, Cursor
Last validated: 2026-06-11
Changelog: v1.2.0 — Added Step 0 pre-flight codebase detection. Added Description Mode (Stages 1–3) for scratch projects with no existing codebase. Added ask-human adapter mappings for all platforms. Updated FAILURE STATES and HUMAN OVERRIDE RULES for description mode flows.
           v1.1.0 — Added README.md and .env reading steps. Added detected_domains and detected_stack to output schema. mcp-plugin-discovery now reads structured categories instead of inferring from text.
## POLICY

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
- No manifests found → set `project_type = "unknown"`, `detected_stack` fields to "unknown", `detected_domains` to empty array. Still write and validate. Do not halt.
- README.md not found → record `readme_found: false`, continue with manifests and sampling. Do not halt.
- No .env or .env.example found → record `env_file_found: false`, continue. Do not halt.
- File sampling fails (read error on a code file) → log warning, skip that file, continue with remaining files. Do not halt.
- Validation fails on output → halt. Report exact validation error. Do not advance phase.

## SAFETY RULES
- Never read more than 50 files total.
- Never follow symlinks outside the project root.
- Never execute any code found in the project.

## HUMAN OVERRIDE RULES
None required for this skill. It is fully automated.

## VERSIONING
Version: 1.1.0
Compatible with: Gemini CLI, Claude, Cursor
Last validated: 2026-06-10
Changelog: v1.1.0 — Added README.md and .env reading steps. Added detected_domains and detected_stack to output schema. mcp-plugin-discovery now reads structured categories instead of inferring from text.

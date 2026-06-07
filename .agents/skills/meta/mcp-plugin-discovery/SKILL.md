## POLICY
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
Run `python .agents/core/validators/validate_json.py .agents/orchestration/mcp_recommendations.json .agents/core/contracts/mcp_recommendations.schema.json`. If validation fails, halt.

## CONTRACTS
- Input: .agents/orchestration/skill_requirements.json
- Output: .agents/orchestration/mcp_recommendations.json with schema mcp_recommendations.schema.json. Note: source is required.
- Validator: `python .agents/core/validators/validate_json.py .agents/orchestration/mcp_recommendations.json .agents/core/contracts/mcp_recommendations.schema.json`

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

## FAILURE STATES
- Cache missing or stale → output empty recommendations array, set source = "cache", populate error_message with the standard v1.0 message. Do not halt — this is a recoverable state.

## SAFETY RULES
- Never attempt any network fetch in v1.0. Live registry fetch is deferred to v1.1 pending registry API documentation.
- The `.allow_network` flag file and `mcp_registry_url` config value are reserved for future use. Do not read or act on them in this version.

## HUMAN OVERRIDE RULES
None — cache is read-only in v1.0. If the cache is missing or stale, the skill outputs an error_message and empty recommendations. The human must manually populate `mcp_cache.json` with known MCP packages.

## VERSIONING
Version: 1.0.0
Compatible with: Gemini CLI, Claude, Cursor
Last validated: 2026-06-07

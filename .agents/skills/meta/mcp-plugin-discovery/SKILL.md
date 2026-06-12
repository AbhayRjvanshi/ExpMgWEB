## POLICY
**Version: 1.1 — Live Registry Fetch with Controlled Category Search**

**Step 1 — Network pre-flight**
Check for `.agents/.allow_network`.
If it does not exist, fall back to v1.0 behavior:
- Check local cache at `.agents/orchestration/mcp_cache.json`.
- If cache exists and `last_updated` is within `mcp_cache_max_age_days` (from `config.json`), use it and set source = "cache".
- If cache is missing or stale, output empty `recommended_mcps` array, set source = "cache", populate `error_message` with: "Cache is stale or missing. Network access is disabled (.allow_network not found). Populate mcp_cache.json manually or create .agents/.allow_network to enable live fetch."
- Skip directly to Step 6.

**Step 2 — Read project requirements**
Read `.agents/orchestration/skill_requirements.json`.
Extract all detected domains and categories (e.g., "database", "authentication", "file-management", "testing").
These detected categories are your search scope. Only search for categories present in this file.
Do NOT search speculatively for categories not detected in the project.

**Step 3 — Read registry and budget configuration**
Read `.agents/core/config.json`. Extract:
- `mcp_registry_primary_url`
- `mcp_registry_fallback_url`
- `mcp_search.max_results_per_category`
- `mcp_search.use_fallback_on_empty_category`

Read `.agents/core/research_limits.json`. Extract `mcp_search_budget` section.
Record `max_total_registry_urls` as your hard URL cap for this entire search session.
Initialize a `urls_visited` counter at 0.

**Step 4 — Search registries, one category at a time**
Process each detected category from Step 2 in order.
Stop processing new categories if `urls_visited` reaches `max_total_registry_urls`.

For each category:

a. Search the primary registry (`mcp_registry_primary_url`) for MCP packages matching this category.
   - Count this as 1 URL visit. Increment `urls_visited`.
   - Record up to `max_results_per_category` results.
   - For each result record: package name, description, reasoning (why it matches), source_registry: "official".

b. If the primary registry returns 0 results for this category AND `use_fallback_on_empty_category` is true AND `urls_visited` has not reached the cap:
   - Search the fallback registry (`mcp_registry_fallback_url`) for the same category.
   - Count this as 1 URL visit. Increment `urls_visited`.
   - Record up to `max_results_per_category` results with source_registry: "community".

c. If the primary registry returned results for a category, do NOT also search the fallback for that same category.

**Step 5 — Deduplicate and score**
After all categories are searched:
- Identify duplicate packages: if the same package name appears from both registries, keep the "official" entry and discard the "community" duplicate.
- Apply scores: official packages = 1.0, community packages = 0.7.
- Sort results within each category by score descending.

Write the deduplicated, scored results to `.agents/orchestration/mcp_cache.json`:
```json
{
  "last_updated": "<current ISO timestamp>",
  "source": "live",
  "entries": {
    "<category>": [
      {
        "package": "...",
        "reasoning": "...",
        "source_registry": "official",
        "score": 1.0
      }
    ]
  }
}
```

**Step 6 — Filter against existing skills and write recommendations**
Read `.agents/orchestration/skill_registry.json`.
Do not recommend any MCP package that directly duplicates functionality already covered by a skill in the registry.

Write final output to `.agents/orchestration/mcp_recommendations.json`:
- `recommended_mcps`: filtered, ranked list of relevant packages from the cache
- `source`: "live" if network was used in this session, "cache" if Step 1 fallback was used
- `error_message`: empty string if successful, error description otherwise

Run:
python .agents/core/validators/validate_json.py .agents/orchestration/mcp_recommendations.json .agents/core/contracts/mcp_recommendations.schema.json
If exit code ≠ 0, halt and report the error. Do not advance the phase.

## CONTRACTS
- Input: `.agents/orchestration/skill_requirements.json`
- Input: `.agents/core/config.json` (registry URLs and search config)
- Input: `.agents/core/research_limits.json` (mcp_search_budget)
- Output: `.agents/orchestration/mcp_cache.json` (live fetch results, written in Step 5)
- Output: `.agents/orchestration/mcp_recommendations.json` with schema `mcp_recommendations.schema.json`. Note: source and error_message are required.
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
- `.allow_network` missing → fall back to v1.0 cache behavior. Do not halt — recoverable.
- Cache missing or stale (v1.0 fallback) → output empty recommendations array, set source = "cache", populate error_message. Do not halt — recoverable.
- Primary registry returns 0 results for all categories → use fallback registry. If fallback also returns 0 for all categories, output empty recommendations, set error_message. Do not halt.
- `urls_visited` cap reached mid-search → stop searching, proceed with results gathered so far. Log warning in error_message.
- Validation failure → halt. Do not advance phase.

## SAFETY RULES
- Never attempt a network fetch unless `.agents/.allow_network` exists.
- Never search categories not detected in `skill_requirements.json`.
- Never exceed `max_total_registry_urls` from `mcp_search_budget`.
- Never search the fallback registry for a category already covered by the primary registry.
- Never recommend packages that duplicate existing skills in the registry.
- Never hallucinate package names or invent registry results.

## HUMAN OVERRIDE RULES
- If `.allow_network` does not exist and the cache is missing or stale, the human must either:
  - Create `.agents/.allow_network` to enable live fetch, or
  - Manually populate `.agents/orchestration/mcp_cache.json` with known MCP packages and re-run.
- The human may disable live fetch at any time by deleting `.agents/.allow_network`. The skill will revert to cache behavior automatically.

## VERSIONING
Version: 1.1.0
Compatible with: Gemini CLI, Claude, Cursor
Last validated: 2026-06-10
Changelog: v1.1.0 — Added live registry fetch with two-registry controlled search, category-scoped budgeting, deduplication, and scoring. v1.0.0 cache-only behavior retained as automatic fallback when .allow_network is absent.

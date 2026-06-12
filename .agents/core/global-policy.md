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
- Network access is prohibited for all skills except `design-system-planner` and `mcp-plugin-discovery`, which require network access as their core function. Both skills may make network requests only when `.agents/.allow_network` exists. All other skills must never make network requests regardless of whether `.allow_network` is present.
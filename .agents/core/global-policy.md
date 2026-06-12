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

## Drift Detection (Trigger Cascade)

On every entry into PHASE_4_CODE, before writing any implementation code, run:
`python .agents/core/validators/detect_drift.py`

Both Trigger 1 (structural scan) and Trigger 2 (commit weight) always run on
phase entry. Either one can escalate to Trigger 3; a silent pass requires both
to be quiet. Signals previously marked as exceptions by the user are excluded
from escalation automatically, so an acknowledged signal cannot generate
repeat reports.

- Exit 0 → proceed with coding normally. Do not mention drift to the user.
- Exit 2 → STOP. Read `.agents/orchestration/drift_report.json`. Present
  Sections 1–4 (Trigger 1 findings, Trigger 2 weight breakdown with severity,
  agent assessment, targeted questions) in chronological order. Ask the
  questions one at a time — wait for each answer before asking the next.
  Record each answer in `user_questions[].user_answer` and set
  `answered: true`.
- Exit 1 → report the error to the user. Do not proceed silently.

Resolution rules (apply after all questions are answered):
- If the user confirms changes are significant → present the report's
  `rerun_recommendations`, set `status = "resolved_rerun"`.
- If the user marks signals as temporary → add their signal keys (the
  `user_questions[].signal` values) to `user_exceptions`, reset
  `accumulated_commit_weight` to 0 in `project_snapshot.json`,
  set `status = "resolved_exceptions_noted"`. No rerun.
- If nothing significant changed → set `status = "resolved_no_action"`.
Always set `resolved_at` when leaving `pending_user_response`. Apply the
same resolution edits to the archived copy in
`.agents/orchestration/drift_reports/` so the audit trail stays accurate.

After applying resolution edits, run:
`python .agents/core/validators/validate_drift_resolution.py`
Exit 0 confirms a consistent resolution (all questions answered, valid
status, `resolved_at` set, archive and latest copies identical). Any other
exit code means the resolution is incomplete — fix the reported issues
before continuing Phase 4.

Manual invocation: when the user asks for a drift check, run with
`--mode manual`. This always produces a full report regardless of
thresholds. Reports are archived under
`.agents/orchestration/drift_reports/drift_YYYYMMDD_NNN.json`;
`drift_report.json` is always the latest report.
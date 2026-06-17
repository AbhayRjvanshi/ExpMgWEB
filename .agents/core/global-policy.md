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
To execute the resolution mechanically, run:
`python .agents/core/validators/resolve_drift.py --status <STATUS> [--exceptions <comma-separated-signals>]`
Where STATUS is one of:
- `resolved_rerun` — if the user confirms changes are significant:
  1. Read and present the report's `rerun_recommendations` section to the user.
  2. Confirm with the user which phases will be re-run before proceeding.
  3. Then run: resolve_drift.py --status resolved_rerun
- `resolved_exceptions_noted` — if changes are temporary and should be suppressed:
  1. Add their signal keys (the `user_questions[].signal` values) to `user_exceptions`.
  2. Run: resolve_drift.py --status resolved_exceptions_noted --exceptions <comma-separated-signals>
  This will reset `accumulated_commit_weight` to 0 in `project_snapshot.json`.
- `resolved_no_action` — if no action is required and no exceptions are noted:
  1. Run: resolve_drift.py --status resolved_no_action

The resolve_drift.py script will automatically:
1. Validate all questions are answered and user answers are not empty.
2. Validate that `--exceptions` correspond to signals in the report.
3. Validate that `--exceptions` is only used with `resolved_exceptions_noted` or `resolved_rerun`.
4. Set the resolution timestamp.
5. Save the report and its archive copy.
6. Validate the resolved report against `validate_drift_resolution.py` with a 30s timeout, halting immediately on failure.
7. Reset `accumulated_commit_weight` to 0 in `project_snapshot.json` only if STATUS is `resolved_exceptions_noted`.
8. Validate the project snapshot against its JSON schema using `validate_json.py` with a 15s timeout if the snapshot was mutated.

Manual invocation: when the user asks for a drift check, run with
`--mode manual`. This always produces a full report regardless of
thresholds. Reports are archived under
`.agents/orchestration/drift_reports/drift_YYYYMMDD_NNN.json`;
`drift_report.json` is always the latest report.
# Final Fix: Update design-evaluator Validator

## Objective
Update `.agents/skills/meta/design-evaluator/SKILL.md` to use the newly created JSON schema validator instead of the temporary syntax-only check.

## Target File
`.agents/skills/meta/design-evaluator/SKILL.md`

## Action Required
Replace the outdated syntax-only validation command with the strict schema validation command.

### From (Old):
- Validator: `python -m json.tool .agents/orchestration/design_evaluation_report.json` (syntax-only check — upgrade to schema validation once `.agents/core/contracts/design_evaluation_report.schema.json` is created).

### To (New):
- Validator: `python .agents/core/validators/validate_json.py .agents/orchestration/design_evaluation_report.json .agents/core/contracts/design_evaluation_report.schema.json`

## Implementation Steps
1. **Apply the Fix:** Use `replace` on `.agents/skills/meta/design-evaluator/SKILL.md` to update the validator string.
2. **Verification:** Confirm the file now contains the new validation command and verify the exact string match.

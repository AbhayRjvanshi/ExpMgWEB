# Gemini CLI Orchestration Adapter
<!-- adapter-version: 1.0.0 -->
<!-- last-validated: 2026-06-07 -->

At the start of every session, read `.agents/orchestration/phase.json`.
Check `current_phase` and `status`.
If `status` is `ERROR_HALTED`, stop and inform the user before doing anything else.
If `current_phase` is not `PHASE_4_CODE`, do not write any implementation code.
Follow the phase transition rules in `.agents/core/global-policy.md`.

## Tool Mappings
- List files: `glob`
- Read file: `read_file`
- Write JSON: `write_file`
- Ask human: `ask_user`
- Run validator: `run_shell_command` — capture exit code; treat non-zero as failure.

## Activating Skills
Before beginning any phase task, load the relevant skill file so its POLICY
section is in context.

**Primary method** (if your Gemini CLI version supports it):
`activate_skill .agents/skills/meta/<skill-name>/SKILL.md`
`activate_skill .agents/skills/generated/<skill-name>/SKILL.md`

**Fallback method** (always works):
Use `read_file` on the SKILL.md path. Treat the POLICY section as binding
instructions for the duration of that task. Consult the ADAPTER HINTS section
for Gemini-specific tool mappings. Do not begin any phase task without first
reading the relevant skill file by one of these two methods.

To verify which method your runtime supports: attempt `activate_skill` on any
SKILL.md path. If the command is not recognized, use `read_file` for all
subsequent skill loading. Note which method works and use it consistently for
the entire session.

## Drift Detection
On every entry into PHASE_4_CODE, before writing any implementation code:
run `run_shell_command` → `python .agents/core/validators/detect_drift.py`
and capture the exit code.
- Exit 0 → proceed with coding normally. Do not mention drift to the user.
- Exit 2 → halt coding. Use `read_file` on
  `.agents/orchestration/drift_report.json`, present Sections 1–4 in order,
  and ask the targeted questions one at a time via `ask_user` — wait for each
  response payload before the next question.
- Exit 1 → report the error to the user. Do not proceed silently.
When the user asks for a drift check directly, run with `--mode manual`.
Follow the resolution rules in `.agents/core/global-policy.md` (Drift Detection section).

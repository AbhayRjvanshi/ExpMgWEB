# Claude Orchestration Adapter
<!-- adapter-version: 1.0.0 -->
<!-- last-validated: 2026-06-07 -->
<!-- conformance-test: adapters/claude/conformance-test.md -->

At the start of every session, read `.agents/orchestration/phase.json`.
Check `current_phase` and `status`.
If `status` is `ERROR_HALTED`, stop and inform the user before doing anything else.
If `current_phase` is not `PHASE_4_CODE`, do not write any implementation code.
Follow the phase transition rules in `.agents/core/global-policy.md`.

## Tool Mappings
- Read file: `bash_tool` with `cat`
- Write JSON: `create_file` or `str_replace`
- Ask human: conversational turn — ask explicitly and wait for reply before proceeding
- Run validator: `bash_tool` — capture exit code; treat non-zero as failure.

## Activating Skills
To use a skill: read the SKILL.md at the relevant path using `bash_tool` with `cat`.
Treat the POLICY section as binding instructions for the duration of that task.
Consult the ADAPTER HINTS section for Claude-specific tool mappings.
Do not begin a phase task without first reading the relevant skill file.

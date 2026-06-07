## POLICY
The `design-evaluator` skill is responsible for evaluating, scoring, ranking, and validating proposed design directions before final design confirmation.

**The skill must:**
1. Read `.agents/orchestration/design_research_session.json`, `.agents/core/research_limits.json`, and any finalized design discussion summaries.
2. Evaluate all proposed design directions.
3. Score each direction against these categories:
   - accessibility: readability, contrast, usability
   - readability: text hierarchy and comprehension
   - mobile_adaptability: responsive behavior
   - audience_fit: alignment with target audience
   - implementation_complexity: frontend difficulty
   - design_consistency: system coherence
   - scalability: future expansion support
4. Detect accessibility risks, implementation conflicts, scalability problems, responsiveness issues, and inconsistent design systems.
5. Rank all candidate designs.
6. Generate structured evaluation reports (including strengths/weaknesses, conflict analysis, and recommendation summaries) to `.agents/orchestration/design_evaluation_report.json`.
7. Require human review of the report before final approval.

**The skill MUST NOT:**
- finalize designs automatically,
- overwrite design tokens,
- silently modify research findings,
- or ignore evaluation thresholds.

## CONTRACTS
- Input: `.agents/orchestration/design_research_session.json`, `.agents/core/research_limits.json`
- Output: `.agents/orchestration/design_evaluation_report.json`
- Validator: `python .agents/core/validators/validate_json.py .agents/orchestration/design_evaluation_report.json` (syntax check only if schema missing).

## ADAPTER HINTS
<!--
UNLISTED PLATFORM PROTOCOL:
1. Read POLICY. This is your source of truth.
2. Read CONTRACTS. Your output must match exactly.
3. Map required actions to your native tools.
4. Do NOT edit this SKILL.md directly.
5. Write your adapter proposal to: .agents/adapters/staging/<your-platform>.adapter.md
6. Present proposal to human. Wait for explicit approval.
7. After approval, append your block (starting with "### <platform>") to this ADAPTER HINTS section.
8. Never delete, edit, or reorder existing content. Only append.
-->

### gemini-cli
- Read JSON files: `read_file`
- Update orchestration state: `write_file`
- Ask human for review: `ask_user`

### claude
- Read files: `bash_tool` with `cat`
- Write JSON: `create_file` or `str_replace`
- Ask human: conversational turn

### cursor
- Read files: editor context
- Write JSON: editor diff
- Ask human: inline editor prompt

## FAILURE STATES
- Missing Research Findings → halt evaluation.
- Missing Design Directions → request additional design discussion.
- Invalid JSON Structure → halt, request schema correction.

## SAFETY RULES
- Never hallucinate evaluation scores or invent source references.
- Never ignore accessibility concerns or bypass human confirmation.
- Keep all evaluations traceable and explain all scoring decisions.

## HUMAN OVERRIDE RULES
- Human review is REQUIRED before final design approval or design token generation.
- The user may reject designs, rerun evaluation, or override recommendations.

## VERSIONING
Version: 1.0.0
Compatible with: Gemini CLI, Claude, Cursor
Last validated: 2026-06-07

## POLICY
[Describe what this skill must do using generic verbs like read, write, ask, validate. Do not include runtime-specific tool names.]

## CONTRACTS
- Input: [Required files/data]
- Output: [Generated files/data]
- Schema: [Path to validation schema]
- Validator: [Validation command]

## ADAPTER HINTS
<!--
UNLISTED PLATFORM PROTOCOL:
1. Read POLICY. This is your source of truth.
2. Read CONTRACTS. Your output must match exactly.
3. Map required actions to your native tools:
   - read files
   - write JSON
   - [other actions required by this skill]
4. Do NOT edit this SKILL.md directly.
5. Write your adapter proposal to: .agents/adapters/staging/<your-platform>.adapter.md
6. Present proposal to human. Wait for explicit approval.
7. After approval, append your block (starting with "### <platform>") to this ADAPTER HINTS section.
8. Never delete, edit, or reorder existing content. Only append.
-->

### gemini-cli
- [Mapping for action 1]
- [Mapping for action 2]

### claude
- [Mapping for action 1]
- [Mapping for action 2]

### cursor
- [Mapping for action 1]
- [Mapping for action 2]

## FAILURE STATES
- [Failure condition 1] → [Recovery action]
- [Failure condition 2] → [Recovery action]

## SAFETY RULES
- [Safety rule 1]
- [Safety rule 2]

## HUMAN OVERRIDE RULES
- [Mandatory human gate 1]
- [Mandatory human gate 2]

## VERSIONING
Version: 1.0.0
Compatible with: Gemini CLI, Claude, Cursor
Last validated: [Date]

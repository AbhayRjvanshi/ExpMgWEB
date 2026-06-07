## POLICY
Handles async notifications and idempotency via a custom durable outbox.
Enforce architectural integrity and domain-specific rules for distributed-resilience-architect.

## CONTRACTS
- Input: Relevant project files
- Output: Validated and improved implementation

## ADAPTER HINTS
<!--
UNLISTED PLATFORM PROTOCOL:
1. Read POLICY. This is your source of truth.
2. Read CONTRACTS. Your output must match exactly.
3. Map required actions to your native tools.
4. Do NOT edit this SKILL.md directly.
-->

### gemini-cli
- read_file
- write_file
- glob
- run_shell_command

## FAILURE STATES
- Logic inconsistencies found during sampling.
- Validation failures.

## SAFETY RULES
- Never bypass idempotency checks.
- Never modify core business logic without explicit verification.

## HUMAN OVERRIDE RULES
- Approval required for any change affecting the Settlement Lock mechanism.

## VERSIONING
Version: 1.0.0
Created: 2026-06-06T18:36:01.093742

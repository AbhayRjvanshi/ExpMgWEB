#!/bin/bash

SKILL_PATH=$1

echo "Running skill security validation..."

if grep -q "rm -rf" "$SKILL_PATH"; then
echo "Blocked dangerous deletion command detected."
exit 1
fi

if grep -q "automatic_global_installation" "$SKILL_PATH"; then
echo "Blocked automatic global installation behavior detected."
exit 1
fi

echo "Skill security validation passed."
exit 0

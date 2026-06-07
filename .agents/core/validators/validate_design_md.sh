#!/bin/bash

DESIGN_MD_JSON=$1
SCHEMA_PATH=".agents/core/contracts/design_md.schema.json"

echo "Validating DESIGN.md extracted structure..."

python .agents/core/validators/validate_json.py "$DESIGN_MD_JSON" "$SCHEMA_PATH"

if [ $? -ne 0 ]; then
echo "DESIGN.md validation failed."
exit 1
fi

echo "DESIGN.md validation passed."
exit 0

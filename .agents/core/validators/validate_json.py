import json
import sys
from pathlib import Path

def validate_json():
    if len(sys.argv) < 3:
        print("Usage: validate_json.py <json-file> <schema-file>")
        return 1
    
    json_path = Path(sys.argv[1])
    schema_path = Path(sys.argv[2])
    
    if not json_path.exists():
        print(f"Error: JSON file not found: {json_path}")
        return 1
    if not schema_path.exists():
        print(f"Error: Schema file not found: {schema_path}")
        return 1
        
    try:
        from jsonschema import validate, ValidationError
    except ImportError:
        print("jsonschema dependency missing")
        return 1

    try:
        data = json.loads(json_path.read_text(encoding='utf-8'))
        schema = json.loads(schema_path.read_text(encoding='utf-8'))
        validate(instance=data, schema=schema)
        print("Validation Successful.")
        return 0
    except ValidationError as e:
        print(f"Validation Error: {e.message}")
        return 1
    except Exception as e:
        print(f"Error during validation: {e}")
        return 1

if __name__ == "__main__":
    sys.exit(validate_json())

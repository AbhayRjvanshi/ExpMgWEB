import json
import sys
from pathlib import Path

def validate():
    if len(sys.argv) < 2:
        print("Usage: validate_design_tokens.py <json-file>")
        return 1
    
    path = Path(sys.argv[1])
    if not path.exists():
        print(f"Error: File {path} not found")
        return 1

    try:
        data = json.loads(path.read_text(encoding='utf-8'))
        
        # Check human_approved_choice
        if not data.get('human_approved_choice', '').strip():
            print("Error: human_approved_choice is empty or missing")
            return 1
            
        # Check path_used
        path_used = data.get('path_used')
        allowed_paths = ["research", "design_md", "user_input"]
        if path_used not in allowed_paths:
            print(f"Error: invalid or missing path_used: {path_used}. Allowed: {allowed_paths}")
            return 1
            
        # Check source_references
        source_refs = data.get('source_references')
        if not isinstance(source_refs, list):
            print("Error: source_references must be a list")
            return 1
            
        if path_used == "research" and not source_refs:
            print("Error: source_references cannot be empty when path_used is 'research'")
            return 1
            
        print("Design tokens validation successful.")
        return 0
    except Exception as e:
        print(f"Error validating design tokens: {e}")
        return 1

if __name__ == "__main__":
    sys.exit(validate())

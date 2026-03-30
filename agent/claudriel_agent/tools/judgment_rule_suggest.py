"""Tool: Suggest a judgment rule from a user correction."""

TOOL_DEF = {
    "name": "judgment_rule_suggest",
    "description": "Suggest a new judgment rule when the user corrects your behavior. Call this when the user says something like 'no, always do X' or 'don't do Y'.",
    "input_schema": {
        "type": "object",
        "properties": {
            "rule_text": {
                "type": "string",
                "description": "The rule itself, e.g. 'Always CC assistant@example.com on client emails'",
                "maxLength": 500,
            },
            "context": {
                "type": "string",
                "description": "When this rule applies, e.g. 'When sending emails to clients'",
                "maxLength": 1000,
            },
            "confidence": {
                "type": "number",
                "description": "How confident this rule is (0.7-1.0). User corrections should be 1.0.",
                "default": 0.8,
            },
        },
        "required": ["rule_text", "context"],
    },
}


def execute(api, args: dict) -> dict:
    return api.post(
        "/api/internal/rules/suggest",
        json_data={
            "rule_text": args["rule_text"],
            "context": args.get("context", ""),
            "confidence": args.get("confidence", 0.8),
        },
    )

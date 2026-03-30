"""Tool: List untriaged items needing attention."""

TOOL_DEF = {
    "name": "triage_list",
    "description": "List untriaged items needing attention.",
    "input_schema": {
        "type": "object",
        "properties": {
            "limit": {
                "type": "integer",
                "description": "Maximum number of entries to return (default: 20)",
                "default": 20,
            },
        },
    },
}


def execute(api, args: dict) -> dict:
    return api.get(
        "/api/internal/triage/list",
        params={
            "limit": args.get("limit", 20),
        },
    )

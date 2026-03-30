"""Tool: List all workspaces."""

TOOL_DEF = {
    "name": "workspace_list",
    "description": "List all workspaces.",
    "input_schema": {
        "type": "object",
        "properties": {
            "limit": {
                "type": "integer",
                "description": "Maximum number of workspaces to return (default: 50)",
                "default": 50,
            },
        },
    },
}


def execute(api, args: dict) -> dict:
    return api.get(
        "/api/internal/workspaces/list",
        params={
            "limit": args.get("limit", 50),
        },
    )

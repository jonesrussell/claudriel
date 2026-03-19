"""Tool: Get full context for a specific workspace."""

TOOL_DEF = {
    "name": "workspace_context",
    "description": "Get full context for a specific workspace.",
    "input_schema": {
        "type": "object",
        "properties": {
            "uuid": {
                "type": "string",
                "description": "Workspace UUID",
            },
        },
        "required": ["uuid"],
    },
}


def execute(api, args: dict) -> dict:
    return api.get(f"/api/internal/workspaces/{args['uuid']}")

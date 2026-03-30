"""Tool: List prospects for a workspace."""

TOOL_DEF = {
    "name": "prospect_list",
    "description": "List sales prospects (pipeline leads) for a workspace.",
    "input_schema": {
        "type": "object",
        "properties": {
            "workspace_uuid": {
                "type": "string",
                "description": "Workspace UUID that owns the pipeline",
            },
            "limit": {
                "type": "integer",
                "description": "Max results (default: 50, max: 100)",
                "default": 50,
            },
        },
        "required": ["workspace_uuid"],
    },
}


def execute(api, args: dict) -> dict:
    params = {
        "workspace_uuid": args["workspace_uuid"],
        "limit": args.get("limit", 50),
    }
    return api.get("/api/internal/prospects/list", params=params)

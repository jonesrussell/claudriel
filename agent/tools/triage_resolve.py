"""Tool: Resolve a triage item."""

TOOL_DEF = {
    "name": "triage_resolve",
    "description": "Resolve a triage item.",
    "input_schema": {
        "type": "object",
        "properties": {
            "uuid": {
                "type": "string",
                "description": "Triage entry UUID",
            },
            "status": {
                "type": "string",
                "description": "Resolution status (default: resolved)",
                "default": "resolved",
            },
        },
        "required": ["uuid"],
    },
}


def execute(api, args: dict) -> dict:
    return api.post(
        f"/api/internal/triage/{args['uuid']}/resolve",
        json_data={"status": args.get("status", "resolved")},
    )

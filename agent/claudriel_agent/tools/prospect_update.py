"""Tool: Update a prospect (e.g. workflow stage)."""

TOOL_DEF = {
    "name": "prospect_update",
    "description": "Update a prospect's stage or qualification notes.",
    "input_schema": {
        "type": "object",
        "properties": {
            "uuid": {
                "type": "string",
                "description": "Prospect entity UUID",
            },
            "stage": {
                "type": "string",
                "description": "Workflow stage (e.g. lead, qualified, contacted)",
            },
            "qualify_notes": {
                "type": "string",
                "description": "Optional qualification / notes text",
            },
        },
        "required": ["uuid"],
    },
}


def execute(api, args: dict) -> dict:
    uuid = args["uuid"]
    payload = {}
    if "stage" in args and args["stage"]:
        payload["stage"] = args["stage"]
    if "qualify_notes" in args:
        payload["qualify_notes"] = args["qualify_notes"]
    return api.post(f"/api/internal/prospects/{uuid}/update", json_data=payload)

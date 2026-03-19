TOOL_DEF = {
    "name": "brief_generate",
    "description": "Generate the user's daily brief with commitments, events, and context.",
    "input_schema": {
        "type": "object",
        "properties": {
            "since": {
                "type": "string",
                "description": "ISO date for time window start (default: 24h ago)",
            },
        },
    },
}


def execute(api, args: dict) -> dict:
    data = {}
    if "since" in args:
        data["since"] = args["since"]
    return api.post("/api/internal/brief/generate", json_data=data)

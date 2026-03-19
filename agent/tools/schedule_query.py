"""Tool: Query schedule entries by date range."""

TOOL_DEF = {
    "name": "schedule_query",
    "description": "Query schedule entries by date range.",
    "input_schema": {
        "type": "object",
        "properties": {
            "date_from": {
                "type": "string",
                "description": "Start date (ISO format)",
            },
            "date_to": {
                "type": "string",
                "description": "End date (ISO format)",
            },
            "limit": {
                "type": "integer",
                "description": "Maximum number of entries to return (default: 50)",
                "default": 50,
            },
        },
    },
}


def execute(api, args: dict) -> dict:
    params = {"limit": args.get("limit", 50)}
    if "date_from" in args:
        params["date_from"] = args["date_from"]
    if "date_to" in args:
        params["date_to"] = args["date_to"]
    return api.get("/api/internal/schedule/query", params=params)

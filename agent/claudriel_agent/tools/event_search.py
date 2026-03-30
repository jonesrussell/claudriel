TOOL_DEF = {
    "name": "event_search",
    "description": "Search events by keyword and optional date range.",
    "input_schema": {
        "type": "object",
        "properties": {
            "query": {"type": "string", "description": "Search keyword"},
            "date_from": {"type": "string", "description": "Start date (ISO)"},
            "date_to": {"type": "string", "description": "End date (ISO)"},
            "limit": {
                "type": "integer",
                "description": "Max results (default: 20)",
                "default": 20,
            },
        },
        "required": ["query"],
    },
}


def execute(api, args: dict) -> dict:
    params = {"query": args["query"], "limit": args.get("limit", 20)}
    if "date_from" in args:
        params["date_from"] = args["date_from"]
    if "date_to" in args:
        params["date_to"] = args["date_to"]
    return api.get("/api/internal/events/search", params=params)

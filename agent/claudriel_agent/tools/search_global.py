TOOL_DEF = {
    "name": "search_global",
    "description": "Search across all entity types (persons, commitments, events) by keyword.",
    "input_schema": {
        "type": "object",
        "properties": {
            "query": {"type": "string", "description": "Search keyword"},
            "limit": {
                "type": "integer",
                "description": "Max results per type (default: 10)",
                "default": 10,
            },
        },
        "required": ["query"],
    },
}


def execute(api, args: dict) -> dict:
    return api.get(
        "/api/internal/search/global",
        params={"query": args["query"], "limit": args.get("limit", 10)},
    )

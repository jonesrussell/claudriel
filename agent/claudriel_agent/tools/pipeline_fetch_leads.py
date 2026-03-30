"""Tool: Fetch leads from North Cloud into Claudriel for a workspace."""

TOOL_DEF = {
    "name": "pipeline_fetch_leads",
    "description": (
        "Import leads from the configured North Cloud /api/leads endpoint into prospects "
        "for this workspace (requires PipelineConfig with source_url)."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "workspace_uuid": {
                "type": "string",
                "description": "Workspace UUID with pipeline configuration",
            },
        },
        "required": ["workspace_uuid"],
    },
}


def execute(api, args: dict) -> dict:
    return api.post(
        "/api/internal/pipeline/fetch-leads",
        json_data={"workspace_uuid": args["workspace_uuid"]},
    )

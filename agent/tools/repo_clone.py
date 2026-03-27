"""Tool: Clone a Git repository into a workspace."""

import re

TOOL_DEF = {
    "name": "repo_clone",
    "description": (
        "Clone a public Git repository into a workspace directory. "
        "The workspace must already exist (call workspace_create first). "
        "Repo format: owner/name (e.g., 'jonesrussell/me')."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "workspace_uuid": {
                "type": "string",
                "description": "UUID of the workspace to clone into.",
            },
            "repo": {
                "type": "string",
                "description": "Repository in owner/name format (e.g., 'jonesrussell/me').",
                "pattern": "^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$",
            },
            "branch": {
                "type": "string",
                "description": "Branch to clone. Defaults to 'main'.",
            },
        },
        "required": ["workspace_uuid", "repo"],
    },
}


def execute(api, args: dict) -> dict:
    repo = args["repo"]
    if not re.match(r"^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$", repo):
        return {"error": f"Invalid repo format: {repo!r}. Expected owner/name."}

    branch = args.get("branch", "main")
    if not re.match(r"^[a-zA-Z0-9_./%-]+$", branch):
        return {"error": f"Invalid branch name: {branch!r}."}

    uuid = args["workspace_uuid"]
    return api.post(
        f"/api/internal/workspaces/{uuid}/clone-repo",
        json_data={
            "repo": repo,
            "branch": branch,
        },
    )

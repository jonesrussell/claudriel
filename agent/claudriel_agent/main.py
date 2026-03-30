"""stdin/stdout entry for the Claudriel agent."""

from __future__ import annotations

import json
import sys
from typing import Any

from claudriel_agent.emit import emit
from claudriel_agent.loop import run_agent_request
from claudriel_agent.tools_discovery import ToolRegistry


def main() -> None:
    try:
        raw = json.load(sys.stdin)
    except json.JSONDecodeError as e:
        emit("error", message=f"Invalid JSON input: {e}")
        sys.exit(1)

    if not isinstance(raw, dict):
        emit("error", message="Invalid JSON input: expected object at root")
        sys.exit(1)

    request: dict[str, Any] = raw
    registry = ToolRegistry()
    run_agent_request(request, registry)


if __name__ == "__main__":
    main()

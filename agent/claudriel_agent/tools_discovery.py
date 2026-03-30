"""Dynamic discovery of tool modules (TOOL_DEF + execute)."""

from __future__ import annotations

import importlib
import os
from collections.abc import Callable
from pathlib import Path
from typing import Any


def parse_enabled_tool_names(raw_value: str | None) -> set[str] | None:
    """Parse a comma-separated allowlist from config."""
    if raw_value is None:
        return None

    names = {name.strip() for name in raw_value.split(",") if name.strip()}

    return names or None


def discover_tools(
    package_name: str = "claudriel_agent.tools",
    package_path: Path | None = None,
    enabled_tool_names: set[str] | None = None,
) -> tuple[list[dict[str, Any]], dict[str, Callable[..., Any]]]:
    """Discover tool modules that export TOOL_DEF and execute()."""
    tools_dir = package_path or (Path(__file__).resolve().parent / "tools")
    tool_defs: list[dict[str, Any]] = []
    executors: dict[str, Callable[..., Any]] = {}

    importlib.invalidate_caches()

    for module_path in sorted(tools_dir.glob("*.py")):
        if module_path.name == "__init__.py":
            continue

        module_name = module_path.stem
        module = importlib.import_module(f"{package_name}.{module_name}")

        tool_def = getattr(module, "TOOL_DEF", None)
        executor = getattr(module, "execute", None)
        if tool_def is None or executor is None or not callable(executor):
            continue

        tool_name = tool_def.get("name")
        if not isinstance(tool_name, str) or tool_name == "":
            continue
        if enabled_tool_names is not None and tool_name not in enabled_tool_names:
            continue
        if tool_name in executors:
            raise ValueError(f"Duplicate tool name: {tool_name}")

        tool_defs.append(tool_def)
        executors[tool_name] = executor

    if enabled_tool_names is not None:
        missing = sorted(enabled_tool_names - executors.keys())
        if missing:
            raise ValueError(f"Configured tools not found: {', '.join(missing)}")

    return tool_defs, executors


def load_configured_tools() -> tuple[list[dict[str, Any]], dict[str, Callable[..., Any]]]:
    enabled_tool_names = parse_enabled_tool_names(
        os.environ.get("CLAUDRIEL_AGENT_TOOLS"),
    )
    return discover_tools(enabled_tool_names=enabled_tool_names)


class ToolRegistry:
    """Loads tool definitions once per instance (no process-wide global cache)."""

    def __init__(self) -> None:
        self._tools: list[dict[str, Any]] | None = None
        self._executors: dict[str, Callable[..., Any]] | None = None

    def reset(self) -> None:
        """Clear cached tools (for tests)."""
        self._tools = None
        self._executors = None

    def _ensure_loaded(self) -> None:
        if self._tools is None:
            self._tools, self._executors = load_configured_tools()

    @property
    def tools(self) -> list[dict[str, Any]]:
        self._ensure_loaded()
        assert self._tools is not None
        return self._tools

    @property
    def executors(self) -> dict[str, Callable[..., Any]]:
        self._ensure_loaded()
        assert self._executors is not None
        return self._executors

"""Spec parity: docs/specs/agent-subprocess.md Tools table matches discover_tools()."""

from __future__ import annotations

import re
from pathlib import Path

import pytest

from claudriel_agent.tools_discovery import discover_tools


def _repo_root() -> Path:
    return Path(__file__).resolve().parent.parent.parent


def _spec_path() -> Path:
    return _repo_root() / "docs" / "specs" / "agent-subprocess.md"


def parse_tool_names_from_agent_subprocess_spec(text: str) -> set[str]:
    """Extract tool names from the Tools markdown table (first column backticks)."""
    lines = text.splitlines()
    in_tools_table = False
    names: set[str] = set()
    for line in lines:
        stripped = line.strip()
        if stripped.startswith("| Tool |") and "File" in stripped:
            in_tools_table = True
            continue
        if in_tools_table:
            if not stripped.startswith("|"):
                break
            if re.match(r"^\|\s*-+", stripped):
                continue
            cells = [c.strip() for c in stripped.split("|")]
            if len(cells) < 3:
                continue
            first = cells[1]
            m = re.match(r"`([a-z0-9_]+)`", first)
            if m and m.group(1) != "Tool":
                names.add(m.group(1))
    return names


@pytest.fixture(scope="module")
def spec_text() -> str:
    path = _spec_path()
    assert path.is_file(), (
        f"spec file must exist for parity CI: {path}. "
        "Do not skip — a missing spec masks tools table drift."
    )
    return path.read_text(encoding="utf-8")


def test_spec_tools_match_discovered_tools(spec_text: str) -> None:
    tools_dir = Path(__file__).resolve().parent.parent / "claudriel_agent" / "tools"
    tool_defs, _ = discover_tools(
        package_name="claudriel_agent.tools",
        package_path=tools_dir,
    )
    discovered = {t["name"] for t in tool_defs}
    from_spec = parse_tool_names_from_agent_subprocess_spec(spec_text)

    assert from_spec == discovered, (
        f"Tools table drift:\n"
        f"  only in spec: {sorted(from_spec - discovered)}\n"
        f"  only in code: {sorted(discovered - from_spec)}"
    )

"""Pytest configuration for claudriel_agent tests."""

from __future__ import annotations

import pytest


@pytest.fixture(autouse=True)
def _emit_strict_for_tests(monkeypatch: pytest.MonkeyPatch) -> None:
    """Reject unknown emit event names in tests (catches typos)."""
    monkeypatch.setenv("CLAUDRIEL_EMIT_STRICT", "1")


@pytest.fixture(autouse=True)
def _clear_agent_tools_allowlist(monkeypatch: pytest.MonkeyPatch) -> None:
    """Unless a test sets CLAUDRIEL_AGENT_TOOLS, load all tools (default discovery)."""
    monkeypatch.delenv("CLAUDRIEL_AGENT_TOOLS", raising=False)

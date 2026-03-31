"""Best-effort checks that the mocked agent path does not leak stderr."""

from __future__ import annotations

import sys
from io import StringIO
from unittest.mock import MagicMock

import pytest

from claudriel_agent.loop import run_agent_request
from claudriel_agent.tools_discovery import ToolRegistry


def test_mocked_success_path_stderr_empty(
    capsys: pytest.CaptureFixture[str], monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setenv("CLAUDRIEL_AGENT_TOOLS", "gmail_list")

    instance = MagicMock()
    instance.get.return_value = {"turn_limits": {"general": 25}}
    instance.close = MagicMock()
    monkeypatch.setattr("claudriel_agent.loop.PhpApiClient", lambda *a, **k: instance)

    class _FakeAnthropic:
        def __init__(self) -> None:
            self.messages = self

        def create(self, **_kwargs: object) -> object:
            class _Blk:
                type = "text"
                text = "ok"

            class _Resp:
                content = [_Blk()]

            return _Resp()

    monkeypatch.setattr("claudriel_agent.loop.anthropic.Anthropic", _FakeAnthropic)

    registry = ToolRegistry()
    registry.reset()
    buf = StringIO()
    old_out = sys.stdout
    sys.stdout = buf
    try:
        run_agent_request(
            {
                "messages": [{"role": "user", "content": "hi"}],
                "system": "s",
                "account_id": "a",
                "tenant_id": "t",
                "api_base": "http://x",
                "api_token": "t",
            },
            registry,
        )
    finally:
        sys.stdout = old_out

    assert capsys.readouterr().err == ""

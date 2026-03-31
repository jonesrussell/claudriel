"""Golden-style integration tests: mocked Anthropic + PhpApiClient, protocol invariants."""

from __future__ import annotations

import sys
from typing import Any
from unittest.mock import MagicMock

import anthropic
import pytest

from claudriel_agent.loop import run_agent_request
from claudriel_agent.protocol import assert_valid_protocol_stream
from claudriel_agent.tools_discovery import ToolRegistry


class _FakeText:
    type = "text"

    def __init__(self, text: str) -> None:
        self.text = text


class _FakeToolUse:
    type = "tool_use"

    def __init__(self, tool_use_id: str, name: str, input_data: dict[str, Any]) -> None:
        self.id = tool_use_id
        self.name = name
        self.input = input_data


class _FakeResponse:
    def __init__(self, content: list[Any]) -> None:
        self.content = content


def _patch_php_client(monkeypatch: pytest.MonkeyPatch) -> MagicMock:
    instance = MagicMock()
    instance.get.return_value = {"turn_limits": {"general": 25, "quick_lookup": 5}}
    instance.close = MagicMock()

    def _factory(*_a: Any, **_k: Any) -> MagicMock:
        return instance

    monkeypatch.setattr("claudriel_agent.loop.PhpApiClient", _factory)
    return instance


def _run_with_responses(
    monkeypatch: pytest.MonkeyPatch,
    responses: list[Any],
    request: dict[str, Any],
) -> tuple[str, str]:
    _patch_php_client(monkeypatch)

    seq = iter(responses)

    class _FakeAnthropic:
        def __init__(self) -> None:
            self.messages = self

        def create(self, **_kwargs: Any) -> Any:
            return next(seq)

    monkeypatch.setattr("claudriel_agent.loop.anthropic.Anthropic", _FakeAnthropic)
    monkeypatch.setattr("claudriel_agent.loop.time.sleep", lambda _s: None)

    registry = ToolRegistry()
    registry.reset()

    import io

    old_stdout = sys.stdout
    buf = io.StringIO()
    sys.stdout = buf
    try:
        run_agent_request(request, registry)
    finally:
        sys.stdout = old_stdout
    return buf.getvalue(), ""


def test_golden_text_only_stderr_empty(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    monkeypatch.setenv("CLAUDRIEL_AGENT_TOOLS", "gmail_list")
    resp = _FakeResponse([_FakeText("Hello from model.")])
    req = {
        "messages": [{"role": "user", "content": "hello"}],
        "system": "sys",
        "account_id": "a",
        "tenant_id": "t",
        "api_base": "http://test",
        "api_token": "tok",
    }
    out, _ = _run_with_responses(monkeypatch, [resp], req)
    events = assert_valid_protocol_stream(out)
    assert events[-1]["event"] == "done"
    assert any(
        e.get("event") == "message" and e.get("content") == "Hello from model." for e in events
    )
    captured = capsys.readouterr()
    assert captured.err == ""


def test_golden_tool_round_trip(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("CLAUDRIEL_AGENT_TOOLS", "gmail_list")
    r1 = _FakeResponse([_FakeToolUse("id1", "gmail_list", {"query": "is:unread"})])
    r2 = _FakeResponse([_FakeText("Listed.")])
    req = {
        "messages": [{"role": "user", "content": "check my inbox"}],
        "system": "sys",
        "account_id": "a",
        "tenant_id": "t",
        "api_base": "http://test",
        "api_token": "tok",
    }
    out, _ = _run_with_responses(monkeypatch, [r1, r2], req)
    events = assert_valid_protocol_stream(out)
    assert events[-1]["event"] == "done"
    tc = [e for e in events if e["event"] == "tool_call"]
    tr = [e for e in events if e["event"] == "tool_result"]
    assert len(tc) == 1 and tc[0]["tool"] == "gmail_list"
    assert len(tr) == 1 and tr[0]["tool"] == "gmail_list"


def test_golden_needs_continuation_then_done(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("CLAUDRIEL_AGENT_TOOLS", "gmail_list")
    mock_api = _patch_php_client(monkeypatch)
    mock_api.get.return_value = {"turn_limits": {"quick_lookup": 1, "general": 25}}

    r1 = _FakeResponse([_FakeToolUse("id1", "gmail_list", {})])
    req = {
        "messages": [{"role": "user", "content": "check my calendar"}],
        "system": "sys",
        "account_id": "a",
        "tenant_id": "t",
        "api_base": "http://test",
        "api_token": "tok",
    }

    class _FakeAnthropic:
        def __init__(self) -> None:
            self.messages = self

        def create(self, **_kwargs: Any) -> Any:
            return r1

    monkeypatch.setattr("claudriel_agent.loop.anthropic.Anthropic", _FakeAnthropic)

    registry = ToolRegistry()
    registry.reset()

    import io

    buf = io.StringIO()
    old_stdout = sys.stdout
    sys.stdout = buf
    try:
        run_agent_request(req, registry)
    finally:
        sys.stdout = old_stdout

    events = assert_valid_protocol_stream(buf.getvalue())
    assert any(e.get("event") == "needs_continuation" for e in events)
    assert events[-1]["event"] == "done"


def test_golden_rate_limit_retry_emits_progress(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("CLAUDRIEL_AGENT_TOOLS", "gmail_list")
    _patch_php_client(monkeypatch)
    monkeypatch.setattr("claudriel_agent.loop.time.sleep", lambda _s: None)

    class _FlakyAnthropic:
        def __init__(self) -> None:
            self.messages = self
            self._n = 0

        def create(self, **_kwargs: Any) -> Any:
            self._n += 1
            if self._n == 1:
                raise anthropic.RateLimitError(
                    message="slow down",
                    response=MagicMock(headers={}),
                    body=None,
                )
            return _FakeResponse([_FakeText("Recovered.")])

    monkeypatch.setattr("claudriel_agent.loop.anthropic.Anthropic", _FlakyAnthropic)

    req = {
        "messages": [{"role": "user", "content": "hello"}],
        "system": "sys",
        "account_id": "a",
        "tenant_id": "t",
        "api_base": "http://test",
        "api_token": "tok",
    }

    registry = ToolRegistry()
    registry.reset()

    import io

    buf = io.StringIO()
    old_stdout = sys.stdout
    sys.stdout = buf
    try:
        run_agent_request(req, registry)
    finally:
        sys.stdout = old_stdout

    events = assert_valid_protocol_stream(buf.getvalue())
    assert any(e.get("event") == "progress" for e in events)
    assert events[-1]["event"] == "done"

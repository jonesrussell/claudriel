"""Tests for JSONL protocol validation (terminal envelope, tool pairing)."""

from __future__ import annotations

import json

import pytest

from claudriel_agent.emit import AGENT_PROTOCOL_VERSION
from claudriel_agent.protocol import (
    ProtocolValidationError,
    assert_valid_protocol_stream,
    parse_jsonl_events,
    validate_protocol_events,
)


def _ev(d: dict) -> dict:
    """Minimal valid event dict including wire protocol version."""
    return {**d, "protocol": AGENT_PROTOCOL_VERSION}


def test_terminal_done_only() -> None:
    events = [_ev({"event": "message", "content": "x"}), _ev({"event": "done"})]
    validate_protocol_events(events)


def test_rejects_double_done() -> None:
    events = [_ev({"event": "done"}), _ev({"event": "done"})]
    with pytest.raises(ProtocolValidationError, match="exactly one terminal"):
        validate_protocol_events(events)


def test_rejects_done_then_message() -> None:
    events = [_ev({"event": "done"}), _ev({"event": "message", "content": "late"})]
    with pytest.raises(ProtocolValidationError, match="after terminal"):
        validate_protocol_events(events)


def test_tool_pairing_ok() -> None:
    events = [
        _ev({"event": "tool_call", "tool": "gmail_list", "args": {}}),
        _ev({"event": "tool_result", "tool": "gmail_list", "result": {}}),
        _ev({"event": "done"}),
    ]
    validate_protocol_events(events)


def test_tool_result_without_call() -> None:
    events = [
        _ev({"event": "tool_result", "tool": "gmail_list", "result": {}}),
        _ev({"event": "done"}),
    ]
    with pytest.raises(ProtocolValidationError, match="without preceding tool_call"):
        validate_protocol_events(events)


def test_message_between_call_and_result() -> None:
    events = [
        _ev({"event": "tool_call", "tool": "gmail_list", "args": {}}),
        _ev({"event": "message", "content": "oops"}),
        _ev({"event": "tool_result", "tool": "gmail_list", "result": {}}),
        _ev({"event": "done"}),
    ]
    with pytest.raises(ProtocolValidationError, match="between tool_call and tool_result"):
        validate_protocol_events(events)


def test_needs_continuation_before_done() -> None:
    events = [
        _ev({"event": "message", "content": "a"}),
        _ev({"event": "tool_call", "tool": "gmail_list", "args": {}}),
        _ev({"event": "tool_result", "tool": "gmail_list", "result": {}}),
        _ev(
            {
                "event": "needs_continuation",
                "turns_consumed": 1,
                "task_type": "general",
                "message": "m",
            },
        ),
        _ev({"event": "done"}),
    ]
    validate_protocol_events(events)


def test_assert_valid_protocol_stream() -> None:
    lines = (
        json.dumps(_ev({"event": "message", "content": "z"}))
        + "\n"
        + json.dumps(_ev({"event": "done"}))
        + "\n"
    )
    out = assert_valid_protocol_stream(lines)
    assert len(out) == 2
    assert out[-1]["event"] == "done"


def test_error_terminal() -> None:
    events = [_ev({"event": "error", "message": "bad"})]
    validate_protocol_events(events)


def test_parse_jsonl_events_skips_blank_lines() -> None:
    lines = ["", "  ", json.dumps(_ev({"event": "done"}))]
    events = parse_jsonl_events(lines)
    assert events == [_ev({"event": "done"})]


def test_rejects_missing_protocol() -> None:
    events = [{"event": "message", "content": "x"}, _ev({"event": "done"})]
    with pytest.raises(ProtocolValidationError, match="expected protocol"):
        validate_protocol_events(events)


def test_rejects_wrong_protocol() -> None:
    events = [
        {**_ev({"event": "message", "content": "x"}), "protocol": "0.9"},
        _ev({"event": "done"}),
    ]
    with pytest.raises(ProtocolValidationError, match="expected protocol"):
        validate_protocol_events(events)

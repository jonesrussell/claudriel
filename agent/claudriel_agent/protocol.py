"""JSONL protocol validation: terminal envelope, tool_call/tool_result pairing, ordering."""

from __future__ import annotations

from typing import Any

from claudriel_agent.emit import AGENT_PROTOCOL_VERSION

TERMINAL_EVENTS = frozenset({"done", "error"})


class ProtocolValidationError(ValueError):
    """Raised when stdout JSONL violates subprocess protocol invariants."""


def parse_jsonl_events(lines: list[str]) -> list[dict[str, Any]]:
    """Parse non-empty lines as JSON objects; raises json.JSONDecodeError on bad input."""
    import json

    out: list[dict[str, Any]] = []
    for line in lines:
        s = line.strip()
        if not s:
            continue
        obj = json.loads(s)
        if not isinstance(obj, dict):
            raise ProtocolValidationError(f"JSONL line must be an object, got {type(obj).__name__}")
        out.append(obj)
    return out


def validate_protocol_events(events: list[dict[str, Any]]) -> None:
    """Enforce terminal envelope, protocol version, and tool_call/tool_result ordering.

    Invariants:
    - Every event has ``protocol`` equal to ``AGENT_PROTOCOL_VERSION`` from ``emit``.
    - Exactly one terminal event (`done` or `error`) in the stream.
    - Terminal is the last event; nothing follows.
    - `progress` and `needs_continuation` never appear after the terminal.
    - After `tool_call`, only `tool_result` is allowed until paired (same tool name).
    - Multiple `message` events may appear in sequence when idle.
    """
    if not events:
        raise ProtocolValidationError("empty event stream")

    terminal_indices: list[int] = []
    stack: list[str] = []
    after_tool_call = False

    for i, ev in enumerate(events):
        et = ev.get("event")
        if not isinstance(et, str):
            raise ProtocolValidationError(f"event {i}: missing or invalid 'event' field")

        proto = ev.get("protocol")
        if proto != AGENT_PROTOCOL_VERSION:
            raise ProtocolValidationError(
                f"event {i}: expected protocol {AGENT_PROTOCOL_VERSION!r}, got {proto!r}",
            )

        if et in TERMINAL_EVENTS:
            terminal_indices.append(i)
            if stack:
                raise ProtocolValidationError(
                    f"event {i}: terminal {et!r} while tool_call stack non-empty: {stack!r}",
                )
            if after_tool_call:
                raise ProtocolValidationError(
                    f"event {i}: terminal {et!r} before matching tool_result",
                )
            # Nothing may follow terminal — checked after loop
            continue

        if terminal_indices:
            raise ProtocolValidationError(
                f"event {i}: {et!r} appears after terminal {events[terminal_indices[0]].get('event')!r}",
            )

        if et == "tool_call":
            if after_tool_call:
                raise ProtocolValidationError(
                    f"event {i}: nested tool_call before tool_result for {stack[-1]!r}",
                )
            tool = ev.get("tool")
            if not isinstance(tool, str) or not tool:
                raise ProtocolValidationError(f"event {i}: tool_call missing string 'tool'")
            stack.append(tool)
            after_tool_call = True

        elif et == "tool_result":
            if not after_tool_call:
                raise ProtocolValidationError(f"event {i}: tool_result without preceding tool_call")
            tool = ev.get("tool")
            if not isinstance(tool, str) or not tool:
                raise ProtocolValidationError(f"event {i}: tool_result missing string 'tool'")
            expected = stack.pop()
            if tool != expected:
                raise ProtocolValidationError(
                    f"event {i}: tool_result for {tool!r} does not match pending tool_call {expected!r}",
                )
            after_tool_call = False

        elif et in ("message", "progress", "needs_continuation"):
            if after_tool_call:
                raise ProtocolValidationError(
                    f"event {i}: {et!r} between tool_call and tool_result",
                )
        else:
            # Unknown event type — strict CI uses CLAUDRIEL_EMIT_STRICT; validator is permissive
            if after_tool_call:
                raise ProtocolValidationError(
                    f"event {i}: unknown event {et!r} between tool_call and tool_result",
                )

    if len(terminal_indices) != 1:
        raise ProtocolValidationError(
            f"expected exactly one terminal event (done|error), found {len(terminal_indices)}",
        )
    if terminal_indices[0] != len(events) - 1:
        raise ProtocolValidationError("terminal event must be the last line in the stream")
    if stack:
        raise ProtocolValidationError(f"unclosed tool_call(s) at end of stream: {stack!r}")


def assert_valid_protocol_stream(stdout_text: str) -> list[dict[str, Any]]:
    """Parse stdout and validate; returns parsed events for assertions."""
    lines = stdout_text.splitlines()
    events = parse_jsonl_events(lines)
    validate_protocol_events(events)
    return events

"""Tests for turn tracking, task type classification, and tool result truncation."""

import pytest

from claudriel_agent.constants import (
    DEFAULT_TURN_LIMITS,
    GMAIL_BODY_MAX_CHARS,
    TOOL_RESULT_MAX_CHARS,
)
from claudriel_agent.loop import LoopState, classify_task_type, truncate_tool_result


def test_classify_email():
    messages = [{"role": "user", "content": "send an email to Bob"}]
    assert classify_task_type(messages) == "email_compose"


def test_classify_brief():
    messages = [{"role": "user", "content": "give me my morning brief"}]
    assert classify_task_type(messages) == "brief_generation"


def test_classify_quick():
    messages = [{"role": "user", "content": "check my calendar"}]
    assert classify_task_type(messages) == "quick_lookup"


def test_classify_research():
    messages = [{"role": "user", "content": "research competitor pricing"}]
    assert classify_task_type(messages) == "research"


def test_classify_general():
    messages = [{"role": "user", "content": "hello how are you"}]
    assert classify_task_type(messages) == "general"


def test_default_turn_limits_has_all_types():
    expected_keys = {
        "quick_lookup",
        "email_compose",
        "brief_generation",
        "research",
        "general",
        "onboarding",
    }
    assert set(DEFAULT_TURN_LIMITS.keys()) == expected_keys


def test_truncate_tool_result_idempotent_for_gmail_read():
    body = "x" * (GMAIL_BODY_MAX_CHARS + 100)
    result = {"id": "1", "body": body}
    a = truncate_tool_result("gmail_read", result)
    b = truncate_tool_result("gmail_read", result)
    assert a == b
    assert "[truncated]" in a
    assert len(a) < len(body) + 200


def test_truncate_tool_result_idempotent_generic_overflow():
    big = {"data": "y" * (TOOL_RESULT_MAX_CHARS + 500)}
    a = truncate_tool_result("search_global", big)
    b = truncate_tool_result("search_global", big)
    assert a == b
    assert a.endswith(" [truncated]")


def test_truncate_small_payload_unchanged():
    small = {"ok": True}
    a = truncate_tool_result("gmail_list", small)
    b = truncate_tool_result("gmail_list", small)
    assert a == b
    assert "truncated" not in a.lower()


def test_loop_state_is_frozen():
    s = LoopState(
        turns_consumed=1,
        turn_limit=5,
        task_type="general",
        continuation_emitted=False,
    )
    assert s.turns_consumed == 1
    with pytest.raises(AttributeError):
        s.turns_consumed = 2  # type: ignore[misc]

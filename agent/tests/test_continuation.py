"""Tests for turn tracking and task type classification."""

from claudriel_agent.constants import DEFAULT_TURN_LIMITS
from claudriel_agent.loop import classify_task_type


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

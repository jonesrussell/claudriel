"""Tests for agent tool definitions and functions."""

from unittest.mock import MagicMock

from tools.calendar_create import TOOL_DEF as CALENDAR_CREATE_DEF
from tools.calendar_create import execute as calendar_create_run
from tools.calendar_list import TOOL_DEF as CALENDAR_LIST_DEF
from tools.calendar_list import execute as calendar_list_run
from tools.gmail_list import TOOL_DEF as GMAIL_LIST_DEF
from tools.gmail_list import execute as gmail_list_run
from tools.gmail_read import TOOL_DEF as GMAIL_READ_DEF
from tools.gmail_read import execute as gmail_read_run
from tools.gmail_send import TOOL_DEF as GMAIL_SEND_DEF
from tools.gmail_send import execute as gmail_send_run
from tools.pipeline_fetch_leads import TOOL_DEF as PIPELINE_FETCH_DEF
from tools.pipeline_fetch_leads import execute as pipeline_fetch_run
from tools.prospect_list import TOOL_DEF as PROSPECT_LIST_DEF
from tools.prospect_list import execute as prospect_list_run
from tools.prospect_update import TOOL_DEF as PROSPECT_UPDATE_DEF
from tools.prospect_update import execute as prospect_update_run

# -----------------------------------------------------------------------
# Tool definitions have required fields
# -----------------------------------------------------------------------


def test_all_tool_defs_have_name_and_schema():
    for tool in [
        GMAIL_LIST_DEF,
        GMAIL_READ_DEF,
        GMAIL_SEND_DEF,
        CALENDAR_LIST_DEF,
        CALENDAR_CREATE_DEF,
        PROSPECT_LIST_DEF,
        PROSPECT_UPDATE_DEF,
        PIPELINE_FETCH_DEF,
    ]:
        assert "name" in tool
        assert "input_schema" in tool
        assert "description" in tool


def test_tool_names_are_unique():
    names = [
        t["name"]
        for t in [
            GMAIL_LIST_DEF,
            GMAIL_READ_DEF,
            GMAIL_SEND_DEF,
            CALENDAR_LIST_DEF,
            CALENDAR_CREATE_DEF,
            PROSPECT_LIST_DEF,
            PROSPECT_UPDATE_DEF,
            PIPELINE_FETCH_DEF,
        ]
    ]
    assert len(names) == len(set(names))


# -----------------------------------------------------------------------
# gmail_list
# -----------------------------------------------------------------------


def test_gmail_list_calls_correct_endpoint():
    api = MagicMock()
    api.get.return_value = {"messages": []}

    result = gmail_list_run(api, {"query": "from:alice", "max_results": 5})

    api.get.assert_called_once_with(
        "/api/internal/gmail/list",
        params={
            "q": "from:alice",
            "max_results": 5,
        },
    )
    assert result == {"messages": []}


def test_gmail_list_uses_defaults():
    api = MagicMock()
    api.get.return_value = {}

    gmail_list_run(api, {})

    api.get.assert_called_once_with(
        "/api/internal/gmail/list",
        params={
            "q": "is:unread",
            "max_results": 10,
        },
    )


# -----------------------------------------------------------------------
# gmail_read
# -----------------------------------------------------------------------


def test_gmail_read_calls_correct_endpoint():
    api = MagicMock()
    api.get.return_value = {"id": "msg-1", "snippet": "Hello"}

    gmail_read_run(api, {"message_id": "msg-1"})

    api.get.assert_called_once()
    call_args = api.get.call_args
    assert "/api/internal/gmail/read" in call_args[0][0]


# -----------------------------------------------------------------------
# gmail_send
# -----------------------------------------------------------------------


def test_gmail_send_calls_post():
    api = MagicMock()
    api.post.return_value = {"id": "sent-1"}

    gmail_send_run(
        api,
        {
            "to": "bob@example.com",
            "subject": "Hi",
            "body": "Hello Bob",
        },
    )

    api.post.assert_called_once()
    call_args = api.post.call_args
    assert call_args[0][0] == "/api/internal/gmail/send"


# -----------------------------------------------------------------------
# calendar_list
# -----------------------------------------------------------------------


def test_calendar_list_calls_correct_endpoint():
    api = MagicMock()
    api.get.return_value = {"items": []}

    calendar_list_run(api, {"days_ahead": 3})

    api.get.assert_called_once()
    call_args = api.get.call_args
    assert call_args[0][0] == "/api/internal/calendar/list"


# -----------------------------------------------------------------------
# calendar_create
# -----------------------------------------------------------------------


def test_calendar_create_sends_required_fields():
    api = MagicMock()
    api.post.return_value = {"id": "evt-1"}

    calendar_create_run(
        api,
        {
            "title": "Standup",
            "start_time": "2026-03-18T09:00:00-04:00",
            "end_time": "2026-03-18T09:30:00-04:00",
        },
    )

    api.post.assert_called_once()
    call_args = api.post.call_args
    assert call_args[0][0] == "/api/internal/calendar/create"
    payload = (
        call_args[1]["json_data"] if "json_data" in call_args[1] else call_args[0][1]
    )
    assert payload["title"] == "Standup"


def test_prospect_list_calls_endpoint():
    api = MagicMock()
    api.get.return_value = {"prospects": []}

    prospect_list_run(api, {"workspace_uuid": "ws-1", "limit": 10})

    api.get.assert_called_once_with(
        "/api/internal/prospects/list",
        params={
            "workspace_uuid": "ws-1",
            "limit": 10,
        },
    )


def test_pipeline_fetch_leads_posts():
    api = MagicMock()
    api.post.return_value = {"imported": 0}

    pipeline_fetch_run(api, {"workspace_uuid": "ws-2"})

    api.post.assert_called_once_with(
        "/api/internal/pipeline/fetch-leads",
        json_data={"workspace_uuid": "ws-2"},
    )


def test_calendar_create_parses_attendees():
    api = MagicMock()
    api.post.return_value = {"id": "evt-2"}

    calendar_create_run(
        api,
        {
            "title": "Meeting",
            "start_time": "2026-03-18T10:00:00-04:00",
            "end_time": "2026-03-18T11:00:00-04:00",
            "attendees": "alice@ex.com, bob@ex.com",
        },
    )

    payload = (
        api.post.call_args[1]["json_data"]
        if "json_data" in api.post.call_args[1]
        else api.post.call_args[0][1]
    )
    assert payload["attendees"] == ["alice@ex.com", "bob@ex.com"]

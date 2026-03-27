"""Tests for the emit() function and JSON-lines contract."""

import io
import json
import sys

from main import emit


def test_emit_writes_json_line_to_stdout(capsys):
    emit("message", content="Hello")
    captured = capsys.readouterr()
    line = json.loads(captured.out.strip())
    assert line["event"] == "message"
    assert line["content"] == "Hello"


def test_emit_done_event(capsys):
    emit("done")
    captured = capsys.readouterr()
    line = json.loads(captured.out.strip())
    assert line["event"] == "done"


def test_emit_error_event(capsys):
    emit("error", message="Something went wrong")
    captured = capsys.readouterr()
    line = json.loads(captured.out.strip())
    assert line["event"] == "error"
    assert line["message"] == "Something went wrong"


def test_emit_tool_call_event(capsys):
    emit("tool_call", tool="gmail_list", args={"query": "is:unread"})
    captured = capsys.readouterr()
    line = json.loads(captured.out.strip())
    assert line["event"] == "tool_call"
    assert line["tool"] == "gmail_list"
    assert line["args"] == {"query": "is:unread"}


def test_emit_preserves_unicode(capsys):
    emit("message", content="Café résumé")
    captured = capsys.readouterr()
    line = json.loads(captured.out.strip())
    assert line["content"] == "Café résumé"

#!/usr/bin/env python3
"""Claudriel agent entrypoint.

Reads a JSON request from stdin, runs an agentic tool-use loop via the
Anthropic API, and writes JSON-lines events to stdout.

Usage:
    echo '{"messages": [...], "system": "...", ...}' | python agent/main.py
"""

import json
import sys

import anthropic

from tools.gmail_list import TOOL_DEF as GMAIL_LIST_DEF, execute as gmail_list_exec
from tools.gmail_read import TOOL_DEF as GMAIL_READ_DEF, execute as gmail_read_exec
from tools.gmail_send import TOOL_DEF as GMAIL_SEND_DEF, execute as gmail_send_exec
from tools.calendar_list import TOOL_DEF as CALENDAR_LIST_DEF, execute as calendar_list_exec
from tools.calendar_create import TOOL_DEF as CALENDAR_CREATE_DEF, execute as calendar_create_exec
from tools.commitment_list import TOOL_DEF as COMMITMENT_LIST_DEF, execute as commitment_list_exec
from tools.commitment_update import TOOL_DEF as COMMITMENT_UPDATE_DEF, execute as commitment_update_exec
from tools.person_search import TOOL_DEF as PERSON_SEARCH_DEF, execute as person_search_exec
from tools.person_detail import TOOL_DEF as PERSON_DETAIL_DEF, execute as person_detail_exec
from tools.brief_generate import TOOL_DEF as BRIEF_GENERATE_DEF, execute as brief_generate_exec
from tools.event_search import TOOL_DEF as EVENT_SEARCH_DEF, execute as event_search_exec
from tools.search_global import TOOL_DEF as SEARCH_GLOBAL_DEF, execute as search_global_exec
from tools.workspace_list import TOOL_DEF as WORKSPACE_LIST_DEF, execute as workspace_list_exec
from tools.workspace_context import TOOL_DEF as WORKSPACE_CONTEXT_DEF, execute as workspace_context_exec
from tools.schedule_query import TOOL_DEF as SCHEDULE_QUERY_DEF, execute as schedule_query_exec
from tools.triage_list import TOOL_DEF as TRIAGE_LIST_DEF, execute as triage_list_exec
from tools.triage_resolve import TOOL_DEF as TRIAGE_RESOLVE_DEF, execute as triage_resolve_exec
from util.http import PhpApiClient

TOOLS = [GMAIL_LIST_DEF, GMAIL_READ_DEF, GMAIL_SEND_DEF, CALENDAR_LIST_DEF, CALENDAR_CREATE_DEF, COMMITMENT_LIST_DEF, COMMITMENT_UPDATE_DEF, PERSON_SEARCH_DEF, PERSON_DETAIL_DEF, BRIEF_GENERATE_DEF, EVENT_SEARCH_DEF, SEARCH_GLOBAL_DEF, WORKSPACE_LIST_DEF, WORKSPACE_CONTEXT_DEF, SCHEDULE_QUERY_DEF, TRIAGE_LIST_DEF, TRIAGE_RESOLVE_DEF]

EXECUTORS = {
    "gmail_list": gmail_list_exec,
    "gmail_read": gmail_read_exec,
    "gmail_send": gmail_send_exec,
    "calendar_list": calendar_list_exec,
    "calendar_create": calendar_create_exec,
    "commitment_list": commitment_list_exec,
    "commitment_update": commitment_update_exec,
    "person_search": person_search_exec,
    "person_detail": person_detail_exec,
    "brief_generate": brief_generate_exec,
    "event_search": event_search_exec,
    "search_global": search_global_exec,
    "workspace_list": workspace_list_exec,
    "workspace_context": workspace_context_exec,
    "schedule_query": schedule_query_exec,
    "triage_list": triage_list_exec,
    "triage_resolve": triage_resolve_exec,
}

DEFAULT_TURN_LIMITS = {
    "quick_lookup": 5,
    "email_compose": 15,
    "brief_generation": 10,
    "research": 40,
    "general": 25,
    "onboarding": 30,
}


def classify_task_type(messages: list) -> str:
    """Classify task type from first user message."""
    first_msg = ""
    for msg in messages:
        if msg.get("role") == "user":
            content = msg.get("content", "")
            if isinstance(content, str):
                first_msg = content.lower()
            break

    if any(w in first_msg for w in ["send", "email", "reply", "compose", "draft"]):
        return "email_compose"
    if any(w in first_msg for w in ["brief", "summary", "morning", "digest"]):
        return "brief_generation"
    if any(w in first_msg for w in ["check", "what time", "calendar", "schedule", "who is"]):
        return "quick_lookup"
    if any(w in first_msg for w in ["research", "find out", "look into", "analyze"]):
        return "research"
    return "general"


def emit(event: str, **kwargs) -> None:
    """Write a JSON-line event to stdout."""
    line = json.dumps({"event": event, **kwargs}, ensure_ascii=False)
    print(line, flush=True)


def main() -> None:
    try:
        request = json.load(sys.stdin)
    except json.JSONDecodeError as e:
        emit("error", message=f"Invalid JSON input: {e}")
        sys.exit(1)

    messages = request.get("messages", [])
    system_prompt = request.get("system", "")
    account_id = request.get("account_id", "")
    api_base = request.get("api_base", "http://localhost:8000")
    api_token = request.get("api_token", "")
    model = request.get("model", "claude-sonnet-4-6")

    api = PhpApiClient(api_base, api_token, account_id)
    client = anthropic.Anthropic()

    # Fetch turn limits from session endpoint, fall back to defaults
    try:
        limits_response = api.get("/api/internal/session/limits")
        turn_limits = limits_response.get("turn_limits", DEFAULT_TURN_LIMITS)
    except Exception:
        turn_limits = DEFAULT_TURN_LIMITS

    task_type = classify_task_type(messages)
    turn_limit = turn_limits.get(task_type, turn_limits.get("general", 25))

    # Support continuation: fresh budget on continued requests
    if request.get("continued", False):
        turn_limit = turn_limits.get(task_type, turn_limits.get("general", 25))

    turns_consumed = 0

    try:
        for _ in range(turn_limit):
            turns_consumed += 1

            response = client.messages.create(
                model=model,
                max_tokens=4096,
                system=system_prompt,
                messages=messages,
                tools=TOOLS,
            )

            # Collect text and tool_use blocks from the response
            text_parts = []
            tool_calls = []

            for block in response.content:
                if block.type == "text":
                    text_parts.append(block.text)
                elif block.type == "tool_use":
                    tool_calls.append(block)

            # Emit any text content
            if text_parts:
                combined = "".join(text_parts)
                emit("message", content=combined)

            # If no tool calls, we're done
            if not tool_calls:
                break

            # Append assistant message to history
            messages.append({"role": "assistant", "content": response.content})

            # Execute each tool call and collect results
            tool_results = []
            for tool_call in tool_calls:
                emit("tool_call", tool=tool_call.name, args=tool_call.input)

                executor = EXECUTORS.get(tool_call.name)
                if executor is None:
                    result = {"error": f"Unknown tool: {tool_call.name}"}
                else:
                    try:
                        result = executor(api, tool_call.input)
                    except Exception as e:
                        result = {"error": str(e)}

                emit("tool_result", tool=tool_call.name, result=result)
                tool_results.append({
                    "type": "tool_result",
                    "tool_use_id": tool_call.id,
                    "content": json.dumps(result, ensure_ascii=False),
                })

            # Check if approaching limit and still have tool calls
            if turns_consumed >= turn_limit - 1 and tool_calls:
                emit("needs_continuation",
                     turns_consumed=turns_consumed,
                     task_type=task_type,
                     message="I need more turns to complete this task. Continue?")
                break

            # Append tool results and loop
            messages.append({"role": "user", "content": tool_results})

        emit("done")

    except Exception as e:
        print(f"Agent error: {e}", file=sys.stderr)
        emit("error", message=str(e))
        sys.exit(1)
    finally:
        api.close()


if __name__ == "__main__":
    main()

"""Anthropic tool-use loop: rate limits, tool execution, continuation hints."""

from __future__ import annotations

import json
import sys
import time
from typing import Any

import anthropic

from claudriel_agent.constants import (
    DEFAULT_TURN_LIMITS,
    GMAIL_BODY_MAX_CHARS,
    MODEL_DEGRADATION,
    MODEL_ESCALATION,
    RATE_LIMIT_INITIAL_BACKOFF,
    RATE_LIMIT_MAX_BACKOFF,
    RATE_LIMIT_MAX_RETRIES,
    TOOL_RESULT_MAX_CHARS,
)
from claudriel_agent.emit import emit
from claudriel_agent.tools_discovery import ToolRegistry
from claudriel_agent.util.http import PhpApiClient


def classify_task_type(messages: list[dict[str, Any]]) -> str:
    """Classify task type from first user message (keyword heuristics for turn limits).

    These keywords tune ``turn_limit`` from the session endpoint; they are not
    semantic classification for the model.
    """
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


def truncate_tool_result(tool_name: str, result: dict[str, Any]) -> str:
    """Truncate a tool result for conversation history to control token growth."""
    result_json = json.dumps(result, ensure_ascii=False)

    if tool_name == "gmail_read":
        # Gmail bodies are the biggest offender; truncate the body field
        truncated = dict(result)
        body = truncated.get("body")
        if isinstance(body, str) and len(body) > GMAIL_BODY_MAX_CHARS:
            truncated["body"] = body[:GMAIL_BODY_MAX_CHARS] + " [truncated]"
        return json.dumps(truncated, ensure_ascii=False)

    if len(result_json) > TOOL_RESULT_MAX_CHARS:
        return result_json[:TOOL_RESULT_MAX_CHARS] + " [truncated]"

    return result_json


def build_cached_tools(tools: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Add cache_control to the last tool definition for prompt caching."""
    if not tools:
        return tools
    cached = [dict(t) for t in tools]
    cached[-1] = dict(cached[-1])
    cached[-1]["cache_control"] = {"type": "ephemeral"}
    return cached


def run_agent_request(request: dict[str, Any], registry: ToolRegistry) -> None:
    """Run one agent session from a parsed stdin JSON object."""
    messages: list[Any] = list(request.get("messages", []))
    system_prompt = str(request.get("system", ""))
    account_id = str(request.get("account_id", ""))
    tenant_id = str(request.get("tenant_id", ""))
    api_base = str(request.get("api_base", "http://localhost:8000"))
    api_token = str(request.get("api_token", ""))
    model = str(request.get("model", "claude-sonnet-4-6"))

    api = PhpApiClient(api_base, api_token, account_id, tenant_id)
    client = anthropic.Anthropic()

    try:
        # Fetch turn limits from session endpoint, fall back to defaults
        try:
            limits_response = api.get("/api/internal/session/limits")
            raw_limits = limits_response.get("turn_limits", DEFAULT_TURN_LIMITS)
            turn_limits: dict[str, int] = (
                dict(raw_limits) if isinstance(raw_limits, dict) else dict(DEFAULT_TURN_LIMITS)
            )
        except Exception:
            turn_limits = dict(DEFAULT_TURN_LIMITS)

        task_type = classify_task_type(messages)
        turn_limit = turn_limits.get(task_type, turn_limits.get("general", 25))

        # Support continuation: fresh budget on continued requests
        if request.get("continued", False):
            turn_limit = turn_limits.get(task_type, turn_limits.get("general", 25))

        turns_consumed = 0
        cached_tools = build_cached_tools(registry.tools)
        cached_system = [
            {"type": "text", "text": system_prompt, "cache_control": {"type": "ephemeral"}}
        ]

        for _ in range(turn_limit):
            turns_consumed += 1

            response = None
            current_model = model
            for attempt in range(RATE_LIMIT_MAX_RETRIES + 1):
                try:
                    response = client.messages.create(
                        model=current_model,
                        max_tokens=4096,
                        system=cached_system,  # type: ignore[arg-type]
                        messages=messages,
                        tools=cached_tools,  # type: ignore[arg-type]
                    )
                    break
                except anthropic.RateLimitError as e:
                    if attempt >= RATE_LIMIT_MAX_RETRIES:
                        # Retries exhausted: try degrading to a lower-tier model
                        fallback = MODEL_DEGRADATION.get(current_model)
                        if fallback:
                            emit(
                                "progress",
                                phase="fallback",
                                summary=(
                                    f"Rate limit exhausted on {current_model}, "
                                    f"falling back to {fallback}"
                                ),
                                level="warning",
                            )
                            current_model = fallback
                            continue
                        raise
                    retry_after = getattr(e.response, "headers", {}).get("retry-after")
                    try:
                        wait = (
                            min(float(retry_after), RATE_LIMIT_MAX_BACKOFF)
                            if retry_after is not None
                            else None
                        )
                    except (ValueError, TypeError):
                        wait = None
                    if wait is None:
                        wait = min(
                            RATE_LIMIT_INITIAL_BACKOFF * (2**attempt),
                            RATE_LIMIT_MAX_BACKOFF,
                        )
                    emit(
                        "progress",
                        phase="rate_limit",
                        summary=(f"Rate limited on {current_model}, retrying in {int(wait)}s..."),
                        level="warning",
                    )
                    time.sleep(wait)
                except anthropic.APIError as api_err:
                    # API error (not rate limit): try escalating to a higher-tier model
                    fallback = MODEL_ESCALATION.get(current_model)
                    if fallback:
                        emit(
                            "progress",
                            phase="fallback",
                            summary=(
                                f"API error on {current_model}, escalating to {fallback}: "
                                f"{api_err}"
                            ),
                            level="warning",
                        )
                        current_model = fallback
                        continue
                    raise

            if response is None:
                emit(
                    "error",
                    message="Failed to get API response after retries and fallbacks",
                )
                break

            # Collect text and tool_use blocks from the response
            text_parts: list[str] = []
            tool_calls: list[Any] = []

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
            tool_results: list[dict[str, Any]] = []
            executors = registry.executors
            for tool_call in tool_calls:
                emit("tool_call", tool=tool_call.name, args=tool_call.input)

                executor = executors.get(tool_call.name)
                if executor is None:
                    result: dict[str, Any] = {"error": f"Unknown tool: {tool_call.name}"}
                else:
                    try:
                        result = executor(api, tool_call.input)
                    except Exception as e:
                        result = {"error": str(e)}

                emit("tool_result", tool=tool_call.name, result=result)
                tool_results.append(
                    {
                        "type": "tool_result",
                        "tool_use_id": tool_call.id,
                        "content": truncate_tool_result(tool_call.name, result),
                    }
                )

            # Check if approaching limit and still have tool calls
            if turns_consumed >= turn_limit - 1 and tool_calls:
                emit(
                    "needs_continuation",
                    turns_consumed=turns_consumed,
                    task_type=task_type,
                    message="I need more turns to complete this task. Continue?",
                )
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

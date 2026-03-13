import logging
import os
from collections.abc import AsyncIterator
from dataclasses import dataclass
from pathlib import Path

from claude_agent_sdk import AssistantMessage, ClaudeAgentOptions, ResultMessage, TextBlock, query

logger = logging.getLogger(__name__)

# Prefer the system-installed Claude CLI over the bundled one.
# The bundled CLI may not share the host's OAuth credentials.
CLAUDE_CLI_PATH: str | None = None
for _candidate in ["/usr/local/bin/claude", "/usr/bin/claude"]:
    if Path(_candidate).is_file():
        CLAUDE_CLI_PATH = _candidate
        break


@dataclass
class TokenEvent:
    text: str


@dataclass
class DoneEvent:
    full_text: str


@dataclass
class ErrorEvent:
    error: str


@dataclass
class ProgressEvent:
    phase: str
    summary: str
    level: str = "info"


StreamEvent = TokenEvent | DoneEvent | ErrorEvent | ProgressEvent

# Restrict Claude to Gmail, Calendar, and Bash (for curl ingestion) tools.
# No file system or code editing access.
ALLOWED_TOOLS = [
    "mcp__claude_ai_Gmail__*",
    "mcp__claude_ai_Google_Calendar__*",
    "Bash",
]


async def stream_chat(
    system_prompt: str,
    messages: list[dict[str, str]],
) -> AsyncIterator[StreamEvent]:
    """Send messages to Claude Code SDK and yield streaming events."""
    model = os.environ.get("CLAUDE_MODEL", "claude-sonnet-4-6")

    prompt = _format_messages(messages)

    options = ClaudeAgentOptions(
        system_prompt=system_prompt,
        model=model,
        max_turns=25,
        allowed_tools=ALLOWED_TOOLS,
        cli_path=CLAUDE_CLI_PATH,
    )

    full_text = ""
    yielded_response_progress = False

    try:
        yield ProgressEvent(phase="prepare", summary="Preparing Claude Code session")
        logger.info("Starting query with model=%s, prompt_len=%d", model, len(prompt))
        yield ProgressEvent(phase="connect", summary="Connecting Claude Code tools")
        async for message in query(prompt=prompt, options=options):
            msg_type = type(message).__name__
            logger.info("Received message type: %s", msg_type)
            if isinstance(message, AssistantMessage):
                for block in message.content:
                    if isinstance(block, TextBlock):
                        if not yielded_response_progress:
                            yielded_response_progress = True
                            yield ProgressEvent(phase="respond", summary="Streaming Claude response")
                        logger.info("TextBlock: %s...", block.text[:80])
                        full_text += block.text
                        yield TokenEvent(text=block.text)
                    else:
                        logger.info("Skipping block type: %s", type(block).__name__)
                        progress_event = _progress_event_from_block(block)
                        if progress_event is not None:
                            yield progress_event
            elif isinstance(message, ResultMessage):
                logger.info("ResultMessage received (query complete)")
                yield ProgressEvent(phase="finalize", summary="Finalizing Claude response")

        yield DoneEvent(full_text=full_text)
        logger.info("Stream complete, full_text_len=%d", len(full_text))

    except Exception as e:
        logger.error("Stream error: %s", e, exc_info=True)
        yield ErrorEvent(error=str(e))


def _format_messages(messages: list[dict[str, str]]) -> str:
    """Format conversation history as a prompt string for the SDK."""
    if not messages:
        return ""

    if len(messages) == 1:
        return messages[0]["content"]

    parts = []
    for msg in messages[:-1]:
        role = "User" if msg["role"] == "user" else "Assistant"
        parts.append(f"{role}: {msg['content']}")

    parts.append(f"\nUser: {messages[-1]['content']}")
    return "\n".join(parts)


def _progress_event_from_block(block: object) -> ProgressEvent | None:
    tool_name = _extract_tool_name(block)
    if tool_name:
        return ProgressEvent(
            phase="tool",
            summary=_tool_summary(tool_name),
        )

    block_type = type(block).__name__.lower()
    if "tool" in block_type:
        return ProgressEvent(
            phase="tool",
            summary="Using Claude Code tools",
        )

    return None


def _extract_tool_name(block: object) -> str | None:
    for attr in ("name", "tool_name"):
        value = getattr(block, attr, None)
        if isinstance(value, str) and value.strip():
            return value.strip()

    return None


def _tool_summary(tool_name: str) -> str:
    normalized = tool_name.lower()
    if "gmail" in normalized:
        return "Checking Gmail context"
    if "calendar" in normalized:
        return "Checking calendar context"
    if normalized == "bash" or normalized.endswith("__bash"):
        return "Running an allowlisted shell command"

    return "Using Claude Code tools"

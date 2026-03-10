# Agent Sidecar Design

## Summary

A Python FastAPI sidecar service wrapping the Claude Agent SDK to give Claudriel's chat interface access to Gmail and Google Calendar via Claude Code's authenticated MCP connectors.

## Problem

Claudriel's chat calls the Anthropic Messages API directly, which has no access to Gmail, Calendar, or other MCP tools. The claude.ai-hosted MCP connectors (Gmail, Calendar) are tied to Claude Code's authentication. Rather than implementing Google OAuth separately, the sidecar wraps the Claude CLI (which is already authenticated) to inherit those capabilities.

## Architecture

```
Browser <--SSE--> Claudriel (PHP)
                      |
                POST /chat, GET /chat/{id}/stream
                      |
                  Sidecar (Python FastAPI)
                      |
                  ClaudeSDKClient (long-running subprocess)
                      |
                  Claude CLI (authenticated with claude.ai)
                      |
                  Anthropic-hosted MCP
                      |
                  Gmail + Google Calendar
```

## Sidecar Service

### Technology

- Python 3.11+
- FastAPI + Uvicorn
- `claude-code-sdk` (Python SDK for Claude Code, spawns CLI as subprocess)

### API Surface

#### POST /chat

Request:
```json
{
  "session_id": "string (Claudriel ChatSession UUID)",
  "system_prompt": "string (built by PHP ChatSystemPromptBuilder)",
  "messages": [
    {"role": "user", "content": "check my email"},
    {"role": "assistant", "content": "..."}
  ]
}
```

Response: SSE stream matching Claudriel's existing frontend event format:
```
event: chat-token
data: {"token": "You have"}

event: chat-token
data: {"token": " 3 unread"}

event: chat-done
data: {"text": "You have 3 unread emails..."}
```

Error events:
```
event: chat-error
data: {"error": "Sidecar session timed out"}
```

Heartbeat (every 15s during tool execution pauses):
```
: heartbeat
```

#### DELETE /chat/{session_id}

Explicitly closes a session and cleans up the `ClaudeSDKClient` instance.

Response: `204 No Content`

### Authentication

- Shared API key via `CLAUDRIEL_SIDECAR_KEY` environment variable
- Checked on every request via `Authorization: Bearer <key>` header
- Sidecar only listens on Docker internal network (not exposed to host)

### Session Management

- **Lazy initialization**: First message for a session_id spawns a new `ClaudeSDKClient`
- **Reuse**: Subsequent messages for the same session_id reuse the existing client
- **Timeout cleanup**: Inactive sessions cleaned up after configurable timeout (default 15 minutes)
- **Explicit cleanup**: DELETE endpoint for immediate teardown
- **Concurrency**: Multiple sessions supported simultaneously (one `ClaudeSDKClient` per session)

### Configuration

Environment variables:
- `CLAUDRIEL_SIDECAR_KEY` — shared auth key with Claudriel
- `SESSION_TIMEOUT_MINUTES` — inactivity timeout (default: 15)
- `SIDECAR_PORT` — listen port (default: 8100)
- `CLAUDE_MODEL` — model to use (default: claude-sonnet-4-6)

Note: Claude Code authenticates via OAuth to claude.ai, not via API key. The `~/.claude/` directory from the host must be mounted into the container for auth tokens.

## Changes to Claudriel (PHP)

### Modified

- **`AnthropicChatClient`** — replace direct Anthropic API calls with HTTP calls to the sidecar. Or create a new `SidecarChatClient` and swap via service provider.
- **`ChatStreamController`** — read SSE from sidecar and pipe to browser, instead of generating SSE from Anthropic API directly.
- **`ClaudrielServiceProvider`** — wire new client, add sidecar config.

### Unchanged

- **`ChatSystemPromptBuilder`** — still builds the system prompt in PHP, sends it to sidecar as part of the request.
- **Chat entities** (`ChatSession`, `ChatMessage`) — PHP continues to own conversation storage.
- **Frontend** — still receives SSE from Claudriel, no changes needed.

## Docker Integration

New service in `docker-compose.yml`:

```yaml
sidecar:
  build:
    context: ./docker/sidecar
  environment:
    - CLAUDRIEL_SIDECAR_KEY=${CLAUDRIEL_SIDECAR_KEY}
    - SESSION_TIMEOUT_MINUTES=15
    - CLAUDE_MODEL=${CLAUDE_MODEL:-claude-sonnet-4-6}
  volumes:
    - ${HOME}/.claude:/home/app/.claude:ro  # Claude Code auth tokens
  networks:
    - claudriel
  expose:
    - "8100"
  healthcheck:
    test: ["CMD", "curl", "-f", "http://localhost:8100/health"]
    interval: 10s
    timeout: 5s
    retries: 3
```

PHP service additions:
```yaml
php:
  depends_on:
    sidecar:
      condition: service_healthy
  environment:
    - SIDECAR_URL=http://sidecar:8100
    - CLAUDRIEL_SIDECAR_KEY=${CLAUDRIEL_SIDECAR_KEY}
```

PHP service connects via `http://sidecar:8100`.

### Dockerfile (docker/sidecar/Dockerfile)

```dockerfile
FROM python:3.11-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8100"]
```

### requirements.txt

```
claude-code-sdk
fastapi
uvicorn[standard]
```

## Data Flow (detailed)

1. User types "check my email" in Claudriel chat UI
2. Frontend POSTs to `POST /api/chat/send` (PHP)
3. PHP saves user `ChatMessage`, returns `messageId`
4. Frontend opens SSE connection to `GET /stream/chat/{messageId}` (PHP)
5. PHP loads conversation history, builds system prompt via `ChatSystemPromptBuilder`
6. PHP POSTs to sidecar `POST /chat` with `{session_id, system_prompt, messages}`
7. Sidecar finds or creates `ClaudeSDKClient` for this session_id
8. Sidecar sends prompt to Claude CLI via Agent SDK
9. Claude CLI calls Gmail MCP tools (authenticated via claude.ai)
10. Claude responds with email summary
11. Sidecar streams tokens back as SSE to PHP
12. PHP pipes SSE tokens to browser
13. PHP saves final assistant `ChatMessage`
14. User sees "You have 3 unread emails from..."

## Prerequisites

- **Claude Code auth in Docker**: The `~/.claude/` directory is mounted read-only into the sidecar container. Must verify that OAuth tokens are portable (no host-specific paths or localhost callbacks). Test this before implementing anything else.

## Sidecar API Endpoints

### GET /health

Returns `200 OK` with `{"status": "ok"}` when the service is running. Used by Docker healthcheck and PHP readiness detection.

## Tool Permissions

The `ClaudeSDKClient` should be configured with `allowed_tools` to restrict Claude to Gmail and Calendar MCP tools only. No file system access, no shell execution, no code editing inside the container.

## Fallback Behavior

When the sidecar is unavailable (down, unhealthy, timeout):
- Claudriel falls back to direct Anthropic API calls via the existing `AnthropicChatClient`
- Chat works normally but without Gmail/Calendar tool access
- No error shown to user unless they ask for something that requires tools

## Open Questions

- **Rate limits**: Claude Code SDK may have different rate limits than direct API calls.
- **Error handling**: How the sidecar reports tool failures (e.g., Gmail auth expired) back to Claudriel via SSE error events.
- **Session cleanup during tool use**: Long-running MCP tool calls should refresh the session's last-activity timestamp to prevent mid-request cleanup.

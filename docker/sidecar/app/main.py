import asyncio
import json
import logging
import os
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, HTTPException, Response
from fastapi.responses import StreamingResponse
from pydantic import BaseModel

from app.auth import verify_api_key
from app.claude_client import DoneEvent, ErrorEvent, ProgressEvent, TokenEvent, stream_chat
from app.session_manager import SessionManager

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

session_manager: SessionManager | None = None


@asynccontextmanager
async def lifespan(app: FastAPI):
    global session_manager
    timeout = int(os.environ.get("SESSION_TIMEOUT_MINUTES", "15"))
    session_manager = SessionManager(timeout_minutes=timeout)
    await session_manager.start_cleanup_loop()
    yield
    await session_manager.stop_cleanup_loop()


app = FastAPI(title="Claudriel Sidecar", lifespan=lifespan)


class ChatMessage(BaseModel):
    role: str
    content: str


class ChatRequest(BaseModel):
    session_id: str
    system_prompt: str
    messages: list[ChatMessage]
    tenant_id: str | None = None
    workspace_id: str | None = None


class WorkspaceBootstrapRequest(BaseModel):
    tenant_id: str
    workspace_id: str


@app.get("/health")
async def health():
    return {
        "status": "ok",
        "active_sessions": session_manager.active_count if session_manager else 0,
        "bootstrapped_workspaces": session_manager.workspace_bootstrap_count if session_manager else 0,
    }


@app.post("/chat")
async def chat(
    request: ChatRequest,
    _key: str = Depends(verify_api_key),
):
    if not session_manager:
        raise HTTPException(status_code=503, detail="Service not ready")

    session = session_manager.get_or_create(request.session_id)
    logger.info(
        "Chat request: session=%s, tenant=%s, workspace=%s, messages=%d",
        request.session_id,
        request.tenant_id,
        request.workspace_id,
        len(request.messages),
    )

    async def event_stream():
        messages = [{"role": m.role, "content": m.content} for m in request.messages]
        logger.info("Starting event_stream for session=%s", request.session_id)

        # Use a queue to decouple the SDK async generator (anyio-based)
        # from the SSE response generator (asyncio-based).
        queue: asyncio.Queue = asyncio.Queue()
        sentinel = object()

        async def producer():
            try:
                async for event in stream_chat(
                    system_prompt=request.system_prompt, messages=messages
                ):
                    await queue.put(event)
            except Exception as e:
                logger.error("Producer error: %s", e, exc_info=True)
                await queue.put(ErrorEvent(error=str(e)))
            finally:
                await queue.put(sentinel)

        task = asyncio.create_task(producer())

        # Send initial heartbeat immediately
        yield ": heartbeat\n\n"

        try:
            while True:
                try:
                    event = await asyncio.wait_for(queue.get(), timeout=5.0)
                except asyncio.TimeoutError:
                    session.touch()
                    yield ": heartbeat\n\n"
                    continue

                if event is sentinel:
                    break

                session.touch()

                if isinstance(event, TokenEvent):
                    yield _sse("chat-token", {"token": event.text})
                elif isinstance(event, DoneEvent):
                    yield _sse("chat-done", {"done": True, "full_response": event.full_text})
                elif isinstance(event, ErrorEvent):
                    yield _sse("chat-error", {"error": event.error})
                elif isinstance(event, ProgressEvent):
                    yield _sse(
                        "chat-progress",
                        {
                            "phase": event.phase,
                            "summary": event.summary,
                            "level": event.level,
                        },
                    )

        except Exception as e:
            logger.error("Event stream error: %s", e, exc_info=True)
            yield _sse("chat-error", {"error": str(e)})
        finally:
            if not task.done():
                task.cancel()

    return StreamingResponse(
        event_stream(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "X-Accel-Buffering": "no",
        },
    )


@app.post("/bootstrap/workspace")
async def bootstrap_workspace(
    request: WorkspaceBootstrapRequest,
    _key: str = Depends(verify_api_key),
):
    if not session_manager:
        raise HTTPException(status_code=503, detail="Service not ready")

    state = session_manager.bootstrap_workspace(request.tenant_id, request.workspace_id)
    return {
        "state": state,
        "tenant_id": request.tenant_id,
        "workspace_id": request.workspace_id,
    }


@app.delete("/chat/{session_id}")
async def delete_session(
    session_id: str,
    _key: str = Depends(verify_api_key),
):
    if session_manager and session_manager.remove(session_id):
        return Response(status_code=204)
    raise HTTPException(status_code=404, detail="Session not found")


def _sse(event: str, data: dict) -> str:
    return f"event: {event}\ndata: {json.dumps(data)}\n\n"

import asyncio
import json
import os
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, HTTPException, Response
from fastapi.responses import StreamingResponse
from pydantic import BaseModel

from app.auth import verify_api_key
from app.claude_client import stream_chat, TokenEvent, DoneEvent, ErrorEvent
from app.session_manager import SessionManager


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


@app.get("/health")
async def health():
    return {"status": "ok", "active_sessions": session_manager.active_count if session_manager else 0}


@app.post("/chat")
async def chat(
    request: ChatRequest,
    _key: str = Depends(verify_api_key),
):
    if not session_manager:
        raise HTTPException(status_code=503, detail="Service not ready")

    session = session_manager.get_or_create(request.session_id)

    async def event_stream():
        try:
            messages = [{"role": m.role, "content": m.content} for m in request.messages]

            async for event in _with_heartbeat(
                stream_chat(system_prompt=request.system_prompt, messages=messages),
                interval=15.0,
            ):
                session.touch()

                if event is None:
                    yield ": heartbeat\n\n"
                elif isinstance(event, TokenEvent):
                    yield _sse("chat-token", {"token": event.text})
                elif isinstance(event, DoneEvent):
                    yield _sse("chat-done", {"done": True, "full_response": event.full_text})
                elif isinstance(event, ErrorEvent):
                    yield _sse("chat-error", {"error": event.error})

        except Exception as e:
            yield _sse("chat-error", {"error": str(e)})

    return StreamingResponse(
        event_stream(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )


@app.delete("/chat/{session_id}")
async def delete_session(
    session_id: str,
    _key: str = Depends(verify_api_key),
):
    if session_manager and session_manager.remove(session_id):
        return Response(status_code=204)
    raise HTTPException(status_code=404, detail="Session not found")


async def _with_heartbeat(stream, interval: float = 15.0):
    """Wrap an async iterator to yield None as heartbeat when no events arrive within interval."""
    aiter = stream.__aiter__()
    while True:
        try:
            event = await asyncio.wait_for(aiter.__anext__(), timeout=interval)
            yield event
        except asyncio.TimeoutError:
            yield None  # heartbeat signal
        except StopAsyncIteration:
            break


def _sse(event: str, data: dict) -> str:
    return f"event: {event}\ndata: {json.dumps(data)}\n\n"

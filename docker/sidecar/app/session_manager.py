import asyncio
import time
from dataclasses import dataclass, field


@dataclass
class Session:
    session_id: str
    last_activity: float = field(default_factory=time.time)

    def touch(self) -> None:
        self.last_activity = time.time()

    def is_expired(self, timeout_seconds: float) -> bool:
        return (time.time() - self.last_activity) > timeout_seconds


class SessionManager:
    def __init__(self, timeout_minutes: int = 15):
        self._sessions: dict[str, Session] = {}
        self._workspace_bootstraps: dict[tuple[str, str], float] = {}
        self._timeout_seconds = timeout_minutes * 60
        self._cleanup_task: asyncio.Task | None = None

    def get_or_create(self, session_id: str) -> Session:
        if session_id in self._sessions:
            session = self._sessions[session_id]
            session.touch()
            return session
        session = Session(session_id=session_id)
        self._sessions[session_id] = session
        return session

    def remove(self, session_id: str) -> bool:
        return self._sessions.pop(session_id, None) is not None

    def bootstrap_workspace(self, tenant_id: str, workspace_id: str) -> str:
        key = (tenant_id, workspace_id)
        if key in self._workspace_bootstraps:
            return "existing"

        self._workspace_bootstraps[key] = time.time()
        return "created"

    def cleanup_expired(self) -> list[str]:
        expired = [
            sid for sid, session in self._sessions.items()
            if session.is_expired(self._timeout_seconds)
        ]
        for sid in expired:
            del self._sessions[sid]
        return expired

    async def start_cleanup_loop(self, interval_seconds: int = 60) -> None:
        self._cleanup_task = asyncio.create_task(self._cleanup_loop(interval_seconds))

    async def _cleanup_loop(self, interval_seconds: int) -> None:
        while True:
            await asyncio.sleep(interval_seconds)
            self.cleanup_expired()

    async def stop_cleanup_loop(self) -> None:
        if self._cleanup_task:
            self._cleanup_task.cancel()
            try:
                await self._cleanup_task
            except asyncio.CancelledError:
                pass

    @property
    def active_count(self) -> int:
        return len(self._sessions)

    @property
    def workspace_bootstrap_count(self) -> int:
        return len(self._workspace_bootstraps)

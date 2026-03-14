import time

from app.session_manager import SessionManager


def test_get_or_create_new_session():
    manager = SessionManager(timeout_minutes=15)
    session = manager.get_or_create("abc-123")
    assert session.session_id == "abc-123"
    assert manager.active_count == 1


def test_get_or_create_reuses_existing():
    manager = SessionManager(timeout_minutes=15)
    s1 = manager.get_or_create("abc-123")
    s2 = manager.get_or_create("abc-123")
    assert s1 is s2
    assert manager.active_count == 1


def test_remove_session():
    manager = SessionManager(timeout_minutes=15)
    manager.get_or_create("abc-123")
    assert manager.remove("abc-123") is True
    assert manager.active_count == 0
    assert manager.remove("abc-123") is False


def test_cleanup_expired():
    manager = SessionManager(timeout_minutes=0)  # 0 min = expire immediately
    session = manager.get_or_create("abc-123")
    session.last_activity = time.time() - 1  # force expired
    expired = manager.cleanup_expired()
    assert expired == ["abc-123"]
    assert manager.active_count == 0


def test_touch_refreshes_activity():
    manager = SessionManager(timeout_minutes=0)
    session = manager.get_or_create("abc-123")
    session.last_activity = time.time() - 100
    session.touch()
    assert not session.is_expired(1)


def test_workspace_bootstrap_is_idempotent_per_tenant_and_workspace():
    manager = SessionManager(timeout_minutes=15)
    assert manager.bootstrap_workspace("tenant-1", "workspace-a") == "created"
    assert manager.bootstrap_workspace("tenant-1", "workspace-a") == "existing"
    assert manager.bootstrap_workspace("tenant-1", "workspace-b") == "created"
    assert manager.bootstrap_workspace("tenant-2", "workspace-a") == "created"
    assert manager.workspace_bootstrap_count == 3

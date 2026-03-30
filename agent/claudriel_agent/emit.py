"""JSONL event emission to stdout."""

import json


def emit(event: str, **kwargs: object) -> None:
    """Write a JSON-line event to stdout."""
    line = json.dumps({"event": event, **kwargs}, ensure_ascii=False)
    print(line, flush=True)

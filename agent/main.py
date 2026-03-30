#!/usr/bin/env python3
"""Backward-compatible entrypoint for the Claudriel agent.

Prefer ``python -m claudriel_agent`` (with ``pip install -e .`` from ``agent/``).

Usage:
    echo '{"messages": [...], "system": "...", ...}' | python agent/main.py
"""

from claudriel_agent.main import main

if __name__ == "__main__":
    main()

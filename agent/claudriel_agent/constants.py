"""Shared constants for the agent loop and turn budgeting."""

# Max characters for tool results stored in conversation history.
# Full results are still emitted via tool_result events to the frontend.
TOOL_RESULT_MAX_CHARS = 2000
GMAIL_BODY_MAX_CHARS = 500

DEFAULT_TURN_LIMITS: dict[str, int] = {
    "quick_lookup": 5,
    "email_compose": 15,
    "brief_generation": 10,
    "research": 40,
    "general": 25,
    "onboarding": 30,
}

RATE_LIMIT_MAX_RETRIES = 3
RATE_LIMIT_INITIAL_BACKOFF = 5  # seconds
RATE_LIMIT_MAX_BACKOFF = 60  # seconds

# Model fallback chains: degrade on rate limit, escalate on API error
MODEL_DEGRADATION: dict[str, str | None] = {
    "claude-opus-4-6": "claude-sonnet-4-6",
    "claude-sonnet-4-6": "claude-haiku-4-5-20251001",
    "claude-haiku-4-5-20251001": None,
}
MODEL_ESCALATION: dict[str, str | None] = {
    "claude-haiku-4-5-20251001": "claude-sonnet-4-6",
    "claude-sonnet-4-6": "claude-opus-4-6",
    "claude-opus-4-6": None,
}

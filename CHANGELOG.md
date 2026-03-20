# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- SSE streaming controllers (`ChatStreamController`, `BriefStreamController`) killed by PHP's default `max_execution_time` (30s), causing `ERR_HTTP2_PROTOCOL_ERROR` on chat and fallback polling on brief (#set_time_limit)
- Agent subprocess hitting 429 rate limits due to uncached system prompt and tool definitions re-sent every turn (#379)
- Agent conversation history growing unbounded from full email bodies in tool results (#380)
- Internal API endpoints (commitments, triage, brief) returning 500 due to Waaseyaa DI not finding pre-registered controller singletons (fixed in waaseyaa/ssr v0.1.0-alpha.35)

### Changed
- Upgraded Waaseyaa packages from v0.1.0-alpha.33 to v0.1.0-alpha.35
- Split staging and production Anthropic API keys into separate Ansible vault variables

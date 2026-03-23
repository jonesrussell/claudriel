# Agentic Design Patterns — Claudriel Baseline Assessment

**Source:** "Agentic Design Patterns: A Hands-On Guide to Building Intelligent Systems" by Antonio Gullí (Springer, 2025)
**Assessment Date:** 2026-03-23

## Overview

Assessment of Claudriel's agent architecture against the 21 agentic design patterns identified in Gullí's framework. This baseline informs the roadmap for agent capability improvements.

## Pattern Coverage

| # | Pattern | Status | Current Implementation | Gap |
|---|---------|--------|----------------------|-----|
| 1 | Prompt Chaining | Partial | Pipeline steps chain (CommitmentExtractionStep -> WorkspaceClassificationStep), but agent subprocess uses single-turn tool loops | Agent needs multi-step prompt orchestration with structured intermediate outputs |
| 2 | Routing | Partial | `IssueIntentDetector`, `OrchestratorIntent` parse natural language intents | No dynamic model routing or agent-type selection based on task complexity |
| 3 | Parallelization | Missing | Agent executes tools sequentially | No fan-out/fan-in, no parallel tool execution, no A/B generation |
| 4 | Reflection | Missing | No self-critique mechanism | Agent doesn't review its own outputs before presenting them |
| 5 | Tool Use | Strong | 30+ tools: Gmail, Calendar, GitHub, commitments, workspaces, scheduling | Well-covered; tools call back to PHP via HMAC Bearer auth on internal API |
| 6 | Planning | Missing | No multi-step plan decomposition | Agent reacts turn-by-turn; no goal decomposition into sub-tasks |
| 7 | Multi-Agent | Missing | Single agent subprocess; temporal agents are deterministic (not LLM-driven) | No specialist agents, no delegation, no collaborative problem-solving |
| 8 | Memory Management | Partial | ChatSession/ChatMessage entities, conversation history capped at 20 messages, older responses truncated to 500 chars | No semantic memory, no memory decay, no cross-session knowledge retrieval |
| 9 | Learning & Adaptation | Missing | No feedback loops | No self-improvement, no outcome tracking, no strategy refinement |
| 10 | Model Context Protocol (MCP) | Planned | v3.2 roadmap (#489) to expose Claudriel as MCP server | Not yet implemented; currently uses custom tool-calling protocol |
| 11 | Goal Setting & Monitoring | Partial | Commitment tracking with confidence thresholds, drift detection (48h staleness) | No agent-level goal decomposition, no progress monitoring during execution |
| 12 | Exception Handling | Partial | 429 retry with exponential backoff (3 attempts, 5-60s); emits progress events during retry | No structured recovery strategies, no fallback task completion paths |
| 13 | Human-in-the-Loop | Strong | Core principle: all external actions require explicit approval; draft-then-confirm flow | Well-covered via Claudriel's safety principles |
| 14 | Knowledge Retrieval (RAG) | Missing | No vector store, no embeddings, no document retrieval | No semantic search over ingested content, emails, or documents |
| 15 | Inter-Agent Communication (A2A) | Missing | No agent-to-agent protocol | Single agent architecture; no need yet, but relevant for multi-agent future |
| 16 | Resource-Aware Optimization | Partial | Model fallback chains with rate-limit retry | No cost tracking, no dynamic model switching based on task complexity |
| 17 | Reasoning Techniques | Missing | No structured reasoning in agent prompt | No Chain-of-Thought, no tree-of-thought, no reasoning scaffolding |
| 18 | Guardrails/Safety | Partial | Confidence threshold (>=0.7) on commitment extraction; human approval gates | No input validation framework, no output safety checks, no content filtering |
| 19 | Evaluation & Monitoring | Missing | No agent performance tracking | No trajectory logging, no quality metrics, no success rate tracking |
| 20 | Prioritization | Missing | No intelligent task ordering | Agent processes requests in arrival order; no urgency/importance assessment |
| 21 | Exploration & Discovery | Missing | No autonomous research capability | No hypothesis generation, no self-directed investigation |

## Summary

- **Strong (2):** Tool Use (#5), Human-in-the-Loop (#13)
- **Partial (7):** Prompt Chaining (#1), Routing (#2), Memory (#8), MCP (#10), Goal Setting (#11), Exception Handling (#12), Resource Optimization (#16), Guardrails (#18)
- **Missing (12):** Parallelization (#3), Reflection (#4), Planning (#6), Multi-Agent (#7), Learning (#9), RAG (#14), A2A (#15), Reasoning (#17), Evaluation (#19), Prioritization (#20), Exploration (#21)

## Prioritization Notes

High-impact patterns for Claudriel's use case (personal operations system):

1. **Memory Management** — semantic memory across sessions transforms the daily brief and commitment tracking
2. **RAG/Knowledge Retrieval** — searching ingested emails, documents, and meeting notes is core to the product vision
3. **Planning** — multi-step task execution (e.g., "prepare for my meeting with Sarah" = fetch emails + check commitments + review calendar + draft brief)
4. **Reflection** — self-critique on drafted emails, commitment extractions, and brief quality
5. **Evaluation & Monitoring** — understanding what the agent does well and where it fails
6. **Guardrails** — as the agent gains autonomy, safety validation becomes critical

Lower priority (infrastructure patterns, less immediately impactful):

- Parallelization, Multi-Agent, A2A, Resource Optimization — these matter at scale but aren't blocking current value delivery
- Exploration & Discovery — interesting for research use cases but not core to personal ops

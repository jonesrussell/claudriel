# RFC: Waaseyaa Agentic Framework Architecture

**Status:** Accepted
**Date:** 2026-03-23
**Decision:** Hybrid model — lean agent kernel + three strategic organ packages

## Context

Waaseyaa already has four AI packages (`ai-agent`, `ai-schema`, `ai-pipeline`, `ai-vector`) providing agent loop, tool generation, processing pipelines, and embeddings. The gap is the "mind" that sits on top: memory, safety, observability.

The framework must remain composable, not monolithic. It should formalize the agentic pattern layer early rather than letting it sprawl.

## Decision

### Package Architecture (7 packages)

```
waaseyaa/
  ai-agent/          <- agent loop, planning, routing, reflection, multi-agent
  ai-memory/         <- conversation + semantic memory, reweave, compression
  ai-guardrails/     <- permissions, safety, reversible actions, validation
  ai-observability/  <- traces, logs, cost, anomaly detection, introspection
  ai-schema/         <- JSON Schema + MCP tool generation (exists)
  ai-vector/         <- embeddings + vector stores (exists)
  ai-pipeline/       <- step-based processing + queues (exists)
```

### Dependency Graph (acyclic)

```
ai-schema <--- ai-agent ---> ai-memory
ai-vector <-------+    +---> ai-guardrails
ai-pipeline <-----+    +---> ai-observability
```

No organ depends on the brainstem. The brainstem orchestrates them all.

## Package Responsibilities

### ai-agent (the brainstem) — EXISTS, EXTEND

The kernel of agentic behavior. Owns behaviors, not infrastructure.

**Current:** AgentInterface, AgentExecutor, ToolRegistry, MCP server, AnthropicProvider, audit logging

**Add:**
- Planning: goal decomposition, step execution, learn-forward context (pattern 6)
- Routing: task classification, dynamic model selection, provider routing (pattern 2)
- Reflection: self-critique loop, synthesis output format (pattern 4)
- Multi-agent: agent registry, delegation, result synthesis (pattern 7)
- Prompt chaining: structured intermediate outputs between steps (pattern 1)
- Parallelization: fan-out/fan-in for independent tool calls (pattern 3)
- Context assembly: selective context curation per request (context engineering)

### ai-memory (the hippocampus) — NEW

Too big and too important to cram into ai-agent. Owns all forms of memory.

**Responsibilities:**
- Conversation memory: session/thread management, history storage
- Semantic memory: fact extraction, long-term knowledge (VectorMemory, FactExtraction, StaticMemory)
- Episodic memory: event-based recall, temporal windows
- Memory persistence: entity-based storage via waaseyaa entity system
- Summarization: compress conversation history for context windows
- Compression: reduce memory footprint without losing meaning
- Retrieval: semantic search across memory stores
- Reweave: backward context propagation (Ars Contexta pattern)
- Memory decay: temporal relevance scoring, consolidation
- Memory schemas: structured memory types

**Unlocks patterns:** Memory (8), RAG (14), Reflection (4), Learning (9)

**Depends on:** ai-vector (for embeddings), entity (for persistence)

### ai-guardrails (the immune system) — NEW

Cross-cutting concern that must be isolated. Owns safety and assurance.

**Responsibilities:**
- Tool permission model: tiered access (read-only / write-low / write-high / destructive)
- canUseTool callback: programmatic enforcement (Polyscope pattern)
- Reversible/irreversible classification: action impact assessment
- Safety policies: configurable rules, not prompts-as-policy
- Input validation: sanitize external inputs, prompt injection defense
- Output validation: PII leakage, hallucination detection, content filtering
- Model constraints: token limits, cost caps, rate limit management
- Assurance hooks: pre/post execution validation points
- Audit trail: every permission decision logged

**Unlocks patterns:** Guardrails (18), Trifecta assurance, Progressive autonomy

**Depends on:** nothing (pure policy layer)

### ai-observability (the nervous system) — NEW

Backbone of understanding what the agent does and how well it does it.

**Responsibilities:**
- Trace logging: full agent execution path (prompt -> reasoning -> tools -> output)
- Cost accounting: token usage, model, estimated cost per request
- Event graph: structured representation of agent trajectories
- Error taxonomy: classify errors (transient vs. permanent, tool vs. model)
- Anomaly detection: unusual action patterns, deviation from expected behavior
- Metrics: success rates, latency, quality scores
- Introspection API: query agent performance programmatically
- Langfuse integration: optional export to external observability platforms

**Unlocks patterns:** Evaluation (19), Resource Optimization (16), Exception Handling (12)

**Depends on:** nothing (pure observation layer)

### ai-schema — EXISTS, STABLE

JSON Schema generation from entity types, MCP tool auto-generation.

No changes needed. Already serves its purpose well.

### ai-vector — EXISTS, STABLE

Embeddings, pluggable vector stores, semantic search.

ai-memory will depend on this for semantic memory retrieval.

### ai-pipeline — EXISTS, STABLE

Step-based processing with queue dispatch.

Already used by Claudriel for commitment extraction, workspace classification.

## Pattern Coverage Map

| Pattern | Primary Package | Supporting |
|---------|----------------|------------|
| 1. Prompt Chaining | ai-agent | ai-pipeline |
| 2. Routing | ai-agent | ai-observability (cost tracking) |
| 3. Parallelization | ai-agent | |
| 4. Reflection | ai-agent | ai-memory (context) |
| 5. Tool Use | ai-agent + ai-schema | ai-guardrails |
| 6. Planning | ai-agent | ai-memory (learn-forward) |
| 7. Multi-Agent | ai-agent | ai-observability (coordination traces) |
| 8. Memory Management | ai-memory | ai-vector |
| 9. Learning & Adaptation | ai-memory | ai-observability (metrics) |
| 10. MCP | ai-agent + ai-schema | |
| 11. Goal Setting | ai-agent (planning) | ai-memory (persistence) |
| 12. Exception Handling | ai-agent | ai-observability (error taxonomy) |
| 13. Human-in-the-Loop | ai-guardrails | ai-agent |
| 14. Knowledge Retrieval (RAG) | ai-memory | ai-vector, ai-pipeline |
| 15. Inter-Agent Communication | ai-agent | |
| 16. Resource Optimization | ai-observability | ai-agent (routing) |
| 17. Reasoning Techniques | ai-agent | |
| 18. Guardrails/Safety | ai-guardrails | |
| 19. Evaluation & Monitoring | ai-observability | |
| 20. Prioritization | ai-agent | ai-memory (context) |
| 21. Exploration & Discovery | ai-agent (planning) | ai-memory (RAG) |

## Design Principles

1. **Organs don't depend on the brainstem** — ai-memory, ai-guardrails, ai-observability are usable independently
2. **The brainstem orchestrates** — ai-agent wires organs together via service providers
3. **Extract patterns, not dependencies** — learn from LangGraph/CrewAI/Neuron AI, but build waaseyaa-native
4. **Entity system is the persistence layer** — memory, traces, sessions are all entity types
5. **Composable via service providers** — apps opt into what they need
6. **Zero downstream knowledge** — waaseyaa doesn't know about Claudriel

## References

- Agentic Design Patterns baseline: `docs/specs/agentic-design-patterns-baseline.md`
- GitHub epic: jonesrussell/claudriel#523
- Autonomous AI Trifecta (assurance framework)
- Polyscope (canUseTool, Autopilot, Opinions)
- Ars Contexta (6 Rs pipeline, Reweave)
- LlamaIndex Context Engineering (memory types, selective retrieval)
- Framework landscape: Laravel AI SDK, Neuron AI, LarAgent, LangGraph, CrewAI, Google ADK

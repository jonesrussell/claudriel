# Tenant and Workspace Boundaries

## Purpose

This document defines the tenant and workspace boundary model for Claudriel v1.0. It is the Phase 2 artifact for issue `#91` and is intended to guide later implementation work in `#94` without prematurely changing routing or enforcement code in this phase.

## Boundary Model

### Tenant

A tenant is the top-level ownership boundary for all user data and runtime operations in Claudriel. Any record or request that is user-specific must resolve to a single tenant before it is read, created, updated, deleted, streamed, or processed asynchronously.

### Workspace

A workspace is a scoped operating context inside a tenant. A workspace groups related operational data such as events, schedule entries, commitments, people, artifacts, and chat intent, but it does not replace the tenant boundary. Workspace context is always subordinate to tenant context.

### Required Invariant

Every workspace belongs to exactly one tenant, and no workspace-scoped operation may cross tenant boundaries. Tenant resolution must happen before workspace resolution.

## Ownership Chain Definition

### Canonical Chain

`Tenant -> Account -> Workspace -> Operational Records`

### Interpretation

- The tenant is the security and ownership root.
- The account is the authenticated application identity operating inside that tenant.
- The workspace is a tenant-scoped context selected explicitly or inferred safely.
- Operational records must never be attached to a workspace without also belonging to the same tenant.

### Record Ownership Rules

- `Workspace` must be tenant-scoped. The existing `account_id` field is not sufficient on its own for multi-tenant isolation.
- `McEvent`, `Commitment`, `Person`, `TriageEntry`, and `ScheduleEntry` must resolve to a tenant before persistence.
- Any `workspace_id` or `workspace_uuid` reference must point only to a workspace owned by the same tenant.
- Chat sessions and chat messages must resolve tenant ownership before performing local actions or agent subprocess actions.
- Agent subprocess sessions must inherit tenant context from the originating request and must never operate as a global shared context.

## Tenant and Workspace Resolution Rules

### Request Resolution

1. Resolve tenant context first from the authenticated account, token, or explicit trusted runtime context.
2. Resolve workspace context second from an explicit workspace identifier or a safe tenant-scoped lookup.
3. Reject or downgrade any request that cannot prove tenant ownership for the workspace it references.

### Defaulting Rules

- Tenant context may default only from authenticated server-side context, never from client-controlled workspace names alone.
- Workspace context may be absent.
- When workspace context is absent, the request remains tenant-scoped and must not widen beyond the current tenant.

### Lookup Rules

- Workspace lookup by name must be tenant-scoped.
- Workspace lookup by UUID must be tenant-scoped.
- Cross-tenant uniqueness must not be assumed for workspace names.

## Enforcement Points Table

| Surface | Required tenant check | Required workspace check | Failure mode |
|---|---|---|---|
| HTTP routing and controllers | Resolve tenant before any entity query or write | Resolve workspace only within resolved tenant | `404` or `403`, never fallback to global data |
| Dashboard and brief assembly | Query only tenant-owned records | Filter workspace slices only within tenant-owned records | Empty scoped result, never mixed-tenant view |
| Chat local actions | Create, delete, or update workspaces only inside active tenant | Match workspace names and identifiers within tenant scope | Refuse ambiguous or cross-tenant mutation |
| Persistence layer | Persist `tenant_id` on all tenant-owned records | Persist workspace reference only after tenant match | Reject invalid associations |
| Background jobs and async tasks | Carry tenant context in job payloads | Carry optional workspace context as tenant-scoped metadata | Do not run job if tenant context is missing |
| Agent subprocess requests | Propagate originating tenant context | Propagate optional workspace context only as tenant-scoped input | Refuse global or unscoped privileged action |
| Ingestion endpoints | Require trusted tenant context on ingest payload or auth mapping | Associate workspace only through tenant-scoped classification or lookup | Store tenant-only record if workspace is uncertain |

## Routing Boundary Rules

- Routing must derive tenant context before it decides which workspace is active.
- Route parameters, query strings, and chat message content are not authoritative on their own without tenant resolution.
- A workspace route or request must never load a workspace by raw identifier without constraining the lookup by tenant.
- Any future tenant-aware routing layer in `#94` must treat tenant resolution as a mandatory precondition and workspace resolution as a constrained secondary step.

## Implemented Routing Enforcement

Phase 4 introduces a request-scope resolver that applies these rules consistently across the brief, dashboard, chat, and tenant-owned API controllers.

### Context Resolution

- `tenant_id` is resolved first from authenticated account context when available, then from trusted request scope, then from the server default tenant.
- `workspace_id` or `workspace_uuid` is resolved only after tenant resolution.
- Any requested workspace must be loaded inside the resolved tenant; otherwise the request fails closed.

### Enforcement Behavior

- Brief and dashboard surfaces assemble tenant-scoped data and only allow workspace scoping for workspaces owned by the active tenant.
- Chat send and chat stream carry `tenant_id` and optional `workspace_id` on sessions and messages, and local workspace mutations resolve names only inside the active tenant.
- Workspace CRUD loads workspaces by tenant-scoped UUID and rejects cross-tenant access.
- Tenant-owned CRUD APIs for people, commitments, schedule, and triage now load and mutate records only within the active tenant scope.
- Agent subprocess chat requests now carry `tenant_id` and `workspace_id` so later enforcement layers can treat the agent boundary as tenant-aware instead of global.

## Persistence Boundary Rules

- `tenant_id` is the minimum required partition key for tenant-owned domain data.
- Workspace references are valid only when the target workspace belongs to the same tenant.
- Persistence code must not infer tenant ownership from workspace metadata alone.
- Records created without a confident workspace match may remain tenant-scoped with a null workspace reference.
- Mutations that would move a record between tenants through workspace reassignment must be disallowed.

## Job Boundary Rules

- Every job payload must include a tenant identifier when operating on tenant-owned data.
- Workspace identifiers in jobs are optional but, when present, must be validated against tenant ownership before execution.
- Retry and replay behavior must preserve tenant context exactly; jobs must not rerun in a global default tenant.
- Background processing must fail closed when tenant context is missing or inconsistent.

## Agent Subprocess Boundary Rules

- The agent subprocess must receive tenant context from the PHP application, not invent it.
- Agent subprocess sessions must be isolated per originating application context and must not leak state between tenants.
- Agent-triggered ingestion back into Claudriel must preserve the same tenant association as the originating request.
- Tenant propagation from PHP to the agent subprocess must be validated; the HMAC token carries account_id which resolves to tenant context.

## Current Gaps Observed In Repo

- `Workspace` currently defaults fields like `account_id`, but it does not establish an explicit tenant field yet.
- Existing local workspace actions in `ChatStreamController` perform global name matching across loaded workspaces and do not apply tenant scoping.
- Several domain entities already carry `tenant_id`, but the boundary behavior is not documented consistently at the routing and job layers.
- Agent subprocess receives tenant context via HMAC token (account_id), but workspace propagation rules are not yet formalized in code.

## Implementation Guidance For Phase 4

- Add tenant-scoped workspace resolution before introducing route-level workspace loading.
- Require tenant-aware workspace lookups in dashboard, chat, brief, and API entry points.
- Preserve null workspace as a valid tenant-scoped state where classification is uncertain.
- Treat any missing tenant context as an enforcement failure, not a reason to widen scope.

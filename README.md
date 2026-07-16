# Scalable Gmail Auto-Responder

## High-Level Architecture

The architecture is split into a small number of independent responsibilities. Each component owns a single concern and communicates with the next through well-defined interfaces. Gmail remains the source of truth for email data, while RevReply owns the AI decisions, workflow state, and audit history.

> **High-Level Architecture**

```
                        +-----------------------+
                        | Connected Account     |
                        | Manager               |
                        +-----------------------+
                                   │
                                   │
                                   ▼
                           +----------------+
                           |     Gmail      |
                           +----------------+
                                   │
                     Push Notification (Pub/Sub)
                                   │
                                   ▼
                    +-----------------------------+
                    |      Ingestion Component    |
                    +-----------------------------+
                                   │
                         Queue (Durability Boundary)
                                   │
                                   ▼
                    +-----------------------------+
                    |      Context Builder        |
                    +-----------------------------+
                                   │
                                   ▼
                    +-----------------------------+
                    |     AI Understanding        |
                    +-----------------------------+
                                   │
                      Intent / Risk / Confidence
                                   │
                                   ▼
                    +-----------------------------+
                    |      Decision Engine        |
                    +-----------------------------+
                          │        │         │
                 Auto Send │ Draft │ Escalate
                          ▼        ▼         ▼
                     Gmail API  Gmail API  Dashboard
                                   │
                                   ▼
                          +------------------+
                          |    Dashboard     |
                          +------------------+
```

### Why these components?

I intentionally separated the system by responsibility instead of technology.

- **Connected Account Manager** is responsible for the integration between RevReply and Gmail. It manages Gmail OAuth, access and refresh tokens, Gmail watch subscription registration and renewal, and multiple connected Gmail accounts per user. Gap recovery (recovering missed emails when a watch expires or the service goes down) is explicitly out of scope for this component — it belongs to the Ingestion Component.
- **Ingestion** is responsible for reliably receiving Gmail events and protecting them onto the queue. This includes the normal push notification path as well as **gap recovery**: when a watch expires before renewal or the service experiences downtime, the Ingestion Component uses the `last_history_id` stored per `ConnectedAccount` to call Gmail's History API, recover any missed messages, and enqueue them identically to live push notifications. This makes gap recovery a consequence of the same ingestion behavior rather than a separate system. The `last_history_id` is maintained by the Connected Account Manager and consumed by the Ingestion Component.
- **Context Builder** gathers everything required for reasoning, including the email thread, attachments, and user configs (again limited to gmail for now, but is extensible ).
- **AI Pipeline** converts unstructured conversation into structured information (intent, confidence, risk, draft).
- **Decision Engine** contains deterministic business logic. The LLM recommends; it never decides whether an email is sent automatically.
- **Action Executor** performs the chosen action through the Gmail API.
- **Dashboard** exposes drafts, escalations, and workflow history to the user.

This separation keeps each component cohesive and allows the AI layer to evolve independently from the Gmail integration.

### System Boundaries

One of the design decisions I made early was to avoid duplicating data that Gmail already owns.

The email itself, attachments, threads and drafts remain inside Gmail and are fetched on demand.

We stores only information that it creates itself, such as AI classifications, workflow history, audit records, user preferences and OAuth metadata.

This keeps the architecture simpler, avoids synchronization problems, and makes Gmail the single source of truth for mailbox data.

## Major Engineering Decisions

Throughout the design I tried to follow one principle:

> Prefer simple systems with clear ownership over complex systems with speculative optimizations.

Whenever I had to choose between introducing another component and keeping the architecture simpler, I chose the simpler option unless there was a measurable engineering reason not to.

| I Chose                  | Instead Of                        | Why                                              |
| ------------------------ | --------------------------------- | ------------------------------------------------ |
| Gmail Push               | Polling                           | Lower latency, Google's recommended architecture |
| Gmail as source of truth | Mirroring emails                  | Avoid synchronization                            |
| One queue                | Multiple queues                   | Simpler operations                               |
| One AI prompt            | Multi-agent workflow              | Lower latency                                    |
| Business rules           | AI decides                        | Deterministic behaviour                          |
| Queue durability         | Persisting incoming notifications | Fewer writes, queue already provides durability  |
| Queue-dispatched watch renewal jobs | Running renewal synchronously in the scheduler command | Each account renews independently. One account failing does not affect others. Retries and backoff are owned by the queue, not the scheduler command. The command is a dispatcher only. |

---

### Decision 1 — Assume Platform Authentication

**Decision**

The implementation assumes the user is already authenticated into RevReply.

Authentication into the platform (login, session management and user identity) is outside the scope of this assignment.

Connecting Gmail accounts remains fully in scope.

**Why**

The assignment focuses on Gmail ingestion and AI workflow architecture rather than user authentication.

This allows the implementation to focus on the engineering problems specific to Gmail while still designing the complete Gmail connection lifecycle.

**Included**

- Gmail OAuth
- Connecting Gmail accounts
- Multiple connected Gmail accounts
- Access token storage
- Refresh token storage
- Automatic token refresh
- Gmail watch creation
- Gmail watch renewal

**Out of Scope**

- Platform login
- Registration
- Session management
- Password reset

---

### Decision 2 — Multiple Gmail Accounts per User

**Decision**

A RevReply user may connect multiple Gmail accounts.

Each connected account is treated as an independent integration with its own OAuth credentials, watch subscription and synchronization state.

**Why**

Sales teams commonly operate multiple inboxes for outreach.

Treating each mailbox independently simplifies synchronization, token management and workflow ownership.

Each ConnectedAccount stores:

- Gmail email address
- Access Token
- Refresh Token
- Watch Expiration
- Last History ID

This allows failures, token refreshes and watch renewals to be managed independently for every mailbox.

---

### Decision 3 — OAuth Lifecycle

**Decision**

OAuth access tokens are treated as short-lived credentials.

Refresh tokens are persisted securely and used to obtain new access tokens automatically whenever required.

If a refresh token becomes invalid or is revoked, the connected account is marked as disconnected and requires the user to reconnect through the Gmail OAuth flow.

**Why**

This keeps Gmail access uninterrupted while preventing unnecessary user interaction during normal operation.

Permanent authorization failures become explicit operational events rather than repeated processing failures.

---

### Decision 4 — Gmail Push Notifications instead of Polling

**Decision**

Use Gmail `watch()` with Google Cloud Pub/Sub.

**Why**

Polling introduces unnecessary latency, API usage and operational cost. Gmail already provides a push-based notification mechanism designed for server-side applications.

**Trade-off**

Polling

+ Simpler to understand.
- Higher latency.
- Constant API usage.
- Doesn't scale well.

Push Notifications

+ Near real-time.
+ Lower API usage.
+ Google's recommended production architecture.

- Requires Pub/Sub.
- Watches must be renewed periodically.

**How Gmail Watch Actually Works**

The `watch()` API is a lease-based registration, not a persistent connection.

When we call `users.watch()`, Gmail registers internally that it should publish notifications to our Pub/Sub topic for the next 7 days. After calling `watch()`, Gmail uses its own internal service account to publish to Pub/Sub. Our OAuth token is not involved in notification delivery at all. Even if the access token expires one hour after calling `watch()`, notifications continue flowing for the full 7 days.

When the 7 days expire, Gmail silently stops publishing. There is no error, no notification and no alert. The system simply goes quiet.

To prevent this, a daily scheduled Artisan command queries for all connected accounts whose `watch_expiration` is either null (watch was never registered) or falls within the next 48 hours. For each of those accounts, the command dispatches an independent queue job and exits. The command itself does not call the Gmail API — that is the job's responsibility.

Each queue job receives a single `ConnectedAccount`, calls `GmailWatchService::watch()`, and handles its own retries with exponential backoff (waiting 5 seconds before the first retry, 10 seconds before the second, 20 seconds before the third). After all retries are exhausted the job is marked as failed and recorded in the `failed_jobs` table with the full error context. Because each account is its own independent job, one account failing has no effect on any other account's job.

The 48-hour renewal window gives a 24-hour failure tolerance: if the daily job fails to run on day one, the next day's run still catches the same accounts because they are still within 48 hours of expiry. Accounts with a freshly registered watch (6 or 7 days remaining) are not touched.

If a watch does expire before renewal, gap recovery is handled by the Ingestion Component using the `last_history_id` stored in `ConnectedAccount`. See the Ingestion Component description and the failure handling table for the full recovery strategy.

---

### Decision 5 — Gmail is the Source of Truth

**Decision**

Emails, threads, attachments and drafts remain inside Gmail.

RevReply stores only the information it owns.

**Why**

Duplicating mailbox data creates synchronization problems and significantly increases storage requirements.

Whenever processing begins, the latest conversation state is fetched directly from Gmail.

**Stored by RevReply**

- OAuth metadata
- AI classifications
- Decision history
- Audit history
- User preferences
- Workflow state

---

### Decision 6 — Queue as the Durability Boundary

**Decision**

Incoming Gmail notifications are immediately placed onto a queue.

The queue becomes the durability boundary.

The notification itself is not persisted inside MySQL.

**Why**

Pub/Sub guarantees delivery.

Once the notification has been accepted by the queue, processing becomes asynchronous.

This keeps the webhook extremely fast while avoiding unnecessary database writes.

The worker reconstructs the latest mailbox state using Gmail's `historyId`.

---

### Decision 7 — Single AI Workflow

**Decision**

The current implementation uses a single structured LLM prompt.

The prompt produces

- Intent
- Confidence
- Risk
- Suggested Draft

**Why**

Multiple LLM calls increase latency and operational complexity.

The architecture keeps the AI layer simple until production evaluations demonstrate that additional stages (critic, evaluator, self-reflection) are necessary.

**Future Evolution**

Evaluation-driven development can later split this workflow into specialized reasoning stages if quality measurements justify the additional latency.

---

### Decision 8 — AI Does Not Make Business Decisions

**Decision**

The LLM provides understanding.

The Decision Engine determines the final action.

Possible actions are

- Auto Send
- Create Draft
- Escalate
- Ignore

**Why**

Business policy should remain deterministic.

The LLM recommends an action, but business rules determine whether that recommendation is acceptable.

For example, company policy may require all pricing emails to remain human-reviewed regardless of model confidence.

---

### Decision 9 — Minimize Stored State

**Decision**

Store only data that cannot be reconstructed.

Everything else is retrieved from Gmail when required.

**Why**

This reduces synchronization issues and simplifies long-term maintenance.

Transient processing data (thread context, parsed attachments, intermediate AI inputs) exists only during workflow execution and is discarded afterwards.

---

### Decision 10 — Production Before Optimization

**Decision**

The architecture intentionally avoids introducing additional queues, multiple AI stages, distributed workflows or replicated mailbox storage until there is production evidence they are required, if in production we find issues regarding these we will have to come back at this point.

## Data Ownership

One of the first architectural decisions was to avoid duplicating mailbox data.

Gmail already owns the mailbox and provides APIs to retrieve the latest state of conversations, attachments and drafts. Instead of synchronizing mailbox data into our own database, RevReply only stores information that it creates itself.

This reduces synchronization complexity and keeps Gmail as the single source of truth for email data.

| Data | Source of Truth | Stored By | Reason |
|-------|-----------------|-----------|--------|
| Emails | Gmail | Gmail | Original mailbox data |
| Email Threads | Gmail | Gmail | Retrieved when processing |
| Attachments | Gmail | Gmail | Retrieved on demand |
| Gmail Drafts | Gmail | Gmail | Created through Gmail API |
| Connected Gmail Accounts | RevReply | MySQL | OAuth metadata |
| OAuth Tokens | RevReply | MySQL | Gmail API access |
| User Preferences | RevReply | MySQL | AI behaviour & settings |
| AI Classification | RevReply | MySQL | Dashboard & audit |
| AI Decision | RevReply | MySQL | Workflow history |
| Workflow State | RevReply | MySQL | Resumable processing |
| Audit History | RevReply | MySQL | Traceability |
| Processed Notifications | RevReply | MySQL | Idempotency across restarts and workers |

The only data cached temporarily during processing is the workflow context (thread, parsed attachments and intermediate processing state). This data exists only to support retries and avoid repeated Gmail API calls during a single workflow execution. It is discarded once processing completes.

---

## Storage Layers

Not every piece of data needs to be in MySQL. The system uses three storage layers, each chosen based on whether the data must survive restarts, must be shared across workers, or is only needed during a single workflow execution.

**Worker Memory**

Data that exists only during a single workflow execution. It is created when processing begins and discarded when processing completes.

- Gmail thread context
- Parsed attachment text
- Intermediate AI inputs
- AI prompt templates
- Decision Engine business rules

This data does not need to survive restarts because the queue will retry the job, and the worker will reconstruct the context from Gmail.

**Redis**

Data that must be shared across workers but can be regenerated if lost. Redis acts as a shared cache to avoid unnecessary database reads and external API calls.

- OAuth access tokens (cached with a 50-minute TTL to avoid refreshing on every Gmail request)
- User preferences (cached with a short TTL since they are read on every workflow but rarely change)
- Rate limit counters for Gmail API quotas
- Queue jobs (Laravel's Redis queue driver is significantly faster than the MySQL queue driver)
- Workflow processing locks (prevents two workers from processing the same thread concurrently)
- Dashboard sessions

If Redis is flushed or restarted, access tokens are re-fetched from Google, preferences are re-read from MySQL and queue jobs are re-dispatched. Nothing is permanently lost.

**MySQL**

Data that cannot be reconstructed and must survive restarts, deployments and infrastructure failures.

- OAuth refresh tokens (irreplaceable without user re-authorization)
- ConnectedAccount records
- Workflow records
- Classifications
- AuditLog entries
- Processed notification idempotency keys
- Watch expiration and last history ID

The distinction is straightforward. If losing the data means a user has to take action or an email gets processed twice, it belongs in MySQL. If losing the data means one extra API call or a cache miss, it belongs in Redis or worker memory.

---

## Data Model

The database is intentionally small. Since Gmail already stores mailbox data, the database only needs to represent RevReply's own state.

> **Entity Relationship Diagram**

entities:

```
User
 ├── id
 ├── name
 └── email

ConnectedAccount
 ├── id
 ├── user_id
 ├── gmail_email
 ├── access_token
 ├── refresh_token
 ├── watch_expiration
 └── last_history_id

Workflow
 ├── id
 ├── connected_account_id
 ├── thread_id
 ├── latest_message_id
 ├── status
 ├── started_at
 └── completed_at

Classification
 ├── id
 ├── workflow_id
 ├── intent
 ├── confidence
 ├── risk
 ├── generated_draft
 ├── prompt_version
 └── model_version

AuditLog
 ├── id
 ├── workflow_id
 ├── event
 ├── metadata
 ├── created_at

ProcessedNotification
 ├── id
 ├── idempotency_key (unique)
 ├── connected_account_id
 └── processed_at
```

### Why these entities?

**ConnectedAccount**

A single RevReply user may connect multiple Gmail accounts. OAuth credentials, watch metadata and synchronization state belong to the connected account rather than the user.

**Workflow**

Each incoming email notification creates a workflow execution. This allows retries, resumable processing and workflow-level observability.

**Classification**

Stores the AI output instead of recomputing it every time the dashboard is opened. It also provides a historical record for evaluation and future model improvements.

**AuditLog**

Every significant workflow event is recorded for debugging and traceability.

Examples include:

- Gmail notification received
- Context created
- AI classification completed
- Draft created
- Auto reply sent
- Workflow failed
- Retry executed

**ProcessedNotification**

Stores idempotency keys for Gmail notifications that have already been processed. The idempotency key is derived from the connected account, `historyId` and latest message ID.

This table must be in MySQL rather than an in-memory cache because Pub/Sub can retry unacknowledged messages for up to 7 days. Worker restarts, horizontal scaling across multiple workers and cache eviction under memory pressure all make in-memory idempotency unreliable. A database table with a unique constraint guarantees that even if two workers race on the same notification, exactly one processes it.

A scheduled job prunes entries older than 14 days, which is beyond the 7-day Pub/Sub retention window. Even at high email volumes this table remains small.

# Email Processing Lifecycle

The following sequence describes the complete journey of a single incoming email.

> Email Processing Sequence
```
Customer
        │
        ▼
      Gmail
        │
 Push Notification
        │
        ▼
RevReply Ingestion
        │
        ▼
 Queue Job Created
        │
        ▼
 Queue Worker
        │
        ▼
 Fetch Thread
        │
        ▼
 Build Context
        │
        ▼
 AI Pipeline
        │
        ▼
 Decision Engine
        │
        ├───────────────┐
        │               │
        ▼               ▼
 Create Draft      Auto Reply
        │               │
        └──────┬────────┘
               ▼
          Update Dashboard
```
### Workflow

1. Gmail receives a new email.

2. Gmail publishes a push notification through Pub/Sub.

3. The ingestion endpoint validates the notification, creates a queue job and immediately acknowledges Pub/Sub.

4. A worker picks up the job and retrieves the latest conversation using Gmail's History API.

5. The Context Builder fetches

- Thread
- Attachments
- User Preferences

and converts them into a structured context object.

6. The AI Pipeline performs a single structured inference and returns

- Intent
- Confidence
- Risk
- Suggested Draft

7. The Decision Engine applies deterministic business rules.

Possible outcomes are

- Auto Reply
- Draft
- Escalation
- Ignore

8. The selected action is executed through the Gmail API.

9. Workflow state and audit history are recorded before marking the workflow complete.


### Workflow States

Each incoming email progresses through a small number of deterministic workflow states.

Received

↓

Queued

↓

Processing

↓

AI Complete

↓

Decision Complete

↓

Draft Created

↓

Waiting Approval

↓

Sent

or

Failed

Representing the workflow explicitly makes retries, observability and operational debugging significantly simpler than relying only on application logs.


# Failure Handling

Every stage of the workflow should either complete successfully or fail in a way that allows processing to resume without losing emails or producing duplicate actions.

| Failure | Impact | Recovery Strategy |
|----------|--------|-------------------|
| Gmail Push notification delivered more than once | Duplicate processing | Idempotency using `historyId` together with `threadId` and latest message ID |
| Gmail watch expires | No new notifications | Scheduled job (Connected Account Manager) renews the Gmail watch before expiration. Job runs daily, targeting accounts whose watch expires within the next 48 hours, giving a 24-hour failure tolerance. |
| Gmail watch expires before renewal job can run (e.g. multiple consecutive cron failures or service downtime) | Emails missed during the gap | Gap recovery is the responsibility of the Ingestion Component. It compares the current Gmail `historyId` against `last_history_id` stored in `ConnectedAccount`, calls Gmail's History API to recover missed messages, and enqueues them identically to live push notifications. The `last_history_id` is reliably stored and updated by the Connected Account Manager on every watch registration and renewal. |
| OAuth access token expires | Gmail API requests fail | Refresh access token automatically using the stored refresh token |
| Refresh token revoked | Gmail account disconnected | Mark account as disconnected and notify the user to reconnect |
| Queue worker crashes | Workflow interrupted | Queue automatically retries the job |
| Gmail API temporarily unavailable | Context cannot be built | Retry using exponential backoff |
| LLM API timeout | Classification unavailable | Retry once, then create a draft for manual review |
| LLM returns malformed JSON | Decision cannot be parsed | Retry with structured-output prompt + the parsing error, otherwise escalate for manual review |
| Draft creation fails | Reply cannot be saved | Retry the Gmail API call before marking workflow as failed |
| Database temporarily unavailable | Workflow state cannot be recorded | Retry database operation before failing the workflow |
| Dashboard unavailable | User cannot view workflow | Email processing continues independently; dashboard reflects latest workflow state once available |

---

## Real User Experience Failure Modes


| User Scenario | Mitigation |
|----------|------------|
| Customer sends another email while the workflow is still processing | Before taking an action, compare the latest `historyId`. If the conversation has changed, restart processing using the latest thread. |
| Duplicate Gmail notification | Ignore duplicate workflows using idempotency keys. |
| Duplicate draft creation | Check whether a draft already exists before creating another one. |
| Wrong customer thread | Always retrieve the latest Gmail thread immediately before AI processing. |
| Auto-reply loop | Ignore emails marked with `Auto-Submitted`, and include the appropriate `Auto-Submitted: auto-replied` header on outgoing replies. |
| AI produces an unsafe or low-confidence response | Decision Engine routes the workflow to Draft or Escalation instead of sending automatically. |
| Human edits a draft while a retry is executing | Workflow verifies current state before applying changes, preventing stale updates from overwriting user edits. |

---

## Idempotency

The system assumes that external systems may deliver duplicate notifications.

Instead of trying to prevent duplicates, the workflow is designed so that processing the same notification multiple times produces the same final result.

The idempotency key is derived from the connected account ID, Gmail `historyId` and the latest message identifier within the thread.

**Why this requires a database table instead of a cache**

Pub/Sub retries unacknowledged messages with exponential backoff capped at 10 minutes, and continues retrying for up to 7 days before dropping the message. This creates a window far too long for in-memory caching to be reliable.

In-memory caches do not survive worker restarts. When workers scale horizontally, each worker has its own memory, so a duplicate landing on a different worker would not be caught. Even Redis with a short TTL becomes unsafe if the TTL expires before Pub/Sub stops retrying.

The `ProcessedNotification` table uses an INSERT with a unique constraint on the idempotency key. The database enforces atomicity, so even if two workers race on the same notification, exactly one succeeds. This is the only approach that reliably survives restarts, multiple workers and the full Pub/Sub retry window.

This guarantees that retries, duplicate Pub/Sub deliveries and worker restarts do not result in duplicate replies or duplicate drafts.

---

## Retry Strategy

Retries are used only for transient failures.

- Gmail API failures use exponential backoff.
- Queue failures rely on the queue's retry mechanism.
- LLM failures are retried once before escalating.
- Permanent failures (revoked OAuth, invalid permissions, deleted mailbox) are not retried indefinitely and instead require user intervention.

This keeps the system responsive while preventing infinite retry loops.



# Scaling Strategy

The implementation demonstrates a single Gmail account, but the architecture is designed so that scaling primarily means adding more workers rather than redesigning the system.

Because the workflow is asynchronous and Gmail remains the source of truth, each email notification can be processed independently.

The system therefore scales horizontally at the worker layer.

---

## Stateless Components

Every processing component is stateless.

A worker receives a queue job, reconstructs the latest state from Gmail, processes the workflow and exits.

No worker depends on local memory from previous executions.

This allows additional workers to be added without changing application logic.

---

## Queue-Based Processing

The queue naturally absorbs traffic spikes.

For example, if hundreds of Gmail notifications arrive simultaneously, they are buffered in the queue while workers continue processing at their own rate.

Instead of overwhelming the application, load is smoothed over time.

Adding more workers increases throughput without changing the architecture.

---

## Gmail API Limits

The Gmail API is an external dependency and therefore becomes the primary scaling constraint rather than the application itself.

To operate reliably, the system should:

- Retry transient failures using exponential backoff.
- Respect Gmail API quotas.
- Avoid unnecessary API calls by retrieving only the required thread.
- Minimize repeated fetches during a single workflow execution.

---

## AI Provider Limits

The LLM provider is another external bottleneck.

The architecture isolates AI processing behind a single component, making it possible to:

- Switch providers.
- Upgrade models.
- Introduce batching.
- Introduce fallback models.

without affecting the rest of the workflow.

---

## Database Growth

The database stores only RevReply-owned state rather than mailbox contents.

As a result, database growth is proportional to workflow history instead of email volume.

This keeps storage requirements relatively small even as the number of connected Gmail accounts increases.

---

## Future Scaling

If production traffic grows significantly, the architecture can evolve without major redesign.

Possible improvements include:

- Multiple queue workers
- Distributed queue infrastructure
- Dedicated observability platform
- Separate AI worker pool
- Evaluation pipeline for continuous quality monitoring
- Multi-region deployment

None of these require changes to the overall workflow because component boundaries remain unchanged.

## Deployment Story

The system is deployed as a stateless Laravel application with independent queue workers.

Because expensive operations (Gmail fetches, AI inference and draft generation) execute asynchronously, the API remains lightweight while workers scale horizontally.

A typical deployment consists of:

- Laravel API
- Queue Workers
- MySQL
- Queue
- Gmail Pub/Sub
- LLM Provider

Only the queue workers require horizontal scaling as traffic grows. Since workers are stateless, additional instances can be added without changing application logic.

## Dashboard

The dashboard is workflow-centric rather than inbox-centric.

Each workflow card displays:

- Customer
- Conversation summary
- Intent
- Confidence
- Risk
- Current workflow state
- Suggested action
- Timestamp

Selecting a workflow expands the full Gmail thread together with the generated draft.

At the top level, the dashboard also exposes operational summaries:

- Drafts awaiting approval
- Auto replies sent
- Escalated conversations
- Failed workflows
- Average processing latency

# Observability

The system is designed so that every workflow execution leaves enough information behind to reconstruct **what happened**, **why it happened**, and **where it failed** without replaying the entire request.

Rather than treating observability as a separate subsystem, it is built into the workflow itself.

---

## Workflow State

Every incoming email creates a Workflow record.

The workflow progresses through deterministic states.

```
Received
↓

Queued
↓

Context Built
↓

AI Complete
↓

Decision Complete
↓

Draft Created

or

Sent

or

Failed
```

Because every state transition is persisted, the current status of every email can be queried directly from the database.

Example questions that can be answered immediately:

- Which workflows are currently processing?
- Which workflows failed?
- Which workflows are waiting for human approval?
- What stage is currently the bottleneck?

---

## Audit Trail

Every significant action performed during processing creates an Audit Log entry.

Example events:

- Gmail notification received
- Queue job created
- Thread fetched
- AI inference completed
- Decision generated
- Draft created
- Auto reply sent
- Retry executed
- Workflow failed

Each event contains

- Workflow ID
- Timestamp
- Event Type
- Metadata
- Correlation ID

This provides a complete chronological history for every processed email.

---

## Correlation IDs

Each workflow receives a Correlation ID when it is first ingested.

That identifier is propagated through:

- Queue Job
- Context Builder
- AI Pipeline
- Decision Engine
- Gmail Actions

Searching a single Correlation ID reconstructs the complete processing path of one email.

---

## Operational Metrics

Most operational metrics are derived directly from the Workflow and Audit tables rather than maintained separately.

| Metric | Derived From |
|---------|--------------|
| Emails Processed | Workflow table |
| Processing Latency | Workflow.started_at → completed_at |
| Queue Wait Time | Audit events (Queued → Processing) |
| AI Latency | Audit events (AI Started → AI Completed) |
| Draft Rate | Classification table |
| Auto Reply Rate | Classification table |
| Escalation Rate | Classification table |
| Workflow Failure Rate | Workflow status |
| Retry Count | Audit events |
| Gmail API Failures | Audit events |
| OAuth Refresh Failures | Audit events |

Because these metrics are derived from persisted workflow history, historical trends can be analyzed without requiring external monitoring infrastructure.

---

## Production Monitoring

For the purposes of this assignment, metrics can be queried directly from MySQL.

In production, the same workflow and audit events would additionally be exported to an observability platform (for example OpenTelemetry, Prometheus/Grafana or Datadog) for dashboards, alerting and long-term retention.

The architecture does not depend on a particular monitoring tool because observability is produced by the workflow itself rather than by the infrastructure.

# Deliberately Left Out

The goal of this assignment was to design a production-ready Gmail processing pipeline, not to build every surrounding product feature. During the design process I deliberately limited the scope whenever it didn't change the core architecture.

The following decisions were made intentionally.

| Decision | Why it was left out |
|----------|----------------------|
| **User Authentication** | Authentication is assumed to already exist. The implementation starts with an authenticated RevReply user so the focus remains on Gmail processing and AI orchestration. |
| **Organization / RBAC** | The assignment describes a single user connecting Gmail accounts. Multi-organization access control doesn't affect the email processing pipeline and was intentionally kept out of scope. |
| **Google Workspace Administration** | Gmail API works for both personal Gmail and Google Workspace mailboxes. Domain-level administration and organization management are product concerns rather than architectural concerns for this assignment. |
| **Google Pub/Sub JWT Verification** | In production, the webhook should verify Google's signed Pub/Sub JWT / OIDC token before accepting a request. I deliberately left the full provider-specific verification implementation out of scope here because the assessment is focused on ingestion architecture, idempotency, workflow persistence and queue-driven processing. |
| **Sensitive Email Filtering** | I considered filtering banking emails, OTPs and other sensitive messages. Instead, I assumed users connect a mailbox intended for sales communication. This keeps the processing pipeline simpler while avoiding incorrect filtering decisions. |
| **Mailbox Synchronization** | Emails, threads, attachments and drafts are never copied into our database. Gmail remains the source of truth and data is fetched when needed. |
| **Refresh Token Recovery UX** | If a refresh token is revoked, the backend detects the failure and marks the account as disconnected. A complete notification and reconnect experience would be one of the first product features added later, but it was intentionally left out of this implementation. |
| **Evaluation Pipeline** | The architecture stores workflow history and AI decisions so evaluations can be added later. The implementation itself focuses only on inference. |
| **Multi-stage AI Workflow** | I intentionally chose a single structured prompt instead of classifier → critic → evaluator workflows. Additional reasoning stages should only be introduced if production evaluations show they improve quality enough to justify the added latency and complexity. |
| **Additional Context Sources** | The Context Builder was designed so CRM systems, internal knowledge bases and customer history can be plugged in later. Since the assignment doesn't require them, the current implementation only uses Gmail context and user configuration. |

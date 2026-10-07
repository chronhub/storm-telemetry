# Storm Telemetry

**Opt-in observability** for the Storm framework. Installing this package swaps the **Null**
observability defaults shipped by Chronicler and Projector for real implementations — structured
logs, a saga-history trail, and health checks. Nothing here runs until the package is installed, and
the observed modules never depend on it.

## When you reach for it

- **structured operation logs**: install the package — event-store appends/loads and projector
  batches/runs start logging, no further wiring;
- **a saga timeline**: point the `SagaHistorySink` alias at a real sink and read one correlation's
  history with `storm:telemetry:history`;
- **health probes**: implement `HealthCheck` for your own checks — the shipped DBAL ping and
  outbox-liveness aggregate with yours, worst-wins;
- **a Prometheus scrape**: `storm:telemetry:metrics` (and the ops HTTP twin) renders the tagged
  `MetricsCollector` blocks — sagas, outbox/inbox, projections, the history counters — as one text
  exposition.

## What it provides

- **`StormObservability`** — the facade implementing every module's observability port. The v1 fanout
  is one structured Monolog line per recorded operation: two routing fields (`module` / `operation`)
  plus the operation's Context DTO flattened into the log context. Event-store appends/loads and
  projector batches/runs log at `info`; OCC conflicts and failed runs at `warning` / `error`.
- **`SagaHistorySubscriber`** — normalizes each `Saga*` domain event into a `SagaHistoryEntry` (with a
  stable `eventId` and the announce-time `occurredAt`) and forwards it to the configured
  `SagaHistorySink`. **Opt-in three times over**: installing the package wires the `Null` sink —
  nothing is written until the app points the `SagaHistorySink` alias at `LogSagaHistorySink`,
  `TableSagaHistorySink` (needs `storm:telemetry:install`; read one saga's timeline with
  `storm:telemetry:history`, prune with `storm:telemetry:prune`), an
  async publishing sink, or a `FanOutSagaHistorySink` it composes. Best-effort at every stage: a write
  failure is logged and dropped, never propagated back into the committed saga.
- **`HealthChecker` + `HealthCheck`** — on-demand health probes aggregated worst-wins into one status.
  The framework ships a DBAL ping and an outbox-liveness check; apps add their own by implementing
  `HealthCheck` (auto-tagged `storm.health_check`).
- **`MetricsExposition` + `MetricsCollector`** — stateless at-rest metrics, collected at scrape time
  from the live tables, never a process registry. Each collector runs behind a per-scrape
  `statement_timeout` and a per-collector backstop: a block that throws, times out, or collides on a
  family name is dropped and counted in `storm_telemetry_collector_errors`, never a dead endpoint.
  Scrape cost grows with the tables it reads — `workflow_history` above all, whose lever is
  `storm:telemetry:prune`.

**The hooks run inside the observed paths** — `recordAppend` inside the append transaction,
`recordBatch` once per projector cycle — so the fail-open contract has a second half: handlers must
not block. Buffered or local Monolog handlers, never a synchronous socket to a remote collector; and
stamp your ambient correlation id with a Monolog processor rather than expecting it in the context.

## Optional OpenTelemetry export

Tracing is disabled by default and does not replace operation logs or metrics. To enable the
SDK adapter, install its runtime dependencies in the consuming application. The Symfony
integration uses DoctrineBundle's connection registry. Enable `ext-curl` built with asynchronous
DNS support; activation refuses other HTTP backends because their DNS lookup can exceed the
export deadline:

```sh
composer require open-telemetry/sdk:^1.15 open-telemetry/exporter-otlp:^1.4 nyholm/psr7:^1.8 symfony/http-client:^8.1
```

```yaml
storm:
    tracing:
        enabled: true
        endpoint: 'http://alloy:4318/v1/traces'
        service_name: 'my-application'
        sample_ratio: 0.01
        queue_capacity: 2048
        batch_size: 256
        export_budget_ms: 200
```

The endpoint is the complete OTLP HTTP traces URL. Use an endpoint reachable from the application
container. Enabling tracing without Telemetry or the required SDK dependencies fails container
compilation with an explicit error. Disabled tracing does not load SDK-dependent services.

`storm.tracing.provider` is a dedicated SDK provider, available for explicit injection. It does
not replace the application's global provider. Enabling tracing instruments Storm's buses, handler
invocations, inbox attempts, append attempts and event-store outbox publishing. HTTP ingress, saga
steps and projection batches require separate instrumentation.

Ending a sampled span only appends its snapshot to a bounded queue. A full queue drops incoming
spans. The processor exports only on an explicit `forceFlush()` call; it checks every configured
Doctrine connection before each batch and refuses export while any transaction is active,
including an outer transaction after a savepoint has completed. Connections created outside the
Doctrine registry are outside that guard and must be included by a custom guard when constructing
the processor directly.

The Symfony lifecycle drains after Messenger iterations, including idle iterations, and HTTP
termination. Worker stop and Console termination perform shutdown. The batched consumer drains
between iterations after acknowledgments, while idle, and when leaving its loop. The event-store
outbox command drains after each relay call, including failure and idle returns. Both callbacks
swallow observation failures and retain the multi-connection transaction guard. Other custom runners must call
`DeferredSpanProcessor::forceFlush()` after releasing all transaction and fence ownership, including
non-database fences. Do not flush from a span callback, an inner bus dispatch, or a projection
checkpoint callback that still owns the transaction. A runner without an explicit safe hook only
gets a final Console drain; it is not continuously instrumented by this adapter.

One monotonic deadline covers all batches in a drain. The remaining budget sets HTTP inactivity
and total request deadlines; automatic retries and redirects are disabled. Serialization consumes
that same budget before the HTTP request starts. PHP cannot preempt synchronous serialization or
user-supplied exporters; the deadline is not a hard real-time execution guarantee. Span attribute,
event and link counts and attribute string lengths are bounded by the provider. Custom exporters
must honor the shared deadline if they replace the supplied transport.

Each worker iteration with queued spans can spend the entire export budget during a collector
outage. There is no outage backoff; account for this latency when choosing the budget and sampling
ratio. A remaining budget below one millisecond starts no HTTP request because curl truncates its
total timeout to whole milliseconds.

Failed batches are dropped and never retried by Storm. A regular drain retains spans it could not
start before the deadline or because of an open transaction. Shutdown discards those remaining
spans and closes the processor. `queued()` and `dropped()` expose process-local counts. Export
failure does not escape into business execution. The official SDK retains its own diagnostic
logging configuration, including `OTEL_LOG_LEVEL`; configure it deliberately for sustained collector
outages. A killed process can lose queued spans.

### Propagate message creation context

When enabled, Storm captures the currently active span into a dedicated `TraceContextStamp` before
Messenger sends a new command or event. Deferred dispatches capture before Messenger queues them,
so they retain the creator even after its span ends. Stored events and saga commands keep their
creation parent on every publication; historical messages without a trace keep no origin.

The message enricher captures newly recorded events. Saga `HopProtocol` uses the narrow
`TraceContextPropagation` contract at sealing, without invoking application enrichers. The neutral
wire uses `traceparent` and `tracestate` in its `header` object. Neither business correlation nor
causation is replaced by a trace identifier.

```yaml
storm:
    tracing:
        enabled: true
        trusted_transports: [storm_events, storm_commands]
```

The list names actual Messenger receiver names, including each priority lane used by the application.
It defaults to empty. Configure one neutral serializer per receiver: the key under
`neutral_transports` must match that receiver's name, and that receiver must use
`storm.neutral_transport.<same-name>`. Do not share a serializer between receivers or priority lanes:
the decoder checks the serializer's configured name, while consumption checks the actual receiver.
Both must apply the same trust decision. Only list transports whose producers the deployment controls. This setting is
independent of `neutral_transports.<name>.trusted_identity`; granting actor or tenant provenance does
not admit a remote trace. The regular worker uses its received transport stamp. The batch consumer
carries the real receiver name through initial processing and isolation retries. Custom batch callers
without that provenance are untrusted.

Untrusted arrivals start with an empty trace root, so the next locally created span uses the provider's
root sampling policy instead of an incoming sampled flag. The neutral decoder removes their trace
headers before hydration, preventing later re-encoding from promoting the rejected parent. Native PHP
serialization requires trusted producers regardless of tracing; this option does not make PHP
unserialization safe for foreign input. The standard Messenger failure replay restores the original receiver name before tracing, so it
keeps that original policy. A custom failure consumer must supply equivalent provenance or remain
untrusted.

The official W3C propagator validates admitted carriers. Wrong types, invalid IDs and overlong values
are ignored for tracing without rejecting the business message. Each field is bounded to 512 bytes
before extraction. Scopes restore their caller in `finally`, including exceptions and nested dispatch.
Baggage is not injected or extracted. With tracing enabled, `traceparent`, `tracestate` and `baggage`
cannot be declared in `context.propagated_keys`.

Context capture and activation perform no export. Use `storm.tracing.provider` for application spans;
Storm does not replace the global OpenTelemetry provider.

### Execution spans

| Span | Actual boundary | Meaning of success |
|---|---|---|
| `storm.message.dispatch` | Local Storm bus call, outside recovery and deduplication | The bus returned; an asynchronous send need not execute a handler |
| `storm.message.process` | Received delivery or a `BatchModeStamp` dispatch | This delivery returned; the enclosing batch can still roll back |
| `storm.handler.invoke` | Messenger invokes a selected, not-already-handled descriptor | That invocation returned, independently of its siblings or transaction |
| `storm.inbox.batch` | Batched inbox call, including transaction return | The store returned; `storm.transaction.outer` identifies an enclosing default DBAL transaction |
| `storm.inbox.attempt` | Per-message inbox call, including isolation retries | `storm.outcome` distinguishes `completed`, `duplicate` and `failed` |
| `storm.es.append` | Each decision/fact writer attempt, inside retry and outside its transaction decorator | The append returned, not necessarily a durable outer commit |
| `storm.outbox.publish` | Event-store publisher port, including its direct sender handoff | The publisher returned; acknowledgment loss can still produce a retry |
| `storm.saga.step` | The saga unit-of-work port, including fence acquisition and transaction return | `storm.saga.outcome` is `completed` or `busy`; completed does not imply a mutation or durable outer commit |
| `storm.saga.timer` | One timer-target invocation, including its driven step | `storm.saga.applied` preserves the engine's boolean result; false can mean a stale timer or busy fence |
| `storm.saga.outbox.publish` | Saga command publisher, including its bus call | The publisher returned; the original creation headers remain unchanged |
| `storm.projection.batch` | One transaction attempt in the persistent projection runner | The transaction returned; applied counts describe this attempt and are not committed-effect counts |

Handler instrumentation yields the original Messenger descriptors. Their names, options, batch
handler identity, `HandledStamp` behavior and exception mapping remain Messenger-owned. The scope
starts before invocation and detaches when Messenger resumes its handler iterator. Its end timestamp
is retained while Messenger finishes classifying named failures; no duration callback is converted
into a historical span. Timing includes the small amount of Messenger bookkeeping before iterator
resumption. All native Symfony `BatchHandlerInterface` descriptors pass through without a handler span,
including synchronous calls without an `AckStamp`. Their invocation
can enqueue work executed by a later `flush()` or deferred acknowledgment, which needs a separate
owned boundary. Storm's inbox batch consumer executes ordinary handlers and has separate transaction
and per-message process spans.

A batch transaction is a root with links to admitted creation contexts, capped at 128 links, with
`storm.links.omitted` counting excess carriers after that cap. Each process span retains its own
message's admitted parent and upstream sampling decision. Isolation retries create distinct attempt,
process and handler spans. Successful handlers in a failed batch remain local successes; the failed
batch span describes the failed store call. No span claims a durable commit, even when an outer
transaction is absent. Duplicate counts belong to the inbox, with no invented handler invocation.

Publish attempts adopt the stored creation context without rewriting it. A stored message without
one starts a local root. Error attributes contain exception class names and the immediate cause's
class only; no error message, stack trace, payload, generic context bag or SQL is recorded. Span names
are fixed. Technical string attributes are capped at 256 bytes. Runtime tracing failures do not retry
business work or replace its result or exception.

These hooks instrument the standard Storm-owned Messenger stack. Avoid enabling a second
instrumentation owner on the same boundaries. A custom runner, connection outside Doctrine's registry,
or non-database fence requires its own safe export boundary.

Saga steps inherit the active processing context. The step span covers the fenced unit of work;
announcement dispatch after that unit returns belongs to the caller, not the step span. A nested
savepoint release is not a durable commit. `storm.transaction.outer` describes the default saga
connection at entry. Append, inbox and saga step observers follow Doctrine's `database_connection`
alias, including when the configured default connection has a different name. Replacing the saga
connection or fence requires matching that composition.

Each timer invocation starts a short root, even under an active caller span, and restores the caller
afterward. Timers have no persisted arming trace context: there is deliberately no parent or link
back to arming, no correlation-ID inference, and no span open during the wait. A timer's driven
step is a child of that short root.

Each persistent projection transaction attempt starts its own root before work begins, including
empty scans and failed retries. After the read, stored event origins become bounded links on that
span; no first-event parent is chosen. Links discovered during the transaction cannot participate
in the initial head-sampling decision. The local root sampling policy governs the batch, while
links preserve the recorded origins. `storm.projection.events` is the number read, and
`storm.projection.applied` counts successful apply returns within the attempt, even when a later
failure rolls them back. `storm.transaction.outer` refers to that projection's actual lane
connection. Ad-hoc query folds are outside this persistent-runner boundary.

Timer and saga-relay commands drain after each tick/drain returns or throws, including empty
iterations. The projection runner drains after each transaction attempt settles, before retry
backoff or idle sleep. These drains use the same registry-wide transaction guard as other workers;
an ambient transaction on any configured connection defers export. No flush occurs inside a saga
step, timer drive, publisher or projection apply. A standalone composition must inject equivalent
observation and drain callbacks or own their lifecycle explicitly.

### Verify a real SDK-free installation

The CI quality job runs `make tracing-no-sdk`. From the monorepo root, the equivalent native commands prepare an independent Composer tree and run its isolated boot tests:

```sh
php tests/Fixtures/TracingNoSdk/prepare.php
composer --working-dir=tmp/tracing-no-sdk update --no-interaction --no-scripts
php tmp/tracing-no-sdk/vendor/bin/phpunit --no-configuration --bootstrap tmp/tracing-no-sdk/vendor/autoload.php tests/Fixtures/TracingNoSdk/TracingOptionalBootTest.php
```

The fixture removes OpenTelemetry dependencies before dependency resolution. It asserts that the
SDK is absent from Composer and the autoloader, then verifies disabled boot and explicit refusal
when tracing is enabled. It does not mask installed SDK classes.

## Design

- Each observability **port lives with the module it observes** (`Storm\Chronicler\Telemetry`,
  `Storm\Projector\Telemetry`) — not in Contracts. Those modules ship a **Null** impl as their default,
  so they boot with zero pollution and zero Telemetry dependency.
- Installing this package **overrides** the Null aliases (last `services.php` import wins) so the real
  `StormObservability` takes over — the framework bodies stay identical.
- Saga needs no port: the engine already dispatches `Saga*` domain events at its persist step, and
  `SagaHistorySubscriber` consumes them.

The dependency direction is always **observer → observed**: Telemetry depends on Chronicler, Projector
and Saga; they never depend on Telemetry.

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Experimental 0.x: this package changes without deprecation cycles — no backward-compatibility
promise and no legacy layer. Pin an exact 0.x tag or commit for reproducibility; pinning fixes
history, not a stable API. Schema changes are resets, not migrations, and a reset destroys data,
so it stays on disposable environments.*

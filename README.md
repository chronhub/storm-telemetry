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

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*

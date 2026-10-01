# Action Scheduler API — PayBridge Integration Contract

> This document describes how PayBridge uses Action Scheduler for background processing.
> It is not a substitute for the official Action Scheduler documentation.
>
> **Official source:** <https://actionscheduler.org/>
> **Last verified:** 2026-09-30

---

## 1. Group and initialization

All PayBridge background tasks are grouped under a single identifier.

| Property | Value |
|---|---|
| Group | `paybridge-for-plaid` |
| Initialization hook | `action_scheduler_init` |

**Source:** [`Scheduler`](../../src/Background/Scheduler.php)

---

## 2. Scheduled Hooks

PayBridge registers the following actions with Action Scheduler:

### 2.1 `paybridge_plaid_transfer_event_sync`

Triggered by incoming webhooks to sync Transfer events from Plaid.

| Property | Value |
|---|---|
| Scheduling function | `as_schedule_single_action()` |
| Frequency | On-demand (every verified `TRANSFER_EVENTS_UPDATE` webhook, whether or not reconciliation is scheduled) |
| Delay | Immediate (`0`); follow-ups when more events or processable rows remain: 1 minute, exponential backoff up to 15 minutes after failures |
| Scope | The configured Plaid account's stream (ADR-0018) |
| Unique | Yes (`true` flag) - duplicate webhooks coalesce |
| Handler | `Scheduler::run_event_sync()` → `EventSyncService::run()` |

### 2.2 `paybridge_plaid_reconcile`

Recurring background task to catch missed webhooks and ensure payment states are updated.

| Property | Value |
|---|---|
| Scheduling function | `as_schedule_recurring_action()` |
| Frequency | Every 15 minutes (`15 * MINUTE_IN_SECONDS`) |
| Unique | Yes (`true` flag) |
| Handler | `Scheduler::run_reconciliation()` → `ReconciliationService::run()` |
| Condition | Scheduled while maintenance is active: credentials and a valid environment exist AND (the gateway is enabled OR the configured Plaid account has work: monitored payments, open refunds, processable events or a failed event sync). Unscheduled when no work remains; disabling the gateway alone never unschedules it while work exists (ADR-0014, ADR-0021). Checked on admin, cron and WP-CLI requests only. |

### 2.3 `paybridge_plaid_reconcile_continue`

Continuation task when reconciliation has more records to process than fits in one time budget.

| Property | Value |
|---|---|
| Scheduling function | `as_schedule_single_action()` |
| Frequency | On-demand (triggered if `ReconciliationService::run()` returns `more => true`) |
| Delay | 1 minute (`MINUTE_IN_SECONDS`) |
| Unique | Yes (`true` flag) |
| Handler | `Scheduler::run_reconciliation()` → `ReconciliationService::run()` |

---

## 3. Worker constraints

Action Scheduler runs tasks via WP Cron or WP CLI. PayBridge tasks are designed to be safe under concurrency:

- Tasks use `DatabaseMutex` (MySQL `GET_LOCK()`) to prevent concurrent execution of the same process.
- Execution time is bounded internally (e.g., `EventSyncService` yields after `TIME_BUDGET_SECONDS = 40`).
- If a task runs out of time, it enqueues a continuation task (or relies on the next recurring run).

---

## 4. Lifecycle management

- **Activation:** Action Scheduler relies on WooCommerce to bundle and load the library.
- **Deactivation/Uninstall:** PayBridge cleans up its scheduled actions via `as_unschedule_all_actions(..., 'paybridge-for-plaid')`. It never touches actions belonging to other plugins.

**Source:** [`Scheduler::unschedule_all()`](../../src/Background/Scheduler.php), [`paybridge-for-plaid.php`](../../paybridge-for-plaid.php)

---

## 5. Freshness protocol

Before modifying Action Scheduler integration code:

1. Read this document.
2. Read the official Action Scheduler API docs.
3. Update this document if the external contract changed.

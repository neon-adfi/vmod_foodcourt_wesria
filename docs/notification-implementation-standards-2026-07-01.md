> Wesria MVP scope: the Gaia/FCM notification subsystem described here is historical and excluded. Use native authentication/recovery/invitation mail when Phase 2 implements it; follow the authoritative ERP MVP plan.

# Notification Implementation Standards

> **Audience:** AI agents and engineers adding or changing ERP UI notifications.
> **Scope:** In-app bell notifications, approval-required/processed lifecycle, notification events, recipient resolution, deep links, push dispatch handoff, and tests.
> **Reference pattern:** Quote approval and supplier PO unlock notifications.

---

## 1. Default Pattern

Use the existing `Gaiafoodtech.Notification` plugin as the single notification surface.

New notification features should follow this flow:

1. Domain service completes the business state change.
2. Domain service fires a named event with the domain model as the first argument.
3. `plugins/gaiafoodtech/notification/Plugin.php` listens to the event.
4. `InAppApprovalNotificationService` resolves recipients, builds context, and writes `UserNotification` rows.
5. After the row commits, the `UserNotification` observer automatically sends the generic email and FCM copy.
6. The header bell reads unresolved notifications for the logged-in frontend user.
7. Clicking the bell item calls `erpNotificationManager::onMarkRead` and redirects to `target_url`.

Reference files:

- `plugins/gaiafoodtech/notification/Plugin.php`
- `plugins/gaiafoodtech/notification/classes/services/InAppApprovalNotificationService.php`
- `plugins/gaiafoodtech/notification/classes/services/UserNotificationFanoutService.php`
- `plugins/gaiafoodtech/notification/models/UserNotification.php`
- `plugins/gaiafoodtech/notification/observers/UserNotificationObserver.php`
- `plugins/gaiafoodtech/notification/components/NotificationManager.php`
- `themes/gaiafoodtech/partials/site/header.htm`
- `themes/gaiafoodtech/layouts/default.htm`

Do not create feature-local notification tables, feature-local bell dropdowns, or direct header queries.

---

## 2. Storage Contract

All ERP bell notifications use `gaiafoodtech_notification_user_notifications`.

Required row contract:

- `user_id`: frontend user recipient from `users.id`.
- `company_id`: owner company for workspace scoping when available.
- `notification_type`: lifecycle class, for example `approval_required` or `approval_processed`.
- `source_type`: stable feature source key, for example `print_quote_approval`.
- `source_id`: integer ID of the source model when available.
- `source_uuid`: UUID of the source model when available.
- `dedupe_key`: stable idempotency key unique per user.
- `title`: short bell title.
- `body`: compact context line.
- `target_url`: route opened after click.
- `payload_json`: structured context for tests, diagnostics, and future UI.
- `read_at`: set when the user opens the notification.
- `resolved_at`: set when the notification no longer needs to stay in the bell.

Rules:

- Create required-action rows with `UserNotification::firstOrCreate()` using `['user_id' => ..., 'dedupe_key' => ...]` as the identity. Event replay must preserve the existing row's read/resolved state and must not trigger created-observer fan-out again.
- Reserve `UserNotification::updateOrCreate()` for processed notifications whose content can legitimately be refreshed, or for an explicitly approved active-row resync. Preserve the existing `read_at` and `resolved_at` values when updating.
- Keep string fields within the enforced `UserNotification` limits: `uuid` and `source_uuid` max 36 characters, `notification_type` max 80, `source_type` max 120, `dedupe_key` max 190, `title` max 255, and `target_url` max 255.
- Never rely on title/body parsing for behavior. Put machine-readable fields in `payload_json`.
- Do not mutate historical resolved notifications unless the feature explicitly requires a targeted active-row resync.
- Do not store backend users as recipients. ERP bell recipients are frontend users.

---

## 3. Event Contract

Notification producers should be domain services, not Twig partials or JavaScript.

Event naming:

```php
Event::fire('vendor.plugin.domain_action.requested', [$model]);
Event::fire('vendor.plugin.domain_action.resolved', [$model]);
```

Current examples:

- `vmod.printcore.supplier_po_unlock.requested`
- `vmod.printcore.supplier_po_unlock.resolved`
- `vmod.printcore.quote_approval.requested`
- `vmod.printcore.quote_approval.resolved`

Rules:

- Fire the event after the domain row exists and has the UUID/status needed by the notification.
- The first event argument must be the source model.
- Additional arguments must be backward compatible. Existing listeners should keep working if they only accept the first argument.
- Do not write notification rows directly from the domain service.
- Do not fire notification events before transaction-sensitive validation has passed.
- If no recipient exists and the business action must not proceed, throw from the notification service and let the domain transaction roll back.
- If notification storage is optional during deployment, use the existing storage availability guard and log/no-op.

---

## 4. Notification Types And Lifecycle

Use `notification_type` to define read/resolve behavior.

Supported lifecycle patterns:

- `approval_required`: action is still pending. Reading marks `read_at` only. The row stays unresolved until the approval source is approved, rejected, consumed, cancelled, or otherwise terminal.
- `approval_processed`: informational result notification. Reading marks both `read_at` and `resolved_at` through `UserNotification::shouldResolveOnRead()`.

Rules:

- Add a model-level lifecycle rule before adding a new type with special read behavior.
- Resolve pending approval notifications from the same domain decision path that makes the source terminal.
- Resolve all pending notifications for a terminal approval source, not only the acting user row.
- Replayed events must not duplicate rows or reopen already resolved informational rows.
- Do not hide unresolved required-action notifications just because the user clicked them.

---

## 5. Recipient Resolution

Recipient resolution belongs in the notification service or a dedicated resolver called by it.

Rules:

- Resolve frontend users only.
- Apply company/workspace scope before writing rows.
- Check the exact permission needed for the action target, not a nearby page permission.
- Prefer existing workspace permission helpers or the current workspace row/role permission shape.
- Exclude users outside the source company.
- Exclude users who only have partial permissions when the action requires a permission combination.
- If no recipient exists, choose explicitly:
  - Throw an `ApplicationException` when the business action depends on an approver.
  - Log and skip when the notification is informational.

Current examples:

- Supplier PO unlock required notification: user needs `print.quote.approval` plus `print.costing.read` or `print.costing.write`.
- Quote approval required notification: pending frontend approver steps provide explicit recipient users.
- Quote approval processed notification: recipient is `print_request.pic_user_id`; no PIC means log and skip.

---

## 6. Context Payload

Every notification must include enough context for support, tests, and future UI improvements.

Minimum `payload_json` fields:

- Source UUID, such as `unlock_uuid` or `version_uuid`.
- Parent document UUID/no when available, such as request UUID/no or quote UUID/no.
- Company ID.
- Actor user ID/name when the notification reports a processed decision.
- Target-specific identifiers needed to reopen the UI, such as approval step UUID or request item UUID.

Rules:

- Use explicit snake_case keys.
- Include both human numbers and UUIDs where both exist.
- Keep nullable fields as `null`; do not invent fake UUIDs.
- Keep `body` short and readable. Long diagnostic detail belongs in `payload_json`.

### Compact display copy

The shared bell and notification center stay source-agnostic. Each source context builder owns one compact body using ` · ` separators in this order: descriptive title, action-relevant value or actor, then document reference. Quote approval bodies show the request title and formatted grand total; processed rows may add `by {actor}`. Copy-unlock and void rows show the request title and requesting/deciding actor. Supplier PO unlock rows show item title, supplier, and PO number. Reasons remain in `payload_json` and are not rendered in the compact body.

- Clip descriptive titles to 48 characters and actor/supplier names to 32 characters using `...`.
- Omit unavailable optional fields without leaving duplicate separators.
- Treat a numeric zero as a valid displayed amount.
- Resolve quote currency before request currency; omit an amount when either value or currency is unavailable.
- Keep quote number plus version, or the request-number fallback, as the final quote-related field.
- Render the body on one line with CSS ellipsis in both shared UI partials. Do not add source-specific Twig branches.

---

## 7. Target URL And Deep Link Rules

`target_url` must open the exact surface where the recipient can understand or act on the notification.

Rules:

- Prefer direct object pages over list pages.
- Include the query parameter required to open the intended offcanvas/panel directly.
- Keep old routes backward compatible when changing a notification target.
- Add a frontend contract test for each deep-link query parameter.
- Do not mark a notification read in JavaScript before `onMarkRead()` succeeds.
- `NotificationManager::onMarkRead()` remains generic: mark read, return `redirect_url`.

Current examples:

- Quote approval required: `/print/quotes/{quote_uuid}?quote_version={version_uuid}`
- Quote approval processed: `/print/quotes/{quote_uuid}?quote_version={version_uuid}`
- Supplier PO unlock: `/print/costings/{request_item_uuid}?supplier_po_unlock={unlock_uuid}`

Fallbacks are allowed only when the direct object UUID is unavailable. Fallback routes must still be permission-safe for the recipient.

---

## 8. Push And Email Handoff

Every newly created `UserNotification` automatically fans out to generic email and FCM after its database transaction commits.

Rules:

- Feature/domain services create the bell row only. They must not send a second email or fire a second FCM copy for that row.
- Fan-out runs from the `UserNotification` created observer through `UserNotificationFanoutService` and is scheduled with the model connection's `afterCommit()` callback. Rolled-back rows send nothing.
- Email and FCM both use the same safe bell target. Unsafe or missing targets fall back to the notification center, and FCM receives the absolute application URL.
- Email and FCM failures are isolated from each other and from the saved bell row. Log only the sanitized notification UUID, source type, frontend user ID, channel, and safe message; never log recipient email addresses, device tokens, credentials, or provider payloads.
- Missing/invalid email skips only email. Missing FCM tokens skip only FCM delivery.
- The shared fan-out has no retry queue, delivery-status column, or per-channel delivery state. Do not imply retry or confirmed delivery in feature code or UI.
- Replay-safe notification creation must not create a new row or reopen an existing read/resolved row; therefore it must not send another email or FCM copy.
- Tests must prove after-commit delivery, rollback suppression, the four-field FCM event contract, safe absolute target URL, channel failure isolation, and no replay delivery.

---

## 9. Header Bell Contract

The default layout owns the header bell notification component.

Required layout/header contract:

- `themes/gaiafoodtech/layouts/default.htm` mounts `[notificationManager erpNotificationManager]`.
- `themes/gaiafoodtech/partials/site/header.htm` reads `erpApprovalNotifications`.
- The unread badge uses `erpNotificationUnreadCount`.
- Bell item click uses `erpNotificationManager::onMarkRead`.
- Successful click redirects to `data.redirect_url`.
- Short polling uses `themes/gaiafoodtech/assets/js/erp-notifications.js` and `erpNotificationManager::onRefreshNotifications`.
- Polling updates `#erpNotificationUnreadBadge`, `#erpNotificationDropdownItems`, and, on `/account/notifications`, `#erpNotificationActiveRows` plus `#erpNotificationAllRows`.

Rules:

- Do not mount duplicate notification manager aliases on feature pages.
- Do not query notifications from feature partials.
- Keep empty/missing storage behavior safe: unauthenticated users and pre-migration deployments show zero notifications.
- Keep header UI generic. Feature-specific labels belong in notification title/body and payload.
- Do not add feature-local polling, WebSocket, or push listeners for the bell UI. Reuse `onRefreshNotifications()` so all live refresh paths share one server contract.

---

## 10. Live Refresh Contract

Current live UI refresh is short polling, not WebSocket.

Rules:

- `erp-notifications.js` polls every 60 seconds.
- The poll pauses while `document.hidden` is true and skips overlapping requests.
- Polling sends `include_center=1` only when `[data-notification-center-card]` exists.
- `NotificationManager::onRefreshNotifications()` must stay generic and user-scoped.
- Refresh responses must only include rows for the authenticated frontend user.
- Missing storage returns empty UI fragments and unread count `0`.
- A `401` response stops polling on the client.
- If a future SSE/WebSocket trigger is added, it should call the same refresh endpoint instead of duplicating notification rendering logic.

---

## 11. Implementation Checklist

Before adding a notification feature:

- Identify the source model and terminal states.
- Decide whether it is required-action or informational.
- Define `notification_type`, `source_type`, `dedupe_key`, `target_url`, and payload keys.
- Define recipient permission rules and company scope.
- Confirm the target route/offcanvas is reachable by those recipients.
- Decide whether no-recipient should throw or skip.

During implementation:

- Add event firing in the domain service.
- Add listener wiring in `Gaiafoodtech.Notification` plugin registration.
- Add a create method and, for required-action notifications, a resolve method.
- Use create-only `firstOrCreate()` by `user_id` and `dedupe_key` for required-action rows.
- Use state-preserving `updateOrCreate()` only for processed notifications or an explicitly approved active-row resync; retain the existing `read_at` and `resolved_at` values.
- Use the storage availability guard when code may run before migration.
- Add or update deep-link handling in the target page JavaScript.

Do not:

- Put recipient logic in Twig.
- Put notification writes in JavaScript.
- Use backend user IDs for ERP bell recipients.
- Add a migration for every new notification source.
- Use free-form `source_type` strings scattered across files; centralize them as constants in the service when practical.

---

## 12. Testing Standard

Every non-trivial notification feature needs the smallest runnable check that proves the lifecycle.

Required PHP coverage:

- Storage/model contract exists when adding new storage behavior.
- Create path writes one row per eligible user.
- Ineligible users do not receive rows.
- `dedupe_key` prevents duplicate rows on event replay or repeated request.
- `target_url` matches the intended deep link.
- `payload_json` contains required context.
- Required-action notifications resolve when the source reaches a terminal state.
- Informational notifications resolve on read when that type requires it.
- Missing optional storage does not crash only when the feature is designed to tolerate pre-migration deployments.

Required frontend/deep-link coverage:

- Header contract test when touching layout/header/component behavior.
- JS/template contract test for new query parameters and highlight/scroll/open behavior.
- Browser or Playwright verification for user-visible notification flows when the task requires it.
- Copy or density changes are incomplete until the frontend Playwright gate passes. The evidence must record the expected/stored/center/bell body, prove single-line CSS ellipsis, capture center/bell/target screenshots, preserve the deep link, and contain no captured browser or server errors.
- The multichannel Playwright harness requires Firebase configuration by default. A local copy/UI run may explicitly set `PRINT_E2E_REQUIRE_FIREBASE=0`; evidence must then record `firebase_required: false` and `fcm_delivery_verified: false`. Such a run verifies bell persistence, account-center and bell copy, email, clipping, and deep-link behavior only. It must not be reported as FCM delivery verification.
- Polling contract coverage when touching header, notification center, or refresh behavior:
  - `onRefreshNotifications()` requires a frontend user.
  - refresh results are scoped to the logged-in user.
  - header-only refresh omits notification center selectors.
  - center refresh includes active/all selectors only when requested.
  - polling JavaScript pauses for hidden tabs and avoids overlapping requests.

Useful existing tests:

- `plugins/vmod/printcore/tests/Unit/PrintApprovalNotificationContractTest.php`
- `plugins/vmod/printcore/tests/Feature/PrintApprovalQueueManagerAuthzTest.php`
- `plugins/vmod/printcore/tests/Feature/PrintSupplierCostLinePackageTest.php`
- `plugins/vmod/printcore/tests/Feature/PrintCostingManagerTest.php`

Targeted commands:

```bash
../../../vendor/bin/phpunit tests/Unit/PrintApprovalNotificationContractTest.php
../../../vendor/bin/phpunit tests/Feature/PrintApprovalQueueManagerAuthzTest.php --filter quote_approval
../../../vendor/bin/phpunit tests/Feature/PrintSupplierCostLinePackageTest.php --filter supplier_po_unlock
../../../vendor/bin/phpunit tests/Feature/PrintCostingManagerTest.php --filter supplier_po_unlock_notification
```

Run commands from `plugins/vmod/printcore` unless the test file documents another working directory.

---

## 13. Migration And Deployment Rules

The current in-app notification table is shared.

Rules:

- Add new columns only when the existing generic columns cannot represent the feature.
- Prefer payload keys over schema changes for source-specific context.
- Do not refresh migrations to fix notification behavior in an existing environment.
- If a new listener is deployed before the table exists, the writer must no-op safely only where business rules allow it.
- Active unresolved rows may be explicitly resynced with `updateOrCreate()` when a target URL or payload contract changes; preserve their existing `read_at` and `resolved_at` values.
- Do not broad-backfill historical resolved rows unless the task explicitly requires it.

---

## 14. Review Questions

Before merging a notification change, answer these:

- Who receives this notification, and why exactly those users?
- What source state creates it?
- What source state resolves it?
- Can a repeated event duplicate it?
- Does the target URL open the correct actionable surface directly?
- Does the recipient have permission to open that target?
- What happens when there is no recipient?
- What happens if push fails?
- What test fails if the recipient filter, target URL, or resolve behavior breaks?

# Profile Liveness Guard `PROJECT_ROADMAP.md` (The Future 🗺️)

## Project Vision

Create a small Piwigo companion plugin that verifies whether an owner profile is still actively controlled by its owner. If the owner does not confirm a periodic SMS challenge within the configured grace period, the plugin asks CPT to make the owner's album tree private.

This plugin is not a dating/social feature. It is a safety and freshness guard for public profile galleries.

---

## Current Delivery Status (2026-06-24)

Delivered now:

- owner profile block with current liveness status, last verification, next due date, masked phone, and SMS request/confirm actions
- manual owner verification flow using Two Factor SMS transport
- guarded due scan webservice entry point: `profile_liveness_guard.runDueScan`
- idempotent due-scan behavior that does not resend duplicate SMS during the active grace period
- expiry handling that asks CPT to make the owner album tree private
- late confirmation flow that moves to `awaiting_admin_restore`
- admin restore action and audit log view
- guest/visitor rejection for crafted SMS-trigger requests
- PLG scope limited to non-admin album owners
- LanguageSwitch-compatible PLG language packs for `en_UK`, `es_ES`, `hu_HU`, `sk_SK`, `ru_RU`, `uk_UA`, and `zh_CN`
- all current acceptance scenarios in `profile_liveness_guard.feature` are implemented in the plugin, including localized PLG section coverage through LanguageSwitch

Still open:

- richer admin action matrix from the original sheet: send/resend SMS, mark verified manually, privatize now, clear guard state
- explicit admin warning panels for missing CPT/Two Factor dependencies
- automated PHPUnit/Cypress coverage
- optional SMS delivery callback and provider-status diagnostics

---

## Product Boundary

Profile Liveness Guard (PLG) is responsible for:

```text
weekly due schedule
SMS liveness challenge state
OTP confirmation
expiry handling
calling CPT to privatize album trees
admin diagnostics
owner-facing localization packs
```

PLG is not responsible for:

```text
SMS provider transport
login 2FA
album privacy internals
public profile rendering
adult age consent
image approval
login-time SMS prompting
```

Those are handled by other plugins:

```text
Two Factor SMS = SMS OTP transport
CPT = album ownership and privacy enforcement
LAC = legal age consent
Piwigo core/admin = image approval and moderation
```

---

## Phase 1: Manual/Semi-Manual Liveness MVP

### Goal

Allow the owner or admin to send a liveness SMS challenge and verify that the owner can confirm it.

Status: core owner flow delivered. Expanded admin-side control surface still pending.

### Features

- **Guard Table:** Store liveness status per owner root album.
- **Owner UCP Block:** Show verification status and a "Send verification SMS" / "Confirm code" workflow.
- **Admin Overview:** Show due, sent, verified, expired, and privatized profile states.
- **SMS Dependency:** Use Two Factor SMS helper to send the OTP.
- **CPT Dependency:** Use CPT helper only for album-tree privatization; do not duplicate privacy logic.
- **Graceful Degradation:** If Two Factor SMS or CPT is unavailable, show clear admin warning and do nothing destructive.

---

## Phase 2: Scheduled Weekly Guard

### Goal

Automate the liveness workflow.

Status: delivered for the due/expiry path through the protected webservice endpoint.

### Features

- **Weekly Due Scan:** Find active public profiles where `next_due_at <= NOW()`.
- **Automatic SMS Send:** Send OTP to verified phone.
- **Grace Period:** Allow configurable confirmation window, default 48 hours.
- **Expiry Action:** If `expires_at < NOW()` and not confirmed, call CPT to privatize the owner tree.
- **Notifications:** Show owner/admin status messages.
- **Idempotent Cron:** Re-running the job should not send duplicate SMS or repeatedly privatize the same tree.

---

## Phase 3: Admin Recovery and Re-Publish Flow

### Goal

Let an owner recover from expiry safely.

Status: delivered with manual admin restore as the default policy.

### Features

- Owner enters correct code after expiry and is marked verified.
- Admin may manually re-publicize the album tree after review.
- Optional auto-restore setting can be considered later, but the default should be manual/admin controlled.
- Admin audit log records who restored visibility and when.

---

## Phase 4: Delivery Status / SMS Callback Diagnostics

### Goal

Provide better SMS operational diagnostics if needed.

Status: provider `batch_id` and `msg_id` are stored. Callback/status integration is still pending.

### Features

- Store provider `batch_id` and `msg_id`.
- Optionally display SMS delivery status if Two Factor SMS exposes it.
- Optional callback support if SMSTOOLS callback is configured.

---

## Safety Position

PLG must fail safe:

- If SMS sending fails, do not immediately privatize.
- If CPT helper is unavailable, do not try to edit Piwigo tables directly.
- If due scan is uncertain, leave the profile unchanged and log a warning.
- If a profile expires, only make albums private; never delete photos, users, or metadata.

---

## Recommended Implementation Order

1. Implement Two Factor SMS transport first.
2. Implement PLG table and owner/admin manual verification flow.
3. Implement CPT privatization helper.
4. Add PLG scheduled due/expiry job.
5. Add E2E tests.

Current position:

- steps 1 to 4 are implemented in the current plugin
- step 5 remains open

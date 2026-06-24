# Profile Liveness Guard `project_sheet.md` (The Present 📜)

This document is the living technical specification for the new **Profile Liveness Guard (PLG)** Piwigo plugin.

PLG periodically asks a profile owner to confirm control of their verification phone. If the owner does not confirm within the grace period, PLG makes the owner album tree private through CPT.

---

## Implementation Status (2026-06-24)

Implemented in the current plugin:

- non-admin owner UCP block with status, dates, masked phone, send SMS, and confirm code
- PLG liveness record table and audit log table
- due scan webservice entry point restricted to webmaster/admin-only POST requests with CSRF token
- no-duplicate resend behavior during an active `sms_sent` grace window
- CPT-backed privatization of the owner tree after expiry
- late confirmation path to `awaiting_admin_restore`
- admin restore action with audit history
- visitor rejection for crafted trigger requests
- LanguageSwitch-compatible locale packs for `en_UK`, `es_ES`, `hu_HU`, `sk_SK`, `ru_RU`, `uk_UA`, and `zh_CN`
- the current feature file scenarios are aligned with delivered behavior, including localized PLG rendering through LanguageSwitch

Not yet implemented from the broader original sheet:

- admin actions to send/resend SMS, mark verified manually, privatize now, and clear guard state
- explicit admin warning panels when CPT or Two Factor are unavailable
- automated PHPUnit/Cypress coverage
- optional provider delivery callback/status handling

---

## Phase 1: Liveness Guard MVP

### `Action`

Verify that a public owner profile is still actively controlled by the owner by sending an SMS OTP on a schedule. If the owner does not confirm the challenge in time, make the owner root album and descendants private.

---

## Design Decision

Create a new plugin instead of putting the timer guard into Two Factor or CPT.

Responsibilities:

```text
Profile Liveness Guard
= schedule, liveness state, due/expired workflow, owner/admin UI, localization packs

Two Factor SMS
= SMS OTP sending and verification primitives

CPT
= album ownership resolution and album-tree privatization

Piwigo core
= users, categories, permissions, image moderation
```

Reasoning:

- Login 2FA and periodic liveness are different concerns.
- CPT should not own scheduling or SMS.
- PLG needs its own state machine and audit trail.
- Keeping the guard separate makes it possible to disable the safety workflow without removing CPT or Two Factor.
- PLG applies only to non-admin album owners; webmaster/admin accounts are outside the guard.

---

## Dependency Contract

Required:

- Piwigo
- CPT plugin active
- Two Factor SMS customization active

Recommended:

- Community plugin / inherited ownership model already in place
- LAC plugin for adult-only consent
- Piwigo image approval workflow enabled

PLG should show admin warnings if CPT or Two Factor SMS is missing.

---

## State Machine

```text
not_started
  -> sms_sent
  -> verified

verified
  -> sms_sent

sms_sent
  -> verified
  -> albums_privatized

albums_privatized
  -> awaiting_admin_restore

awaiting_admin_restore
  -> verified
```

Meaning:

- `not_started`: no PLG record existed yet or the first verification has not completed.
- `verified`: owner recently confirmed and the next due date is scheduled.
- `sms_sent`: OTP has been sent and the challenge grace period is active.
- `albums_privatized`: CPT privacy action was applied after expiry.
- `awaiting_admin_restore`: owner confirmed later, but public visibility requires admin review.

Note:

- the current implementation does not persist standalone `due`, `confirmed`, or `expired` states
- due-ness is derived from `next_due_at <= NOW()` while `status = verified`

---

## Data Model

```sql
CREATE TABLE piwigo_profile_liveness_guard (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  root_category_id INT NOT NULL,
  verified_phone VARCHAR(32) NULL,
  status VARCHAR(32) NOT NULL,
  last_verified_at DATETIME NULL,
  next_due_at DATETIME NULL,
  challenge_sent_at DATETIME NULL,
  challenge_expires_at DATETIME NULL,
  albums_privatized_at DATETIME NULL,
  restored_by INT NULL,
  restored_at DATETIME NULL,
  last_batch_id VARCHAR(64) NULL,
  last_msg_id VARCHAR(64) NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY user_root (user_id, root_category_id),
  KEY status_due (status, next_due_at),
  KEY status_expiry (status, challenge_expires_at)
);
```

Audit table:

```sql
CREATE TABLE piwigo_profile_liveness_guard_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  root_category_id INT NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  event_note TEXT NULL,
  actor_user_id INT NULL,
  created_at DATETIME NOT NULL
);
```

---

## Configuration

```php
$conf['profile_liveness_guard'] = array(
  'verification_interval_days' => 7,
  'challenge_grace_hours' => 48,
  'due_scan_enabled' => true,
  'auto_privatize_enabled' => true,
  'max_send_attempts_per_day' => 3,
  'require_admin_restore' => true,
  'debug_log' => false,
);
```

Future configuration keys such as separate auto-send or callback tuning can still be added later if needed.

---

## Owner UX

UCP section name:

```text
Profile Verification
```

Visible states:

```text
Your profile was verified on ...
Next verification is due on ...
A verification SMS was sent to +421905***000.
Enter the code from SMS.
Your profile verification expired. Your gallery is hidden until review.
```

Owner actions:

- request/resend verification SMS, rate-limited
- enter OTP code
- see next due date
- see whether profile is currently hidden by guard

---

## Admin UX

Admin panel should show:

```text
overview counts by state
restore candidates waiting for admin action
recent audit log events
```

Admin actions:

- restore after review if owner confirmed late
- view recent guard log events

Backlog admin actions still open:

- send/resend SMS
- mark verified manually with note
- privatize now
- clear guard state

---

## Scheduled Job

PLG needs a cron/maintenance entry point.

Possible entry points:

```text
admin-triggered maintenance action
Piwigo event on init with low-frequency throttling
CLI script if available
external cron hitting a protected webservice endpoint
```

Preferred for testability:

```text
profile_liveness_guard.runDueScan
```

Webservice restrictions:

- webmaster only, or
- protected by secret token if used by external cron

Due scan algorithm:

```text
1. Load profiles where `next_due_at <= NOW()` and `status = verified`.
2. If due scan is enabled, send SMS using the stored verified phone.
3. Store provider batch/msg ids.
4. Set `status = sms_sent`, `challenge_sent_at = NOW`, `challenge_expires_at = NOW + challenge_grace_hours`.
5. Re-running during the active grace period must not send a duplicate SMS.
6. Load profiles where `challenge_expires_at < NOW()` and `status = sms_sent`.
7. If automatic privatization is enabled, call the CPT tree-private helper.
8. Mark `status = albums_privatized` and log the transition.
```

---

## CPT Integration

PLG must not manually edit album privacy tables.

Expected CPT helper:

```php
cpt_make_owner_tree_private(int $root_album_id, int $owner_user_id, string $reason): array
```

Expected behavior:

- verify effective ownership
- set root album and descendants to private
- preserve admin/owner access
- purge user cache
- return affected album ids
- log reason if CPT logging exists

PLG treats a helper error as a failed operation and logs it.

---

## Two Factor SMS Integration

Current integration:

```text
- PLG bootstraps `PwgTwoFactor` to read the verified SMS phone
- PLG sends OTP through the existing Two Factor SMS transport helpers
- after a successful PLG confirmation, PLG disables SMS login enrollment for that user and keeps using the PLG-stored `verified_phone` for later weekly checks
```

PLG should not know or store the SMSTOOLS API key.

---

## Security Rules

- Only owner/admin/system may trigger liveness SMS.
- PLG applies only to non-admin album owners.
- Visitors must never trigger liveness SMS.
- Phone is masked in UI.
- OTP is never logged.
- All manual actions require CSRF token.
- Cron endpoint must be admin-only or protected by a secret.
- Expiry action only makes albums private; it never deletes content.
- Late confirmation should not automatically restore public visibility unless explicitly configured.
- Admin override actions must be logged.

---

## PHPUnit Test Plan

1. Guard record is created for owner root album.
2. Due scan marks verified profile as due.
3. Due scan sends SMS and records expiry.
4. Re-running due scan does not send duplicate SMS during grace period.
5. Correct OTP marks profile verified and advances `next_due_at`.
6. Wrong OTP does not verify and is rate-limited/attempt-limited.
7. Expired SMS challenge calls CPT privatization helper once.
8. CPT helper failure logs error and does not mark privatized.
9. Late confirmation moves to `awaiting_admin_restore`.
10. Admin manual verification logs actor and note.
11. Owner cannot verify another owner's guard record.
12. Visitor cannot trigger SMS.

---

## Cypress / E2E Acceptance Scenarios

1. Owner sees verification status in UCP.
2. Owner requests SMS and confirms code.
3. Due profile receives SMS during scheduled scan.
4. Expired profile becomes private.
5. Guest cannot see expired/privatized profile albums.
6. Owner can still log in and see recovery status.
7. Admin can restore profile after owner confirms late.
8. Visitor cannot trigger SMS to owner phone.
9. Owner sees the PLG section localized according to the active LanguageSwitch locale.

---

## Manual Testing Checklist

1. Configure Two Factor SMS API key and sender.
2. Configure PLG as enabled with short test period/grace.
3. Create owner root album `slecna1`.
4. Set verified phone for owner.
5. Run due scan manually.
6. Confirm SMS is sent.
7. Enter correct code and verify next due date.
8. Run due scan with expired timestamp.
9. Confirm CPT privatizes root and descendants.
10. Confirm owner/admin access remains.
11. Confirm guest access is blocked.
12. Confirm admin log records actions.

---

## Definition of Done

- PLG installs/uninstalls cleanly.
- Guard table and log table are created.
- Owner can confirm liveness by SMS.
- Due scan sends SMS once per due cycle.
- Expired challenge triggers CPT tree-private helper.
- No visitor can trigger SMS.
- Admin can review status and recover profile after late confirmation.
- PHPUnit and Cypress cover core state transitions. Current status: still pending.

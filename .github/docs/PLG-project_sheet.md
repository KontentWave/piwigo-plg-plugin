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
- PLG uses `tf_get_verified_sms_phone($user_id)` as the trusted phone source when available
- PLG may fall back to the current Two Factor class reader only for compatibility on older local states
- PLG sends OTP through the existing Two Factor SMS transport helpers
- after a successful PLG confirmation, PLG may explicitly disable SMS login enrollment for that user as a PLG policy step and keeps using the PLG-stored `verified_phone` for later weekly checks
```

Boundary reminder:

```text
- Two Factor owns login authentication and verified-phone storage
- PLG owns recurring weekly liveness orchestration and state
- disabling SMS login enrollment is an explicit PLG policy action, not shared workflow ownership
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

---

## Phase 1.1: Privacy Snapshot Before Forced Privatization

Status: planned hardening extension.

### `Action`

Before PLG forces an expired owner album tree to private, capture the current privacy state of every album in that effective owner tree. If the owner confirms late and an administrator approves restoration, restore every album to its captured pre-PLG visibility instead of blindly making the whole tree public.

This prevents a serious overexposure bug:

```text
Before expiry:
- root album: public
- album A: public
- album B: private
- album C: shared with selected users

After PLG expiry:
- all albums become private

After late verification + admin restore:
- root album: public
- album A: public
- album B: private
- album C: shared with selected users
```

PLG must never turn a previously private/shared album public just because the profile owner confirmed late.

---

## Why This Extension Exists

The current MVP intentionally hides an expired profile by calling CPT to make the owner tree private.

However, admin restore currently has a risky simplification if it restores all affected albums as public. That is acceptable for a very early smoke test, but not safe for a real portal because a gallery owner may already have intentionally private or shared child albums before PLG expiry.

PLG should treat forced privatization as a reversible safety overlay, not as a permanent rewrite of the owner's privacy choices.

---

## Design Decision

Take the restoration snapshot immediately before forced privatization, not when the SMS challenge is first sent.

Reason:

```text
SMS sent
-> owner still has a grace period
-> owner/admin might change album privacy during the grace period
-> expiry happens later
```

The safest snapshot is the state PLG is about to overwrite at expiry time.

Optional later enhancement:

```text
Also record a lightweight "challenge-start visibility hash" for diagnostics,
but do not use it for restore unless explicitly designed.
```

---

## Data Model Extension

Add a dedicated snapshot table.

```sql
CREATE TABLE piwigo_profile_liveness_guard_album_snapshot (
  id INT AUTO_INCREMENT PRIMARY KEY,
  guard_record_id INT NOT NULL,
  user_id INT NOT NULL,
  root_category_id INT NOT NULL,
  album_id INT NOT NULL,
  previous_status VARCHAR(16) NOT NULL,
  previous_visibility_mode VARCHAR(16) NOT NULL,
  previous_shared_user_ids TEXT NULL,
  captured_at DATETIME NOT NULL,
  restored_at DATETIME NULL,
  restore_status VARCHAR(32) NULL,
  UNIQUE KEY plg_snapshot_album (guard_record_id, album_id),
  KEY plg_snapshot_record (guard_record_id),
  KEY plg_snapshot_user_root (user_id, root_category_id)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
```

Field meaning:

```text
guard_record_id
= PLG liveness record id for this owner/root album cycle

album_id
= root album or descendant album id

previous_status
= original Piwigo category status, usually public/private

previous_visibility_mode
= CPT interpretation: public/private/shared

previous_shared_user_ids
= JSON/text array of selected user ids if previous_visibility_mode = shared

captured_at
= when PLG captured the state just before privatization

restored_at
= when admin restore applied this row

restore_status
= restored/skipped/error
```

The snapshot table should be created/updated by `maintain.class.php`.

Upgrade rule:

```text
If upgrading from an existing PLG install, create the snapshot table without touching existing guard records.
```

---

## Snapshot Capture Algorithm

Add helper:

```php
profile_liveness_guard_capture_visibility_snapshot(array $record): array
```

Expected behavior:

```text
1. Resolve root album id and owner user id from the guard record.
2. Fetch the effective owner album tree.
3. For each album, verify it still belongs to the same effective owner.
4. Read current album status from categories.status.
5. Read CPT visibility mode using cpt_get_album_visibility_mode() when available.
6. Read selected shared users using cpt_get_album_shared_user_ids() when available.
7. Store one snapshot row per album.
8. Do not overwrite an existing snapshot for the same guard record and album.
9. Log `visibility_snapshot_captured`.
```

Ownership safety:

```text
Only snapshot/update albums whose effective owner is the guard owner.
If an explicit child owner exists under the tree, skip it and log `visibility_snapshot_album_skipped`.
```

Reason:

```text
PLG must not hide or restore another owner's explicit child album.
```

---

## Forced Privatization Flow

Update expiry handling:

```text
1. Load expired `sms_sent` records.
2. Before calling CPT to privatize, capture a visibility snapshot.
3. If snapshot capture fails, do not privatize unless `allow_privatize_without_snapshot` is explicitly enabled.
4. Call CPT to make only the effective owner's albums private.
5. Mark status `albums_privatized`.
6. Log affected album ids and snapshot row count.
```

Recommended config:

```php
$conf['profile_liveness_guard'] = array(
  // existing keys...
  'restore_original_privacy' => true,
  'allow_privatize_without_snapshot' => false,
);
```

Default recommendation:

```text
restore_original_privacy = true
allow_privatize_without_snapshot = false
```

Failing closed is safer. If PLG cannot remember what it is about to overwrite, it should not overwrite it unless the admin deliberately allows that mode.

---

## Restore Algorithm

Replace the current "make all albums public" restore logic with snapshot-based restore.

Add helper:

```php
profile_liveness_guard_restore_visibility_snapshot(array $record, int $actor_user_id): array
```

Expected behavior:

```text
1. Load snapshot rows for the guard record.
2. For each snapshot row, verify the album still exists.
3. Verify the album still belongs to the same effective owner.
4. Restore via cpt_update_album():
   - previous_visibility_mode = public
     -> status public, mode public
   - previous_visibility_mode = private
     -> status private, mode private, no shared users
   - previous_visibility_mode = shared
     -> status private, mode shared, previous shared users
5. Mark snapshot rows restored.
6. Mark PLG record `verified`.
7. Set `restored_by` and `restored_at`.
8. Log `visibility_snapshot_restored` and `admin_restore_completed`.
```

If a snapshot row cannot be restored:

```text
- skip the album
- log `visibility_snapshot_restore_failed`
- keep the admin informed
- do not silently make it public
```

---

## Admin UX Update

Admin restore copy should change from:

```text
Restore profile
```

to something clearer:

```text
Restore original visibility
```

or:

```text
Restore saved album privacy
```

Admin panel should show:

```text
Snapshot captured: yes/no
Snapshot album count
Public/private/shared count before expiry
Restore status
```

Suggested warning when snapshot is missing:

```text
No saved privacy snapshot exists for this record. Restoring all albums to public is unsafe and disabled by default.
```

---

## Owner UX Update

When owner confirms late:

```text
Your phone was verified, but your albums were already hidden.
An administrator must review and restore the saved album privacy.
```

Do not say "your albums will be made public" because the correct behavior is restoration of the previous privacy mix.

---

## Updated Security Rules

Add these rules to the Security section:

- PLG must snapshot album visibility before forced privatization.
- PLG must restore from the snapshot after late verification and admin approval.
- PLG must not make previously private/shared albums public unless the snapshot says they were public.
- PLG must not overwrite an existing snapshot for the same expired cycle.
- PLG must skip explicit child-owner albums in the owner tree.
- PLG must fail closed if snapshot capture fails and `allow_privatize_without_snapshot` is false.
- Admin restore must be logged with actor id and snapshot restore counts.

---

## Updated Scheduled Job Algorithm

Replace the expiry portion of the due scan with:

```text
6. Load profiles where `challenge_expires_at < NOW()` and `status = sms_sent`.
7. Capture current visibility snapshot for the owner tree.
8. If snapshot capture succeeds, call the CPT tree-private helper.
9. Mark `status = albums_privatized`.
10. Log `visibility_snapshot_captured` and `albums_privatized`.
```

Late restore now becomes:

```text
1. Owner confirms OTP after expiry.
2. PLG moves status to `awaiting_admin_restore`.
3. Admin reviews restore candidate.
4. PLG restores each album from snapshot.
5. PLG marks record `verified`.
6. PLG logs `visibility_snapshot_restored` and `admin_restore_completed`.
```

---

## Updated PHPUnit Test Plan

Add these tests:

13. Snapshot captures public, private, and shared album states before forced privatization.
14. Snapshot stores shared user ids for shared albums.
15. Expiry does not privatize if snapshot capture fails and fail-closed mode is enabled.
16. Re-running expiry does not overwrite an existing snapshot.
17. Admin restore restores public albums to public.
18. Admin restore keeps originally private albums private.
19. Admin restore restores originally shared albums with the same shared users.
20. Admin restore skips albums that no longer belong to the original owner.
21. Missing snapshot prevents unsafe restore-to-public.
22. Snapshot capture and restore events are written to the audit log.

---

## Updated Cypress / E2E Acceptance Scenarios

Add these scenarios:

10. Mixed public/private/shared album tree is restored to its original visibility after late verification and admin approval.
11. A previously private child album is not made public by PLG restore.
12. A previously shared child album remains shared with the same selected users after PLG restore.
13. Admin sees a warning when a restore candidate has no visibility snapshot.
14. Expired profile remains hidden until admin approval even after late owner confirmation.

---

## Updated Definition of Done

Add these items:

- PLG captures a visibility snapshot before forced privatization.
- Snapshot includes album id, previous status, previous CPT visibility mode, and previous shared users.
- Forced privatization fails closed if snapshot capture fails.
- Admin restore uses the saved snapshot instead of blindly setting albums public.
- Private albums remain private after restore.
- Shared albums retain their selected user access after restore.
- Snapshot capture and restore are covered by unit and E2E tests.

---

## Verified Two Factor Phone Source Contract

Status: required integration rule.

PLG must rely on the verified SMS phone stored by the Two Factor plugin, not on the raw editable CPT profile phone.

Reason:

```text
CPT contact_number
= editable candidate phone
= can change any time in My Profile
= not trusted for liveness until verified

two_factor.phone_number
= previously verified SMS phone
= updated only after a successful SMS OTP verification

PLG verified_phone
= copied/recorded from the trusted Two Factor phone used for the liveness challenge
```

This protects the liveness workflow when an owner edits her public contact number. The profile may show that re-verification is required, but PLG must continue to treat the previously verified Two Factor phone as the trusted phone until the new number is verified.

---

## Recommended Two Factor Helper

Add or consume a small Two Factor helper so PLG does not need to know the `two_factor` table shape:

```php
tf_get_verified_sms_phone(int $user_id): ?string
```

Expected behavior:

```text
1. Return the normalized verified SMS phone from the Two Factor `sms` method row.
2. Return null if SMS 2FA/verification is not enabled for the user.
3. Never return raw CPT `contact_number`.
4. Never expose the full phone to public templates.
```

PLG-side usage:

```php
$phone = function_exists('tf_get_verified_sms_phone')
  ? tf_get_verified_sms_phone($user_id)
  : profile_liveness_guard_get_phone_number($user_id);
```

For the current fallback implementation, PLG may still bootstrap `PwgTwoFactor('sms')->getPhoneNumber()`, because that value is the stored verified phone after OTP setup. But the helper is cleaner and avoids coupling PLG to the class/table details.

---

## PLG Behavior When CPT Phone Changes

If the owner changes `CPT contact_number` but has not verified the new number yet:

```text
- Two Factor UI shows re-verification required.
- `two_factor.phone_number` remains the previously verified number.
- PLG continues to send liveness SMS to the previously verified number.
- PLG does not read or trust the raw CPT candidate number.
- PLG does not reset expiry or restore albums just because CPT phone changed.
```

After the owner successfully verifies the new CPT phone through Two Factor:

```text
- Two Factor updates `two_factor.phone_number`.
- Future PLG liveness SMS challenges use the new verified phone.
- Existing PLG `verified_phone` may be updated on the next successful liveness send/confirmation.
```

---

## Updated Security Rules For Phone Source

Add these rules to the Security section:

- PLG must use the trusted verified SMS phone from Two Factor.
- PLG must not send liveness SMS to raw CPT `contact_number`.
- PLG must not infer liveness from the existence of a CPT phone.
- PLG must not force a new liveness cycle merely because the CPT phone changed.
- If no verified Two Factor SMS phone exists, PLG should show setup-required state and refuse to send liveness SMS.

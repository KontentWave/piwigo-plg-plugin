# ADR-0001: PLG Scope and Integration Decisions

- Status: Accepted
- Date: 2026-06-24

## Context

Profile Liveness Guard started from the stock Piwigo scaffold, but the actual product boundary became narrower and more operationally specific during implementation.

Three questions had to be resolved explicitly:

1. PLG needed SMS delivery and phone ownership proof, but it must not become a login 2FA plugin.
2. PLG needed to hide expired owner galleries, but it must not take over album privacy internals from CPT.
3. The gallery policy required the guard to apply to public owner galleries, not to webmaster/admin accounts.

## Decision

PLG is implemented as a separate companion plugin with the following rules:

1. PLG owns liveness scheduling, OTP challenge state, late-confirm handling, audit logging, and owner/admin UI.
2. Two Factor SMS remains the SMS transport and verified-phone dependency. PLG reads the verified phone through `PwgTwoFactor` and sends OTP through the existing Two Factor SMS helper.
3. CPT remains the only component that changes album-tree visibility. PLG calls CPT helpers and does not write privacy state directly.
4. PLG applies only to non-admin album owners. Webmaster/admin accounts are excluded from the owner liveness workflow and do not receive the PLG profile block.
5. After a successful PLG confirmation, PLG disables SMS login enrollment for that user and continues future weekly checks using the PLG-stored `verified_phone`. This keeps login 2FA concerns separate from periodic gallery-liveness checks.
6. Late confirmation after expiry does not automatically re-publicize the owner tree. The default recovery policy is `awaiting_admin_restore` followed by an explicit admin restore action.
7. Localization is implemented through native Piwigo language packs inside PLG. LanguageSwitch compatibility is achieved by shipping PLG locale folders, not by patching the LanguageSwitch plugin itself. The delivered locale set is `en_UK`, `es_ES`, `hu_HU`, `sk_SK`, `ru_RU`, `uk_UA`, and `zh_CN`.

## Consequences

Positive:

- PLG stays narrowly aligned to its safety/freshness purpose.
- Existing SMS and privacy code remains reusable and authoritative.
- Admin/webmaster accounts are not forced into an album-owner verification cycle.
- Late recovery is safer because public visibility is restored only after review.
- Localization remains standard Piwigo plugin behavior and automatically follows the active gallery language.

Trade-offs:

- PLG depends on Two Factor SMS and CPT being present and compatible.
- The current admin surface is intentionally smaller than the original broad admin-action wishlist.
- Weekly verification relies on PLG's stored phone after the first successful confirmation, so phone changes must be reflected through the upstream Two Factor phone source before the next PLG refresh.

## Follow-up

The remaining backlog should build on this ADR rather than reopen the boundary decisions:

- add richer admin actions without moving SMS or privacy ownership out of their existing plugins
- add automated tests around the established state machine and policy rules
- add optional delivery-status diagnostics only if the Two Factor SMS layer exposes them cleanly

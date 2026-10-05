---
name: feedback-t28-fa1-review
description: T28/FA1 (staff login admin-api) review notes: what was already solid, and recurring gaps to recheck in later staff/admin tasks
metadata:
  type: feedback
---

T28+FA1 review (2026-10-05): no blockers. Verified: `SessionGuard::login()` itself stores `password_hash_web` (no stale-hash worry on re-login); `admin.origin` before `auth:sanctum` works via `prependToPriorityList`; admin session store TTL = 720 min so idle 120 is enforced by `StaffIdleTimeout` (not GC).

**Why:** future staff routes (T06+, T33) reuse this stack.
**How to apply:** when reviewing later admin tasks check: (1) new routes sit in the `staff` group in routes/admin.php; (2) e2e tests weakened to not touch backend (FA1 dropped the CSRF/CORS check); (3) mail/side-effect ordering (record device before send loses the alert on failure); (4) FE hardcodes server-config constants (idle 120) instead of reading API `session.*`; (5) curl without `Accept: application/json` to a protected route gives 500 "Route [login] not defined" (pre-existing, not a new bug — add Accept before reporting); (6) design/contract drift (login label "Email hoặc SĐT" vs email-only).

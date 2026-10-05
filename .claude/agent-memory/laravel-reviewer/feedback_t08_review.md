---
name: t08-course-admin-review
description: T08 review notes - what was solid, cross-task race with T14 EnrollmentService, test env gotcha
metadata:
  type: feedback
---

T08 (course admin, upload, sanitize) approved with 0 blocker. Solid patterns to expect again: Policy in FormRequest::authorize (before validation), forceFill in services, WebP re-encode for uploads.

Cross-task check that recurs: services that delete/archive a parent row (CourseService::delete) assume child-creating flows lock the same parent row. InnoDB FK insert takes S-lock so "insert-first" is safe, but "delete-first then insert" slips through unless the creator re-checks status/trashed under lockForUpdate (T14 requestFree/grantPurchase do not).

**Why:** model passed into service was loaded before the transaction, so stale status/trashed.
**How to apply:** whenever reviewing enrollment/order code, check it re-reads the course under lock; when order tables arrive (T18/T19), delete must also consider pending order_items.

Env gotcha: running pest on the shared test DB can fail with "users doesn't exist" while another dev migrates; rerun after ~1 min. `timeout` command is not on macOS host; call docker compose directly.

Small recurring nit: html_entity_decode + trim leaves NBSP (U+00A0), so "empty" checks on sanitized HTML miss `&nbsp;`.

---
name: feedback-vitaminvui-t05-session-review
description: T05 (ADR-003 single-student-session) review notes — how the S11 fix was verified as solid, and the recurring "fail-closed error branch has no failure-injection test" gap to check on future session/security Services
metadata:
  type: feedback
---

T05 (`StudentSessionService`, `EnforceSingleStudentSession`, `SessionTombstoneStore`,
`ApiExceptionRenderer::resolveAuthenticationException`) implements ADR-003 option D cleanly: no
"adopt a NULL/stale session" branch anywhere (the exact S11 regression the ADR was written to prevent),
`bind()` takes `lockForUpdate()` on the `users` row inside `DB::transaction()` before reading the old
session id (correctly serializes concurrent logins from 2 devices — same pattern as T04's
`OtpService::createCodeAtomically()`), and no external service (OTP, mail) is called inside that
transaction. 7 HTTP-level Pest tests in `tests/Feature/T05/SingleStudentSessionTest.php` drive real
login/logout/`/auth/me` round-trips with real `Set-Cookie` handoff between simulated "devices" (not
`actingAs()`) and cover all 6 ADR-mandated scenarios plus the conditional-UPDATE-on-logout race. Verified
independently via `pint --test && phpstan analyse && pest -c phpunit.t05.xml` (356 passed) — this class
of task (real session/cookie flow, not mocked) is exactly where insisting on real-HTTP tests (see
[[feedback-vitaminvui-otp-throttle-pattern]]'s T04 lesson) pays off; it would be very easy to fake this
kind of test with `actingAs()` and it would prove nothing about the actual cookie/session-store
interaction being tested.

**Recurring gap worth checking on every future review of a "fail-closed" security Service:** the two
`catch (Throwable $e)` branches in `StudentSessionService::bind()`/`invalidatePrevious()` (DB write fails
→ rethrow + tear down the new session; cache/session-store write fails → swallow + `report()` +
`Log::warning()`, matching ADR-003's explicit "losing the tombstone reason is acceptable, losing the
security property is not") read correctly by inspection, but had **zero test coverage** exercising either
failure branch (no `Cache::shouldReceive(...)->andThrow(...)` or forced DB failure anywhere in the diff).
This is a different gap than the OTP TOCTOU one — here the code path is only reachable during real infra
incidents (Redis/DB hiccup), which is exactly when you most need to trust it hasn't silently regressed.
Rated SHOULD not BLOCKER because the by-inspection reasoning is genuinely sound and the design already
matches ADR-003's own explicit reasoning for the two different failure-handling choices.

**How to apply:** when a Service's core safety property (e.g. "never 2 valid sessions", "never lose an
audit row") depends on specific try/catch behavior around an external write (DB/cache/queue), check
whether any test actually forces that external write to throw. If not, flag as SHOULD and propose a
concrete `Cache::shouldReceive(...)->andThrow(...)` / forced-exception test rather than accepting
"the code looks right" as sufficient for a [SEC] task.

---
name: feedback-t03-auth-review
description: Recurring pitfalls in VitaminVui auth code (T03 review 2026-10-05) and where code reviews are stored
metadata:
  type: feedback
---

Code reviews go to `docs/review/<task>.md` (docs/security = security reviews, docs/qa = QA).

Check in auth tasks: (1) `throttle:` middleware counts successful requests too, but contract says "lần sai" -> need RateLimiter::hit on failure only; (2) parsing MySQL 1062 message with str_contains is attacker-influenceable (the duplicated value is in the message) -> parse only after "for key"; (3) fake captcha/driver defaults + ProductionConfigGuard only guards APP_ENV=production, not staging; (4) Turnstile token is single-use, any 422 burns it.

**Why:** found in T03 review (no blockers, 4 SHOULD). **How to apply:** check same items on T27/T28 (password reset, admin login).

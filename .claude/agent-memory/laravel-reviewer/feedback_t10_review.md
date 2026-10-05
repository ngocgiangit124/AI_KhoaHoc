---
name: t10-catalog-review
description: T10 catalog review notes - what was verified solid and recurring nits
metadata:
  type: feedback
---

T10 catalog: cache.headers (Laravel SetCacheHeaders) skips non-2xx, so error responses are not publicly cached; public routes use withoutMiddleware(stateful).

**Why:** quickly confirm cache safety without re-deriving.
**How to apply:** for public cached routes still check Vary: Origin/CORS, Set-Cookie on errors, `links` built from request Host. Resources that return rich-text must run HtmlSanitizer on output (admin CourseResource does; public detail did not, R1). `docker compose exec` has no `timeout` on host macOS.

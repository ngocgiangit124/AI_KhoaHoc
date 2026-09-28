---
name: feedback-vitaminvui-review-findings
description: Recurring defect pattern to check for in VitaminVui (ADR-004 2-host Sanctum SPA) backend reviews
metadata:
  type: feedback
---

When reviewing VitaminVui backend work against ADR-004 (2-host Sanctum SPA, `api.*`/`admin-api.*`), always check for **default package routes that bypass the app's own security middleware**, not just the routes the dev wrote in `routes/*.php`.

**Why:** In the T01/T02 review (2026-09-25) I found `php artisan install:api` leaves Sanctum's built-in `GET /sanctum/csrf-cookie` route registered with `domain=null` and middleware `['web']` only — no `Route::domain()` restriction and no `admin.origin`/`EnsureAdminOrigin`. It responds on `admin-api.localhost` just like the custom `/api/v1/csrf-token`, but skips the origin check ADR-004 §2.2 says must apply to "every route on admin-api". This wasn't visible from reading `routes/admin.php`/`routes/api.php` — only `php artisan route:list --json` revealed it. The dev had actually self-flagged this exact route's coexistence as something needing careful review but hadn't resolved or documented it.

**How to apply:** For any Laravel project using Sanctum/Fortify/Breez/Jetstream-style packages that auto-register routes, run `php artisan route:list --json` and check `domain` + `middleware` for every route — don't rely on grepping `routes/*.php`. If a package route duplicates a custom-hardened endpoint (e.g. custom CSRF endpoint here), recommend disabling the package's own route (e.g. `Sanctum::ignoreRoutes()` in a provider's `register()`) rather than leaving both to coexist.

Also: when curl-testing session/CSRF endpoints on this project, a 500 "Session store not set on request" is very likely just a missing `Origin` header in the curl call (Sanctum's `EnsureFrontendRequestsAreStateful::fromFrontend()` gate), not a real app bug — reproduce with `-H "Origin: <matching stateful domain>"` before reporting it as a defect.

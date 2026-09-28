---
name: project-vitaminvui
description: VitaminVui repo layout, how to run checks, and where the architecture contracts live
metadata:
  type: project
---

Repo root is now `/Users/admin/Documents/Giang/AI_KhoaHoc` (git, main branch, work happens on `claude/zen-dirac-fmucf7` and per-task sub-branches — see `docs/board.md` "Bàn giao về máy local" for the current branch map). Layout: `backend/` (Laravel 13, PHP 8.3, MySQL 8.4 driver MySQL), `frontend/` (pnpm workspace: apps/web, apps/admin, packages/api-client, packages/ui, packages/config), `infra/` (docker-compose, nginx, php), `docs/` (architecture, adr, security, db, stories, tech, design, qa, and now `reviews/` for non-story infra/setup reviews).

**Contracts to always cross-check for backend work:**
- `docs/adr/ADR-004-nen-tang-api-nextjs-sanctum-phan-quyen.md` — 2-host topology (api./admin-api.), cookie/CORS/CSP rules, middleware groups, mass-assignment rules. This is the single most load-bearing doc for backend reviews.
- `docs/architecture/api-contract.md` §1 — response envelope, middleware groups table, error codes, rate limits.
- `docs/architecture/data-model.md` §3.1 — users/audit_logs schema detail.
- `docs/architecture/tasks.md` — per-task "Định nghĩa xong" (DoD) checklist, this is what to grade against.
- `docs/architecture/review-traceability.md` — maps each Security finding (S1..S24) and DBA finding (#1..#10) to the task that must close it; useful to check nothing regressed.
- `docs/board.md` — always read first each session: current task status table, which branches are unreviewed WIP, and any PO-decided rules (e.g. staff-login-redirect decision from 2026-09-28).

**How to run checks (read-only, safe):**
- Backend: `cd infra && docker compose exec -T php composer ci` (runs `vendor/bin/pint --test && vendor/bin/phpstan analyse && vendor/bin/pest` per `backend/composer.json`). Containers must already be up (`docker compose ps`); on this Mac host they're typically already running.
- Frontend: `frontend/scripts/pnpm.sh run lint|typecheck|test|build` (spins a docker build of `Dockerfile.dev`, runs pnpm through docker so no host Node needed).
- Route inspection: `docker compose exec -T php php artisan route:list --json` is the fastest way to verify actual domain/middleware per route (don't trust routes/*.php source alone — Sanctum/Fortify-style packages register their own routes outside the app's route files, e.g. `sanctum/csrf-cookie`).
- Live header/cookie checks: `curl -i --resolve <host>:8000:127.0.0.1 http://<host>:8000/...` — note `curl` does NOT auto-resolve `*.localhost`, must use `--resolve`. Also: hitting the api-host CSRF endpoint without an `Origin` header 500s (Sanctum's `EnsureFrontendRequestsAreStateful` never starts the session) — that's expected Sanctum SPA behavior, not a bug, always send `Origin` when curl-testing session/CSRF endpoints.
- `.gitignore` sanity check: don't trust `git check-ignore -v` alone when a `!negation` pattern is involved — its exit code/pattern output can look like a match either way. Use `git status --ignored=matching -- <path>` and read the two-letter code (`??` = untracked/not ignored, `!!` = ignored) — that's unambiguous.

See [[feedback-vitaminvui-review-findings]] for the Sanctum/package-route defect pattern (T01/T02/FE0, 2026-09-25) and [[feedback-vitaminvui-infra-dual-fix]] for the recurring "fixed in one of two sibling infra files" pattern (local-setup review, 2026-09-28).

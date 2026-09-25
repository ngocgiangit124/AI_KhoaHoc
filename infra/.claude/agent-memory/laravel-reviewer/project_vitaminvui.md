---
name: project-vitaminvui
description: VitaminVui repo layout, how to run checks, and where the architecture contracts live
metadata:
  type: project
---

Repo root `/home/ngocgiang/TestAI_Agent` (no git yet as of 2026-09-25). Layout: `backend/` (Laravel 13, PHP 8.3), `frontend/` (pnpm workspace: apps/web, apps/admin, packages/api-client, packages/ui, packages/config), `infra/` (docker-compose, nginx, php), `docs/` (architecture, adr, security, db, stories, tech, design).

**Contracts to always cross-check for backend work:**
- `docs/adr/ADR-004-nen-tang-api-nextjs-sanctum-phan-quyen.md` — 2-host topology (api./admin-api.), cookie/CORS/CSP rules, middleware groups, mass-assignment rules. This is the single most load-bearing doc for backend reviews.
- `docs/architecture/api-contract.md` §1 — response envelope, middleware groups table, error codes, rate limits.
- `docs/architecture/data-model.md` §3.1 — users/audit_logs schema detail.
- `docs/architecture/tasks.md` — per-task "Định nghĩa xong" (DoD) checklist, this is what to grade against.
- `docs/architecture/review-traceability.md` — maps each Security finding (S1..S24) and DBA finding (#1..#10) to the task that must close it; useful to check nothing regressed.

**How to run checks (read-only, safe):**
- Backend: `cd infra && docker compose exec -T php composer ci` (runs pint --test, phpstan level 6, pest). Containers must already be up (`docker compose ps`).
- Frontend: `frontend/scripts/pnpm.sh run -r lint|typecheck|test` (spins a docker build of `Dockerfile.dev`, runs pnpm through docker so no host Node needed).
- Route inspection: `docker compose exec -T php php artisan route:list --json` is the fastest way to verify actual domain/middleware per route (don't trust routes/*.php source alone — Sanctum/Fortify-style packages register their own routes outside the app's route files, e.g. `sanctum/csrf-cookie`).
- Live header/cookie checks: `curl -i --resolve <host>:8000:127.0.0.1 http://<host>:8000/...` — note `curl` on this WSL2 box does NOT auto-resolve `*.localhost`, must use `--resolve`. Also: hitting the api-host CSRF endpoint without an `Origin` header 500s (Sanctum's `EnsureFrontendRequestsAreStateful` never starts the session) — that's expected Sanctum SPA behavior, not a bug, always send `Origin` when curl-testing session/CSRF endpoints.

See [[feedback-vitaminvui-review-findings]] for the specific defect pattern found in the T01/T02/FE0 review (2026-09-25).

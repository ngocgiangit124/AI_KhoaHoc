---
name: project-mvp-architecture
description: Where the VitaminVui MVP architecture lives, its status after the security/DBA review, and the key decisions future designs must not contradict
metadata:
  type: project
---

The MVP design lives in `docs/architecture/`: README, data-model, api-contract, tasks, and review-traceability. The decisions are in `docs/adr/ADR-001..004`, now Accepted after the Security and DBA design reviews of 2026-09-25. `docs/tech/README.md` maps each story to its sections.

PO decisions of 2026-09-25:
- Stack: **Laravel 13 from the start** (updated decision; Laravel 12 was skipped because its security support ends around 02/2027, and upgrade task T32 was removed), PHP 8.3, MySQL 8.4 LTS.
- Third-party packages may lag Laravel 13. tasks.md G2 has a compatibility table: Larastan and mews/purifier are uncertain; the fallback for purifier is `ezyang/htmlpurifier` used directly. `--ignore-platform-reqs` is never allowed.
- The admin UI is Next.js on its own origin, `admin.vitaminvui.vn`.
- Staging uses a completely separate domain.
- Active MoMo reconciliation is ON.
- Infrastructure defaults to Nginx on Ubuntu with Docker, still pending PO confirmation.

Decisions later work must stay consistent with:
- There are two API hosts in one Laravel app:
  - `api.` serves students with cookie `vv_session`;
  - `admin-api.` serves staff with cookie `vv_admin_session`.
  - Cookies are host-only and CSRF travels in the `X-CSRF-TOKEN` header.
  - Admin routes also use `EnsureAdminOrigin`, email MFA for admin and page managers, and a 120-minute idle timeout.
- The repo is a monorepo: `backend/` (Laravel), `frontend/` (pnpm workspace with `apps/web`, `apps/admin` and packages), and `infra/`. Frontend work belongs to agent `nextjs-dev`.
- The MySQL connection uses READ COMMITTED set through `PDO::MYSQL_ATTR_INIT_COMMAND`, not an `isolation_level` key, which does not exist for the mysql driver. Collation is `utf8mb4_0900_ai_ci`.
- Frontend runs Next.js 16.x (FE0 installed 16.3.6; `middleware.ts` is now `proxy.ts`).
  - ADR-004 §2.7 chose a CSP nonce on every HTML route, so every page renders dynamically. Data is cached only at the fetch/Data Cache layer (`revalidate: 60`); ISR, PPR and `cacheComponents` are not used.
  - Rejected: `unsafe-inline` on public pages. Those pages carry teacher-authored HTML and share an origin with logged-in students.
  - Fallback if FW2 load tests miss p95 ≤ 500 ms: hash/SRI-based CSP.
- API response shape: single objects are flat (`JsonResource::withoutWrapping()`); lists are always `{data, meta, links}`.
- There is one canonical lock order: carts → orders → payment_attempts → enrollments → coupons → courses.
- Student single-session: on a new login the old session is destroyed and a cache tombstone keeps the replacement reason. There is no "adopt a NULL session" branch.
- `consents` and `audit_logs` tables exist; parent contact fields use encrypted casts. Legal questions go to legal counsel and must not be concluded by the architect.

**Why:** 14 PO-approved stories plus design reviews that initially failed. Every later story builds on this model.

**How to apply:** before designing, check `docs/architecture/README.md` §8, the "Chờ PO xác nhận" list of safe defaults. Also check whether BA has written US-015 to US-018:
- US-015: password reset
- US-016: staff account management
- US-017: consent and parent confirmation
- US-018: personal-data rights

When the PO confirms an item, update it there and in this memory. See also [[project-stack-mysql-nextjs]].

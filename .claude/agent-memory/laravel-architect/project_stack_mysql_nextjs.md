---
name: project-stack-mysql-nextjs
description: VitaminVui stack is MySQL 8.4 + Laravel 13 API-only + two separate Next.js apps; SQL Server/Blade mentions in agent templates and design docs do not apply
metadata:
  type: project
---

CLAUDE.md is the source of truth. As of 2026-09-25 it says:
- **Laravel 13 / PHP 8.3 / MySQL 8.4 (InnoDB)** — the PO first moved from 11 to 12, then chose 13 from the start.
- Laravel is a JSON API only.
- The frontend is **Next.js + Tailwind + TypeScript**, split into a student web app and an admin app.

The coordinator corrected an early prompt that said SQL Server. The PO then upgraded the stack from Laravel 11 / MySQL 8.0 after the security review flagged both as end-of-life.

**Why:** the agent definitions (laravel-dba, README examples) are generic templates written for SQL Server. laravel-designer also names components in Blade style (`<x-...>`). Both are misleading for this project.

**How to apply:**
- Design for MySQL/InnoDB: generated column + unique instead of filtered indexes, READ COMMITTED set through the PDO init command, awareness of gap locks, and named `chk_*` constraints.
- Treat Blade names in `docs/design/` as UI references only. No Blade, Livewire or Filament; the PO chose Next.js for admin.
- Remind laravel-dba to review for MySQL 8.4.
- Check framework EOL dates when choosing versions, because the security reviewer flags them.
- Related: [[project-mvp-architecture]].

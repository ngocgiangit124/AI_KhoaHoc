---
name: fa1-admin-auth
description: FA1 apps/admin - auth client-side qua /admin/auth/me, các route, lệnh docker trên macOS
metadata:
  type: project
---

Admin không SSR dữ liệu cá nhân: `SessionProvider` (lib/auth) gọi `/admin/auth/me`, suy trạng thái từ 401/403 (MFA_REQUIRED, PASSWORD_CHANGE_REQUIRED, ACCOUNT_LOCKED). Route: /dang-nhap, /xac-thuc-mfa, /doi-mat-khau, /quan-tri (AuthGate + AdminShell). `SessionWatcher` ở layout gốc xử lý login-required/idle/khoá. Không dùng react-hook-form ở admin (chưa cài), form dùng useState + zod.
Backend T28 thực tế (đọc code): login trả {mfa_required, resend_available_at, user}; có POST /admin/auth/mfa/resend; PUT /admin/auth/password cần current_password; /me có permissions + session.
Chạy kiểm tra macOS: docker run --user $(id -u):$(id -g) -e HOME=/tmp -e COREPACK_HOME=/tmp/corepack -v frontend:/workspace -w /workspace vitaminvui-frontend-dev:latest pnpm --filter @vitaminvui/admin run <script>. Test import "@/lib/api" phải vi.mock("@/env").

---
name: fw1-otp-auth-context
description: FW1 phần OTP (apps/web) - AuthProvider dùng chung /auth/me, cờ flash chỉ còn "verified", cách chạy docker kiểm tra
metadata:
  type: project
---

`/auth/me` gọi một lần qua `lib/auth/AuthProvider.tsx` (useAuth); bọc ở trang cần (home, /xac-thuc-otp), không bọc layout để khách ở /dang-ky không bị gọi thừa. Banner "Cần xác thực" theo `is_verified`/`parent_consent_status`, không theo query/tuổi client. Cờ sessionStorage `vv:account-flash` chỉ cho "verified".
Chạy kiểm tra: docker run --user $(id -u):$(id -g) -e HOME=/tmp -e COREPACK_HOME=/tmp/corepack -v frontend:/workspace vitaminvui-frontend-dev:latest pnpm -r run lint|typecheck|test|build.

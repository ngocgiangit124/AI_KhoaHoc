# Memory index — nextjs-dev

- [VitaminVui frontend workspace (FE0)](vitaminvui_fe0_workspace.md) — cấu trúc pnpm workspace, versions ghim, cách chạy Docker.
- [Docker dev environment gotchas](docker_dev_env_gotchas.md) — HOME/pnpm store trong Docker, lỗi Turbopack workspace root.
- [Playwright trong Docker không có backend](playwright_docker_no_backend.md) — chạy e2e bằng ảnh mcr.microsoft.com/playwright, mock API tối thiểu.
- [Testing Library cleanup trong Vitest](vitest_testing_library_cleanup.md) — phải tự gọi cleanup() khi không dùng vitest globals.
- [ADR-004 CSP nonce vs ISR](adr004_csp_nonce_vs_isr.md) — xung đột kiến trúc cần theo dõi ở FW2.
- [TypeScript 7 vỡ eslint-config-next](typescript7_breaks_eslint.md) — kiểm peer range typescript-eslint trước khi ghim "latest".
- [Docker container phân giải *.localhost](docker_wildcard_localhost_dns.md) — --network host + --add-host để test với backend thật.

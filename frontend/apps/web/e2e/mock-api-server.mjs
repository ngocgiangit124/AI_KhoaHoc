// Mock tối thiểu của Laravel host `api` — CHỈ dùng cho Playwright smoke test (FE0) khi
// backend thật (T01) chưa chạy. KHÔNG dùng cho việc khác. Không phụ thuộc package ngoài
// (chỉ Node "http") theo đúng danh sách package được duyệt ở FE0.
import { createServer } from "node:http";

const PORT = Number(process.env.MOCK_API_PORT ?? 8000);

const CONFIG_PUBLIC_FIXTURE = {
  referral_code_enabled: false,
  quiz_time_limit_enabled: true,
  otp: { ttl_minutes: 10, resend_cooldown_seconds: 60 },
  grades: [6, 7, 8, 9, 10, 11, 12],
  captcha_site_key: "mock-site-key",
  policy_version: "2026-09",
  parent_consent_age: 18,
};

const server = createServer((req, res) => {
  const url = new URL(req.url ?? "/", `http://${req.headers.host ?? "localhost"}`);

  if (url.pathname === "/api/v1/config/public") {
    res.writeHead(200, {
      "Content-Type": "application/json",
      "Cache-Control": "public, max-age=60",
    });
    res.end(JSON.stringify(CONFIG_PUBLIC_FIXTURE));
    return;
  }

  res.writeHead(404, { "Content-Type": "application/json" });
  res.end(JSON.stringify({ message: "Not found (mock)", code: "NOT_FOUND" }));
});

server.listen(PORT, () => {
  console.log(`[mock-api] host api (student) nghe cổng ${PORT}`);
});

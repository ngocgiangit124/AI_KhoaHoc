// Mock tối thiểu của Laravel (2 host `api` + `admin-api` — ADR-004 §2.1) CHỈ dùng cho
// Playwright smoke test (FE0) khi backend thật (T01) chưa chạy. Phân biệt host bằng
// header `Host` giống Route::domain() thật. Chỉ dùng Node "http", không thêm package.
import { createServer } from "node:http";

const PORT = Number(process.env.MOCK_API_PORT ?? 8000);
const ADMIN_ORIGIN = process.env.MOCK_ADMIN_ORIGIN ?? "http://admin.localhost:3001";

const CONFIG_PUBLIC_FIXTURE = {
  referral_code_enabled: false,
  quiz_time_limit_enabled: true,
  otp: { ttl_minutes: 10, resend_cooldown_seconds: 60 },
  grades: [6, 7, 8, 9, 10, 11, 12],
  captcha_site_key: "mock-site-key",
  policy_version: "2026-09",
  parent_consent_age: 18,
};

function isAdminHost(hostHeader) {
  return (hostHeader ?? "").startsWith("admin-api.");
}

const server = createServer((req, res) => {
  const url = new URL(req.url ?? "/", `http://${req.headers.host ?? "localhost"}`);
  const origin = req.headers.origin;
  const adminHost = isAdminHost(req.headers.host);

  // Chỉ set CORS cho host admin-api, và chỉ khi Origin đúng ADMIN_URL (EnsureAdminOrigin
  // thật — ADR-004 §2.2). Origin sai -> KHÔNG set header -> trình duyệt tự chặn (test
  // "gọi admin-api từ origin web bị chặn").
  if (adminHost && origin === ADMIN_ORIGIN) {
    res.setHeader("Access-Control-Allow-Origin", ADMIN_ORIGIN);
    res.setHeader("Access-Control-Allow-Credentials", "true");
    res.setHeader("Access-Control-Allow-Headers", "Content-Type,Accept,X-CSRF-TOKEN,X-Device-Id");
  }

  if (req.method === "OPTIONS") {
    res.writeHead(204);
    res.end();
    return;
  }

  if (adminHost && url.pathname === "/api/v1/csrf-token") {
    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(JSON.stringify({ token: "mock-csrf-token" }));
    return;
  }

  if (!adminHost && url.pathname === "/api/v1/config/public") {
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
  console.log(`[mock-api] host api + admin-api nghe cổng ${PORT} (ADMIN_ORIGIN=${ADMIN_ORIGIN})`);
});

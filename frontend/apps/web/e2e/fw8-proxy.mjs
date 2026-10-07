// Proxy kiểm thử cho e2e/home-real.spec.ts (FW8/FW9). KHÔNG dùng cho việc khác.
// Đứng giữa SSR của Next (API_INTERNAL_URL) và backend thật: chuyển tiếp nguyên trạng, trừ hai đường dẫn của trang chủ
// (`GET /courses?sort=featured` và `GET /home/teachers`) có thể bị làm lỗi/rỗng/rút gọn/chậm theo lệnh của test:
//   POST /__mode  {"courses":"ok|500|slow500|empty|slice:3","teachers":"..."}   GET /__stats   POST /__reset
// Dữ liệu vẫn là backend thật; chỉ phần "tình huống lỗi/ít dữ liệu" mới cần giả lập vì không thể tạo ra trên DB dùng chung.
import { createServer, request as httpRequest } from "node:http";

const PORT = Number(process.env.FW8_PROXY_PORT ?? 8001);
const UPSTREAM = new URL(process.env.FW8_UPSTREAM ?? "http://host.docker.internal:8000");
const UPSTREAM_HOST_HEADER = process.env.FW8_UPSTREAM_HOST ?? "api.localhost:8000";

const mode = { courses: "ok", teachers: "ok", detail: "ok" };
const stats = { courses: 0, teachers: 0, detail: 0 };

const keyOf = (url) => {
  if (url.pathname === "/api/v1/home/teachers") return "teachers";
  // Chi tiết khóa (BUG-2): `detail=404` giả lập khóa vừa ngừng bán (backend trả 404).
  if (/^\/api\/v1\/courses\/e2e-fw8-[a-z0-9-]+$/.test(url.pathname)) return "detail";
  if (url.pathname === "/api/v1/courses" && url.searchParams.get("sort") === "featured" && !url.searchParams.has("page") && !url.searchParams.has("teacher_id")) {
    return "courses";
  }
  return null;
};

const json = (res, status, body) => {
  res.writeHead(status, { "Content-Type": "application/json" });
  res.end(JSON.stringify(body));
};

const server = createServer((req, res) => {
  const url = new URL(req.url ?? "/", "http://proxy");

  if (url.pathname === "/__mode" && req.method === "POST") {
    let raw = "";
    req.on("data", (c) => (raw += c));
    req.on("end", () => {
      Object.assign(mode, JSON.parse(raw || "{}"));
      json(res, 200, { mode, stats });
    });
    return;
  }
  if (url.pathname === "/__stats") return json(res, 200, { mode, stats });
  if (url.pathname === "/__reset") {
    mode.courses = "ok";
    mode.teachers = "ok";
    mode.detail = "ok";
    stats.detail = 0;
    stats.courses = 0;
    stats.teachers = 0;
    return json(res, 200, { mode, stats });
  }

  const key = keyOf(url);
  const m = key ? mode[key] : "ok";
  if (key) stats[key] += 1;

  if (m === "404") return json(res, 404, { message: "Không tìm thấy (fw8-proxy)", code: "NOT_FOUND" });
  if (m === "500") return json(res, 500, { message: "giả lập lỗi (fw8-proxy)", code: "SERVER_ERROR" });
  if (m === "slow500") {
    setTimeout(() => json(res, 500, { message: "giả lập chậm rồi lỗi (fw8-proxy)", code: "SERVER_ERROR" }), 6500);
    return;
  }
  if (m === "empty" && key === "teachers") return json(res, 200, { data: [] });
  if (m === "empty" && key === "courses") {
    return json(res, 200, { data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 }, links: { next: null, prev: null } });
  }

  const headers = { ...req.headers, host: UPSTREAM_HOST_HEADER };
  delete headers["accept-encoding"];
  const up = httpRequest({ hostname: UPSTREAM.hostname, port: UPSTREAM.port, path: req.url, method: req.method, headers }, (upRes) => {
    const slice = /^slice:(\d+)$/.exec(m ?? "");
    if (!slice) {
      res.writeHead(upRes.statusCode ?? 502, upRes.headers);
      upRes.pipe(res);
      return;
    }
    const chunks = [];
    upRes.on("data", (c) => chunks.push(c));
    upRes.on("end", () => {
      const body = JSON.parse(Buffer.concat(chunks).toString("utf8"));
      body.data = body.data.slice(0, Number(slice[1]));
      const out = Buffer.from(JSON.stringify(body));
      const h = { ...upRes.headers, "content-length": String(out.length) };
      for (const k of ["etag", "transfer-encoding", "connection", "content-encoding"]) delete h[k];
      res.writeHead(upRes.statusCode ?? 200, h);
      res.end(out);
    });
  });
  up.on("error", () => json(res, 502, { message: "upstream lỗi (fw8-proxy)" }));
  req.pipe(up);
});

server.listen(PORT, "127.0.0.1", () => console.log(`[fw8-proxy] :${PORT} -> ${UPSTREAM.origin}`));

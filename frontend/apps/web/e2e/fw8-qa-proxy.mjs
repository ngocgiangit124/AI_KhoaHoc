// Proxy ghi log cho e2e QA FW8: chuyển tiếp nguyên trạng SSR -> backend thật; ghi lại (đường dẫn, X-Client-IP, có X-Internal-Token hay không, status).
//   GET /__log -> mảng log; POST /__reset -> xoá log.
import { createServer, request as httpRequest } from "node:http";
const UP = new URL(process.env.FW8_UPSTREAM ?? "http://host.docker.internal:8000");
let log = [];
createServer((req, res) => {
  if (req.url === "/__log") { res.writeHead(200, { "content-type": "application/json" }); return res.end(JSON.stringify(log)); }
  if (req.url === "/__reset") { log = []; res.writeHead(200); return res.end("ok"); }
  const started = Date.now();
  const h = { ...req.headers, host: "api.localhost:8000" };
  delete h["accept-encoding"];
  const up = httpRequest({ hostname: UP.hostname, port: UP.port, path: req.url, method: req.method, headers: h }, (r) => {
    log.push({ path: req.url, clientIp: req.headers["x-client-ip"] ?? null, token: Boolean(req.headers["x-internal-token"]), status: r.statusCode, ms: Date.now() - started, at: new Date().toISOString().slice(11, 19) });
    if (Date.now() - started > 3000) console.log(`[fw8-qa-proxy] chậm ${Date.now() - started} ms ${req.url}`);
    res.writeHead(r.statusCode ?? 502, r.headers);
    r.pipe(res);
  });
  up.on("error", () => { res.writeHead(502); res.end(); });
  req.pipe(up);
}).listen(8001, "127.0.0.1", () => console.log("[fw8-qa-proxy] :8001"));

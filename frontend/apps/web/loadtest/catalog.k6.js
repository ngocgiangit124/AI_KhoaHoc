// Load test FW2 (ADR-004 §2.7): p95 TTFB <= 500 ms @ 50 req/s (cache ấm), <= 1,2 s (cache lạnh).
// k6 (docker run --rm --network container:<container-next> -e MODE=warm|cold -e PORTS=3000[,3001..]
//   [-e RATE=50 -e DUR=60s] -v <thư mục chứa slugs.json + file này>:/fw grafana/k6 run /fw/catalog.k6.js)
// - Chạy cùng network namespace với `next start` (bản build production), gọi 127.0.0.1 kèm header Host: api.localhost:3000.
// - slugs.json: mảng slug lấy từ GET /api/v1/courses (các trang). MODE=cold dùng tốc độ 90 req/phút vì
//   Laravel `throttle:catalog` chỉ cho 120 req/phút/IP và mọi SSR đến từ 1 IP (xem README mục FW2).
// - Chỉ đo được ý nghĩa trên máy yên tĩnh: máy dev đang chạy nhiều container khác sẽ làm p95 sai lệch.
import http from "k6/http";
import { check } from "k6";
import { SharedArray } from "k6/data";

const slugs = new SharedArray("slugs", () => JSON.parse(open(__ENV.SLUGS_FILE || "/fw/slugs.json")));
const MODE = __ENV.MODE; // warm | cold
const PORTS = (__ENV.PORTS || "3000").split(",");
const base = () => `http://127.0.0.1:${PORTS[__ITER % PORTS.length]}`;
const params = (tag) => ({ headers: { Host: "api.localhost:3000" }, tags: { page: tag } });

const FIXED = ["/khoa-hoc", "/khoa-hoc?grade=9", "/khoa-hoc?sort=popular", "/lop-9", "/lop-10", "/lop-10?page=2", "/lop-8"];

function pickUrl(i) {
  // 50% chi tiết khóa, 50% danh mục (đúng tỉ lệ xem trang thường gặp)
  if (i % 2 === 0) return [`/khoa-hoc/${slugs[i % slugs.length]}`, "detail"];
  return [FIXED[i % FIXED.length], "list"];
}

export const options = {
  scenarios:
    MODE === "warm"
      ? { warm: { executor: "constant-arrival-rate", rate: Number(__ENV.RATE || 50), timeUnit: "1s", duration: __ENV.DUR || "60s", preAllocatedVUs: 50, maxVUs: 300, exec: "warm" } }
      : {
          // dưới giới hạn throttle:catalog 120 req/phút/IP (mọi SSR đến từ 1 IP) — mỗi request là 1 lần MISS Data Cache
          cold: { executor: "constant-arrival-rate", rate: 90, timeUnit: "1m", duration: "60s", preAllocatedVUs: 5, maxVUs: 20, exec: "cold" },
        },
  thresholds:
    MODE === "warm"
      ? { "http_req_waiting{scenario:warm}": ["p(95)<500"], "http_req_failed{scenario:warm}": ["rate<0.001"] }
      : { "http_req_waiting{scenario:cold}": ["p(95)<1200"], "http_req_failed{scenario:cold}": ["rate<0.001"] },
  summaryTrendStats: ["avg", "min", "med", "p(90)", "p(95)", "p(99)", "max"],
};

export function setup() {
  if (MODE !== "warm") return;
  // làm ấm Data Cache cho toàn bộ URL sẽ dùng
  for (const p of FIXED) http.get(`http://127.0.0.1:${PORTS[0]}` + p, params("warmup"));
  for (const s of slugs) http.get(`http://127.0.0.1:${PORTS[0]}/khoa-hoc/${s}`, params("warmup"));
}

export function warm() {
  const [url, tag] = pickUrl(__ITER);
  const r = http.get(base() + url, params(tag));
  check(r, { "200": (x) => x.status === 200 });
}

let n = 0;
export function cold() {
  // Mỗi request một khóa cache mới: chi tiết lần đầu (slug chưa gọi) hoặc danh mục với q ngẫu nhiên.
  const i = __ITER;
  let url, tag;
  if (i % 2 === 0 && Math.floor(i / 2) < slugs.length) {
    url = `/khoa-hoc/${slugs[Math.floor(i / 2)]}`;
    tag = "detail";
  } else {
    url = `/khoa-hoc?q=toan${Math.random().toString(36).slice(2, 8)}&grade=${6 + (i % 7)}`;
    tag = "list";
  }
  const r = http.get(base() + url, params(tag));
  check(r, { "200": (x) => x.status === 200 });
}

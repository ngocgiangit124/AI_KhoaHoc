import { NextRequest } from "next/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

beforeEach(() => {
  vi.resetModules();
  vi.stubEnv("NEXT_PUBLIC_API_URL", "http://api.localhost:8000");
  vi.stubEnv("NEXT_PUBLIC_SITE_URL", "http://api.localhost:3000");
  vi.stubEnv("NEXT_PUBLIC_STATIC_URL", "http://localhost:8080");
});
afterEach(() => vi.unstubAllEnvs());

async function run(path: string, headers: Record<string, string> = {}) {
  const { proxy } = await import("./proxy");
  return proxy(new NextRequest(`http://api.localhost:3000${path}`, { headers }));
}

describe("proxy — cổng /v2", () => {
  it("production không V2_PREVIEW: rewrite sang route không tồn tại, vẫn có CSP nonce + header bảo mật", async () => {
    vi.stubEnv("NODE_ENV", "production");
    vi.stubEnv("V2_PREVIEW", "");
    for (const p of ["/v2", "/v2/khoa-hoc", "/%76%32/khoa-hoc", "/V2"]) {
      const res = await run(p);
      expect(res.headers.get("x-middleware-rewrite"), p).toContain("/khong-ton-tai");
      expect(res.headers.get("Content-Security-Policy"), p).toMatch(/nonce-/);
      expect(res.headers.get("X-Content-Type-Options"), p).toBe("nosniff");
    }
  });

  it("V2_PREVIEW=1 và development: không rewrite; route thật không bị chặn", async () => {
    vi.stubEnv("NODE_ENV", "production");
    vi.stubEnv("V2_PREVIEW", "1");
    expect((await run("/v2/khoa-hoc")).headers.get("x-middleware-rewrite")).toBeNull();
    vi.stubEnv("V2_PREVIEW", "");
    expect((await run("/khoa-hoc")).headers.get("x-middleware-rewrite")).toBeNull();
  });

  it("/dang-ky, /quen-mat-khau và bước 2 có Cloudflare Turnstile trong CSP, route khác không", async () => {
    vi.stubEnv("NODE_ENV", "production");
    for (const path of ["/dang-ky", "/quen-mat-khau", "/quen-mat-khau/dat-lai"]) {
      expect((await run(path)).headers.get("Content-Security-Policy"), path).toContain("challenges.cloudflare.com");
    }
    expect((await run("/khoa-hoc")).headers.get("Content-Security-Policy")).not.toContain("challenges.cloudflare.com");
  });

  it("matcher có entry riêng cho /v2 KHÔNG có `missing` (request prefetch cũng bị chặn)", async () => {
    const { config } = await import("./proxy");
    const entries = config.matcher as Array<{ source: string; missing?: unknown }>;
    for (const source of ["/v2", "/v2/:path*"]) {
      const e = entries.find((m) => m.source === source);
      expect(e, source).toBeDefined();
      expect(e?.missing).toBeUndefined();
    }
  });
});

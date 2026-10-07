import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import { ApiError, NetworkError, publicFetch } from "@vitaminvui/api-client";
import { afterEach, describe, expect, it, vi } from "vitest";
import { isUpstreamBusy } from "./busy";

describe("isUpstreamBusy", () => {
  it("429, 5xx và mất kết nối -> bận; 404/422 và lỗi lạ -> không", () => {
    expect(isUpstreamBusy(new ApiError(429, { message: "x" }, 30))).toBe(true);
    expect(isUpstreamBusy(new ApiError(500, { message: "x" }))).toBe(true);
    expect(isUpstreamBusy(new ApiError(503, { message: "x" }))).toBe(true);
    expect(isUpstreamBusy(new NetworkError(new Error("x")))).toBe(true);
    expect(isUpstreamBusy(new ApiError(404, { message: "x" }))).toBe(false);
    expect(isUpstreamBusy(new ApiError(422, { message: "x" }))).toBe(false);
    expect(isUpstreamBusy(new Error("bug"))).toBe(false);
  });
});

describe("429/5xx không được lưu vào Data Cache", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("publicFetch ném ApiError (không trả dữ liệu) khi API trả 429/500", async () => {
    for (const status of [429, 500, 503]) {
      vi.stubGlobal("fetch", vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: "bận" }), { status, headers: { "Retry-After": "30" } })));
      await expect(publicFetch("http://api.test", "/api/v1/courses", { revalidate: 60, tags: ["catalog"] })).rejects.toMatchObject({ status });
    }
  });

  it("Next chỉ ghi Data Cache cho response 200 (khoá cứng theo bản Next đang dùng — xem lại khi nâng cấp Next)", () => {
    const req = createRequire(import.meta.url);
    const file = req.resolve("next/dist/server/lib/patch-fetch.js");
    expect(readFileSync(file, "utf8")).toMatch(/res\.status === 200 && incrementalCache && cacheKey/);
  });
});

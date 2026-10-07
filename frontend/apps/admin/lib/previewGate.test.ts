import { afterEach, describe, expect, it, vi } from "vitest";
import { isBlockedPreview, isPreviewPath } from "./previewGate";

afterEach(() => vi.unstubAllEnvs());

describe("isPreviewPath", () => {
  it("nhận /v2, /v2/..., biến thể mã hoá phần trăm, hoa thường, nhiều dấu /", () => {
    for (const p of ["/v2", "/v2/", "/v2/quan-tri/khoa-hoc", "/%76%32", "/%76%32/quan-tri", "/V2/x", "//v2/x"]) expect(isPreviewPath(p), p).toBe(true);
  });
  it("không nhận nhầm /v2x, /quan-tri, /dang-nhap, /xv2", () => {
    for (const p of ["/v2x", "/v2x/a", "/quan-tri", "/quan-tri/v2", "/dang-nhap", "/xv2", "/"]) expect(isPreviewPath(p), p).toBe(false);
  });
});

describe("isBlockedPreview", () => {
  it("production không đặt V2_PREVIEW → chặn", () => {
    vi.stubEnv("NODE_ENV", "production");
    vi.stubEnv("V2_PREVIEW", "");
    expect(isBlockedPreview("/v2/quan-tri/khoa-hoc")).toBe(true);
    expect(isBlockedPreview("/%76%32")).toBe(true);
  });
  it("production V2_PREVIEW=1 → cho qua; giá trị khác 1 vẫn chặn", () => {
    vi.stubEnv("NODE_ENV", "production");
    vi.stubEnv("V2_PREVIEW", "1");
    expect(isBlockedPreview("/v2")).toBe(false);
    vi.stubEnv("V2_PREVIEW", "true");
    expect(isBlockedPreview("/v2")).toBe(true);
  });
  it("development → không chặn", () => {
    vi.stubEnv("NODE_ENV", "development");
    vi.stubEnv("V2_PREVIEW", "");
    expect(isBlockedPreview("/v2/quan-tri/khoa-hoc")).toBe(false);
  });
  it("route thường không bị chặn nhầm ở production", () => {
    vi.stubEnv("NODE_ENV", "production");
    vi.stubEnv("V2_PREVIEW", "");
    for (const p of ["/quan-tri", "/quan-tri/chuyen-de", "/dang-nhap", "/v2x"]) expect(isBlockedPreview(p), p).toBe(false);
  });
});

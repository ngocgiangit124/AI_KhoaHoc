import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const VALID_ENV = {
  NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.localhost:8000",
  NEXT_PUBLIC_ADMIN_URL: "http://admin-api.localhost:3001",
  NEXT_PUBLIC_STATIC_URL: "http://localhost:8080",
  NEXT_PUBLIC_VIDEO_UPLOAD_URL: "http://video.localhost:8000",
};

const ORIGINAL_ENV = { ...process.env };

describe("env.ts (admin) — validate biến môi trường công khai bằng zod", () => {
  beforeEach(() => {
    vi.resetModules();
    process.env = { ...ORIGINAL_ENV };
  });

  afterEach(() => {
    process.env = { ...ORIGINAL_ENV };
  });

  it("parse thành công khi đủ biến hợp lệ", async () => {
    Object.assign(process.env, VALID_ENV);
    const { env } = await import("./env");
    expect(env.NEXT_PUBLIC_ADMIN_URL).toBe("http://admin-api.localhost:3001");
  });

  it("ném lỗi rõ ràng khi thiếu NEXT_PUBLIC_VIDEO_UPLOAD_URL", async () => {
    Object.assign(process.env, VALID_ENV);
    delete process.env.NEXT_PUBLIC_VIDEO_UPLOAD_URL;
    await expect(import("./env")).rejects.toThrow(/NEXT_PUBLIC_VIDEO_UPLOAD_URL/);
  });
});

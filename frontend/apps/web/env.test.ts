import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const REQUIRED_ENV = {
  NEXT_PUBLIC_API_URL: "http://api.localhost:8000",
  NEXT_PUBLIC_SITE_URL: "http://api.localhost:3000",
  NEXT_PUBLIC_STATIC_URL: "http://localhost:8080",
};

const ORIGINAL_ENV = { ...process.env };

describe("env.ts — validate biến môi trường công khai bằng zod", () => {
  beforeEach(() => {
    vi.resetModules();
    process.env = { ...ORIGINAL_ENV };
  });

  afterEach(() => {
    process.env = { ...ORIGINAL_ENV };
  });

  it("tách NEXT_PUBLIC_VIDEO_HOSTS/NEXT_PUBLIC_MOMO_HOSTS thành mảng theo dấu phẩy", async () => {
    Object.assign(process.env, REQUIRED_ENV, {
      NEXT_PUBLIC_VIDEO_HOSTS: "video.localhost:8000, cdn.vitaminvui.vn",
      NEXT_PUBLIC_MOMO_HOSTS: "test-payment.momo.vn,payment.momo.vn",
    });

    const { env } = await import("./env");

    expect(env.NEXT_PUBLIC_VIDEO_HOSTS).toEqual(["video.localhost:8000", "cdn.vitaminvui.vn"]);
    expect(env.NEXT_PUBLIC_MOMO_HOSTS).toEqual(["test-payment.momo.vn", "payment.momo.vn"]);
  });

  it("NEXT_PUBLIC_VIDEO_HOSTS đi thẳng vào CSP nên mỗi phần tử phải là origin: chèn directive/đường dẫn bị từ chối", async () => {
    for (const bad of ["cdn.vn; script-src *", "https://cdn.vn/path", "cdn vn", "javascript:alert(1)"]) {
      vi.resetModules();
      Object.assign(process.env, REQUIRED_ENV, { NEXT_PUBLIC_VIDEO_HOSTS: bad });
      await expect(import("./env"), bad).rejects.toThrow(/NEXT_PUBLIC_VIDEO_HOSTS/);
    }
  });

  it("NEXT_PUBLIC_MOMO_HOSTS mặc định rỗng khi không đặt hoặc đặt chuỗi rỗng (D5)", async () => {
    Object.assign(process.env, REQUIRED_ENV);
    delete process.env.NEXT_PUBLIC_MOMO_HOSTS;
    delete process.env.NEXT_PUBLIC_VIDEO_HOSTS;

    const { env } = await import("./env");

    expect(env.NEXT_PUBLIC_MOMO_HOSTS).toEqual([]);
    expect(env.NEXT_PUBLIC_VIDEO_HOSTS).toEqual([]);

    process.env.NEXT_PUBLIC_MOMO_HOSTS = "";
    vi.resetModules();
    const { env: env2 } = await import("./env");
    expect(env2.NEXT_PUBLIC_MOMO_HOSTS).toEqual([]);
  });

  it("ném lỗi rõ ràng khi thiếu biến bắt buộc", async () => {
    process.env.NEXT_PUBLIC_API_URL = undefined;
    delete process.env.NEXT_PUBLIC_API_URL;
    process.env.NEXT_PUBLIC_SITE_URL = REQUIRED_ENV.NEXT_PUBLIC_SITE_URL;
    process.env.NEXT_PUBLIC_STATIC_URL = REQUIRED_ENV.NEXT_PUBLIC_STATIC_URL;

    await expect(import("./env")).rejects.toThrow(/NEXT_PUBLIC_API_URL/);
  });

  it("ném lỗi khi biến không phải URL hợp lệ", async () => {
    Object.assign(process.env, REQUIRED_ENV, { NEXT_PUBLIC_API_URL: "khong-phai-url" });
    await expect(import("./env")).rejects.toThrow();
  });
});

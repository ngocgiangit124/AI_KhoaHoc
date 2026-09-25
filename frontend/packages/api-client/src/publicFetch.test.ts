import { afterEach, describe, expect, it, vi } from "vitest";
import { publicFetch } from "./publicFetch";
import { ApiError } from "./errors";

const BASE_URL = "http://api.localhost:8000";

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

describe("publicFetch", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("không gửi cookie (credentials: 'omit')", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(200, { grades: [6, 7, 8, 9, 10, 11, 12] }));
    vi.stubGlobal("fetch", fetchMock);

    await publicFetch(BASE_URL, "/api/v1/config/public");

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.credentials).toBe("omit");
  });

  it("lỗi 4xx/5xx ném ApiError kèm code", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(jsonResponse(404, { message: "Không tìm thấy", code: "NOT_FOUND" }));
    vi.stubGlobal("fetch", fetchMock);

    await expect(publicFetch(BASE_URL, "/api/v1/courses/khong-ton-tai")).rejects.toMatchObject({
      code: "NOT_FOUND",
      status: 404,
    });
  });

  it("lỗi ném đúng kiểu ApiError", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(500, { message: "Lỗi máy chủ" }));
    vi.stubGlobal("fetch", fetchMock);

    await expect(publicFetch(BASE_URL, "/api/v1/subjects")).rejects.toBeInstanceOf(ApiError);
  });
});

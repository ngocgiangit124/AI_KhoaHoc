import { describe, expect, it } from "vitest";
import { ApiError } from "./errors";
import { parseJsonResponse } from "./http";

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json", ...headers },
  });
}

describe("parseJsonResponse — Retry-After (T04 security review §L3, api-contract §1.7)", () => {
  it("429 có header Retry-After -> ApiError.retryAfterSeconds đọc đúng giá trị", async () => {
    const res = jsonResponse(
      429,
      { message: "Bạn gửi mã quá nhanh, vui lòng thử lại sau.", code: "TOO_MANY_ATTEMPTS" },
      { "Retry-After": "42" },
    );

    await expect(parseJsonResponse(res)).rejects.toSatisfy((err: unknown) => {
      expect(err).toBeInstanceOf(ApiError);
      expect((err as ApiError).retryAfterSeconds).toBe(42);
      return true;
    });
  });

  it("429 không có Retry-After -> retryAfterSeconds là undefined (lớp Service trước fix L3, hoặc lỗi khác)", async () => {
    const res = jsonResponse(429, { message: "Bạn thao tác quá nhanh, vui lòng thử lại sau.", code: "TOO_MANY_ATTEMPTS" });

    await expect(parseJsonResponse(res)).rejects.toSatisfy((err: unknown) => {
      expect(err).toBeInstanceOf(ApiError);
      expect((err as ApiError).retryAfterSeconds).toBeUndefined();
      return true;
    });
  });

  it("422 (không liên quan Retry-After) vẫn không có retryAfterSeconds", async () => {
    const res = jsonResponse(422, { message: "Mã OTP không đúng, vui lòng thử lại.", code: "VALIDATION_ERROR" });

    await expect(parseJsonResponse(res)).rejects.toSatisfy((err: unknown) => {
      expect(err).toBeInstanceOf(ApiError);
      expect((err as ApiError).retryAfterSeconds).toBeUndefined();
      return true;
    });
  });
});

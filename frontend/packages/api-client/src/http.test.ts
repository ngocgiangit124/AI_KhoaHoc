import { describe, expect, it } from "vitest";
import { ApiError } from "./errors";
import { parseJsonResponse } from "./http";

describe("parseJsonResponse — Retry-After", () => {
  it("429 có Retry-After dạng số giây → ApiError.retryAfterSeconds", async () => {
    const res = new Response(JSON.stringify({ message: "Chờ.", code: "TOO_MANY_ATTEMPTS" }), {
      status: 429,
      headers: { "Retry-After": "37" },
    });
    const err = await parseJsonResponse(res).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).retryAfterSeconds).toBe(37);
  });

  it("không có hoặc không phải số → undefined", async () => {
    const res = new Response("{}", { status: 429, headers: { "Retry-After": "Wed, 21 Oct 2026 07:28:00 GMT" } });
    const err = (await parseJsonResponse(res).catch((e: unknown) => e)) as ApiError;
    expect(err.retryAfterSeconds).toBeUndefined();
  });
});

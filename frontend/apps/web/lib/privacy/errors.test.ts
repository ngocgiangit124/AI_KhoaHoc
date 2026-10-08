import { ApiError } from "@vitaminvui/api-client";
import { describe, expect, it } from "vitest";
import {
  classifyAcceptError,
  classifyDeleteSendError,
  classifyExportError,
  classifyParentContactError,
  classifyUnsubscribeError,
  domainValue,
  EXPORT_LIMIT_TEXT,
} from "./errors";

// `errors` của lỗi nghiệp vụ chứa chuỗi (không phải mảng) — ép kiểu như thực tế JSON.
const err = (status: number, body: Record<string, unknown>, retry?: number) => new ApiError(status, body as unknown as ConstructorParameters<typeof ApiError>[1], retry);

describe("domainValue", () => {
  it("đọc chuỗi và phần tử đầu của mảng", () => {
    const e = err(429, { message: "x", errors: { resets_at: "2026-10-09T00:00:00+07:00", current_password: ["Sai"] } });
    expect(domainValue(e, "resets_at")).toBe("2026-10-09T00:00:00+07:00");
    expect(domainValue(e, "current_password")).toBe("Sai");
    expect(domainValue(e, "khong_co")).toBeUndefined();
  });
});

describe("classifyExportError", () => {
  it("429 DATA_EXPORT_LIMIT lấy errors.resets_at", () => {
    const f = classifyExportError(err(429, { message: "m", code: "DATA_EXPORT_LIMIT", errors: { limit: 2, resets_at: "2026-10-09T00:00:00+07:00" } }, 3600));
    expect(f).toEqual({ kind: "limit", resetsAt: "2026-10-09T00:00:00+07:00" });
    expect(EXPORT_LIMIT_TEXT(2, "2026-10-09T00:00:00+07:00")).toBe("Bạn đã tải 2 lần hôm nay. Thử lại sau 00:00, 09/10/2026.");
  });
  it("429 TOO_MANY_ATTEMPTS là throttle, 422 current_password là lỗi field, 500 giữ request_id", () => {
    expect(classifyExportError(err(429, { message: "m", code: "TOO_MANY_ATTEMPTS" }, 120)).kind).toBe("throttled");
    expect(classifyExportError(err(422, { message: "m", errors: { current_password: ["Mật khẩu không đúng."] } }))).toEqual({ kind: "password", message: "Mật khẩu không đúng." });
    expect(classifyExportError(err(500, { message: "Lỗi", request_id: "r1" }))).toMatchObject({ kind: "banner", requestId: "r1" });
  });
});

describe("classifyDeleteSendError", () => {
  it("403 ACCOUNT_NOT_VERIFIED", () => {
    expect(classifyDeleteSendError(err(403, { message: "Bạn cần xác thực email trước khi xoá tài khoản.", code: "ACCOUNT_NOT_VERIFIED" })).kind).toBe("not-verified");
  });
  it("409 ACCOUNT_HAS_PENDING_PAYMENT hiện giờ VN từ errors.retry_after_at", () => {
    const f = classifyDeleteSendError(err(409, { message: "m", code: "ACCOUNT_HAS_PENDING_PAYMENT", errors: { retry_after_at: "2026-10-08T15:30:00+07:00" } }));
    expect(f).toEqual({ kind: "pending-payment", message: "Bạn đang có đơn chờ thanh toán. Hãy thử lại sau 15:30, 08/10/2026." });
  });
  it("429 có Retry-After ngắn -> wait", () => {
    expect(classifyDeleteSendError(err(429, { message: "m", code: "TOO_MANY_ATTEMPTS" }, 45))).toMatchObject({ kind: "wait", seconds: 45 });
  });
});

describe("classifyParentContactError", () => {
  it("422 theo field", () => {
    const f = classifyParentContactError(err(422, { message: "m", errors: { parent_email: ["Phải khác email của bạn"], current_password: ["Sai"] } }));
    expect(f).toEqual({ kind: "fields", errors: { parent_email: "Phải khác email của bạn", current_password: "Sai" } });
  });
  it("429 -> throttled", () => {
    expect(classifyParentContactError(err(429, { message: "m" }, 300)).kind).toBe("throttled");
  });
});

describe("classifyAcceptError / classifyUnsubscribeError", () => {
  it("409 CONSENT_VERSION_CHANGED lấy errors.current_version", () => {
    expect(classifyAcceptError(err(409, { message: "m", code: "CONSENT_VERSION_CHANGED", errors: { current_version: "2026-11" } }))).toEqual({ kind: "version-changed", currentVersion: "2026-11" });
  });
  it("unsubscribe: 422 -> link hỏng, 429/mạng -> thử lại", () => {
    expect(classifyUnsubscribeError(err(422, { message: "m" })).kind).toBe("invalid-link");
    expect(classifyUnsubscribeError(err(429, { message: "m" }, 60)).kind).toBe("retry");
    expect(classifyUnsubscribeError(new Error("x")).kind).toBe("retry");
  });
});

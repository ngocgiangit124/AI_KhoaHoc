import { describe, expect, it } from "vitest";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { classifyDecisionError, listErrorMessage } from "./errors";
import { parseRequestQuery, requestQueryToApi, requestQueryToSearch } from "./query";

const params = (s: string) => new URLSearchParams(s);

describe("query", () => {
  it("mặc định: chờ duyệt, trang 1, 25 dòng; URL gọn", () => {
    const q = parseRequestQuery(params(""));
    expect(q).toEqual({ status: "pending_approval", courseId: null, page: 1, perPage: 25 });
    expect(requestQueryToSearch(q)).toBe("");
    expect(requestQueryToApi(q)).toBe("status=pending_approval&per_page=25&page=1");
  });

  it("đọc và ghi lại bộ lọc", () => {
    const q = parseRequestQuery(params("status=rejected&course_id=7&page=3&per_page=50"));
    expect(q).toEqual({ status: "rejected", courseId: 7, page: 3, perPage: 50 });
    expect(requestQueryToSearch(q)).toBe("?status=rejected&course_id=7&per_page=50&page=3");
    expect(requestQueryToApi(q)).toContain("course_id=7");
  });

  it("giá trị lạ rơi về mặc định (không gửi tham số sai lên API)", () => {
    expect(parseRequestQuery(params("status=x&course_id=-1&page=0&per_page=7"))).toEqual({ status: "pending_approval", courseId: null, page: 1, perPage: 25 });
    expect(parseRequestQuery(params("course_id=abc&page=1.5")).courseId).toBeNull();
  });
});

describe("classifyDecisionError", () => {
  it("409 ALREADY_PROCESSED → stale", () => {
    const f = classifyDecisionError(new ApiError(409, { message: "x", code: "ALREADY_PROCESSED" }));
    expect(f.stale).toBe(true);
    expect(f.message).toMatch(/người khác xử lý/);
  });
  it("403 và 404 → stale", () => {
    expect(classifyDecisionError(new ApiError(403, { message: "x", code: "FORBIDDEN" })).stale).toBe(true);
    expect(classifyDecisionError(new ApiError(404, { message: "x" })).stale).toBe(true);
  });
  it("COURSE_NOT_FREE / COURSE_UNAVAILABLE giữ dòng, không tải lại", () => {
    const a = classifyDecisionError(new ApiError(422, { message: "x", code: "COURSE_NOT_FREE" }));
    const b = classifyDecisionError(new ApiError(409, { message: "x", code: "COURSE_UNAVAILABLE" }));
    expect(a.stale).toBe(false);
    expect(a.message).toMatch(/có phí/);
    expect(b.stale).toBe(false);
    expect(b.message).toMatch(/bị xoá/);
  });
  it("422 reason → lỗi dưới ô lý do", () => {
    const f = classifyDecisionError(new ApiError(422, { message: "x", code: "VALIDATION_ERROR", errors: { reason: ["Lý do không được chứa HTML."] } }));
    expect(f.reasonError).toBe("Lý do không được chứa HTML.");
  });
  it("lỗi mạng và lỗi lạ", () => {
    expect(classifyDecisionError(new NetworkError(new Error("x"))).stale).toBe(false);
    expect(classifyDecisionError(new Error("x")).message).toBeTruthy();
    expect(listErrorMessage(new ApiError(403, { message: "x" }))).toMatch(/không có quyền/);
  });
});

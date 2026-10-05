import { describe, expect, it, vi } from "vitest";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { classifySubjectFormError, isSubjectInUse } from "./errors";
import { normalizeSubjectName, parseSubjectQuery, subjectQueryToApi, subjectQueryToSearch, validateSubjectName } from "./query";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));

const params = (s: string) => new URLSearchParams(s);

describe("parseSubjectQuery", () => {
  it("mặc định khi trống", () => {
    expect(parseSubjectQuery(params(""))).toEqual({ q: "", status: "", page: 1, perPage: 25 });
  });
  it("loại giá trị lạ", () => {
    expect(parseSubjectQuery(params("status=x&page=-3&per_page=77"))).toEqual({ q: "", status: "", page: 1, perPage: 25 });
    expect(parseSubjectQuery(params("page=abc"))).toMatchObject({ page: 1 });
  });
  it("nhận giá trị hợp lệ", () => {
    expect(parseSubjectQuery(params("q=%20Đại%20số%20&status=hidden&page=3&per_page=50"))).toEqual({ q: "Đại số", status: "hidden", page: 3, perPage: 50 });
  });
});

describe("query string", () => {
  it("URL trang bỏ tham số mặc định", () => {
    expect(subjectQueryToSearch({ q: "", status: "", page: 1, perPage: 25 })).toBe("");
    expect(subjectQueryToSearch({ q: "a b", status: "active", page: 2, perPage: 50 })).toBe("?q=a+b&status=active&per_page=50&page=2");
  });
  it("API: giáo viên không gửi status", () => {
    const q = { q: "x", status: "hidden" as const, page: 2, perPage: 25 as const };
    expect(subjectQueryToApi(q, { includeStatus: true })).toBe("q=x&status=hidden&per_page=25&page=2");
    expect(subjectQueryToApi(q, { includeStatus: false })).toBe("q=x&per_page=25&page=2");
  });
});

describe("validateSubjectName", () => {
  it("chuẩn hoá khoảng trắng", () => expect(normalizeSubjectName("  Hình   học ")).toBe("Hình học"));
  it("rỗng/toàn khoảng trắng", () => {
    expect(validateSubjectName("   ")).toBe("Vui lòng nhập tên chuyên đề");
  });
  it("quá dài, thẻ HTML, ký tự điều khiển", () => {
    expect(validateSubjectName("a".repeat(101))).toMatch(/tối đa 100/);
    expect(validateSubjectName("<b>x</b>")).toMatch(/< hoặc >/);
    expect(validateSubjectName("a\u0007b")).toMatch(/điều khiển/);
  });
  it("hợp lệ", () => expect(validateSubjectName("Ôn thi vào lớp 10")).toBeNull());
});

describe("lỗi API", () => {
  const err = (status: number, body: ConstructorParameters<typeof ApiError>[1]) => new ApiError(status, body);
  it("422 → dưới field name", () => {
    expect(classifySubjectFormError(err(422, { message: "x", errors: { name: ["Chuyên đề đã tồn tại."] } }))).toEqual({ nameError: "Chuyên đề đã tồn tại.", banner: null });
  });
  it("422 field lạ → banner", () => {
    expect(classifySubjectFormError(err(422, { message: "x", errors: { other: ["Lạ"] } })).banner).toBe("Lạ");
  });
  it("403/mạng", () => {
    expect(classifySubjectFormError(err(403, { message: "m", code: "FORBIDDEN" })).banner).toMatch(/không có quyền/);
    expect(classifySubjectFormError(new NetworkError(new Error("x"))).banner).toMatch(/kết nối/);
  });
  it("409 SUBJECT_IN_USE", () => {
    expect(isSubjectInUse(err(409, { message: "m", code: "SUBJECT_IN_USE" }))).toBe(true);
    expect(isSubjectInUse(err(409, { message: "m", code: "OTHER" }))).toBe(false);
  });
});

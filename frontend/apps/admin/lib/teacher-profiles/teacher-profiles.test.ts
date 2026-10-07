import { describe, expect, it } from "vitest";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { navForUser } from "@/lib/nav";
import { clampPos, coverScale, cropRect, outputSide } from "./crop";
import { classifyProfileError, homepageLimitMessage, isConsentVersionChanged, isHomepageLimit, profileActionError } from "./errors";
import { buildContentPatch, normalizeText, pruneDraft, validateDraft } from "./form";
import { parseProfileQuery, profileQueryToApi, profileQueryToSearch } from "./query";
import { checklistFor, notShownLabel } from "./reasons";

const api = (status: number, body: { message?: string; code?: string; errors?: Record<string, string[]> }) => new ApiError(status, { message: body.message ?? "x", ...body });

describe("form: văn bản thuần + PATCH chỉ trường đã đổi (AC21)", () => {
  it("chuẩn hoá xuống dòng và trim", () => {
    expect(normalizeText("  a\r\nb \r\n")).toBe("a\nb");
  });

  it("chỉ gửi trường đã đổi; rỗng → null; không đổi → null", () => {
    const profile = { headline: "Toán 9", bio: "Dòng 1\nDòng 2" };
    expect(buildContentPatch(profile, {})).toBeNull();
    expect(buildContentPatch(profile, { headline: "Toán 9 ", bio: "Dòng 1\r\nDòng 2" })).toBeNull();
    expect(buildContentPatch(profile, { bio: "Mới" })).toEqual({ bio: "Mới" });
    expect(buildContentPatch(profile, { headline: "  " })).toEqual({ headline: null });
    expect(buildContentPatch({ headline: null, bio: null }, { headline: "", bio: "" })).toBeNull();
  });

  it("kiểm độ dài 120/600 theo ký tự (emoji tính 1) và chặn < >", () => {
    expect(validateDraft({ headline: "a".repeat(120), bio: "b".repeat(600) })).toEqual({});
    expect(validateDraft({ headline: "a".repeat(121) }).headline).toMatch(/120/);
    expect(validateDraft({ bio: "b".repeat(601) }).bio).toMatch(/600/);
    expect(validateDraft({ bio: "😀".repeat(600) })).toEqual({});
    expect(validateDraft({ bio: "<script>alert(1)</script>" }).bio).toMatch(/<|>/);
    expect(validateDraft({ headline: "a<b" }).headline).toBeDefined();
    // xuống dòng được phép ở bio, không ở headline
    expect(validateDraft({ bio: "a\nb" })).toEqual({});
    expect(validateDraft({ headline: "a\nb" }).headline).toBeDefined();
    // trường không sửa thì không báo lỗi
    expect(validateDraft({})).toEqual({});
  });

  it("pruneDraft bỏ trường đã gửi, giữ trường đang sửa dở, bỏ trường trùng bản lưu", () => {
    const saved = { headline: "H", bio: "B" };
    expect(pruneDraft(saved, { headline: "Mới", bio: "Dở dang" }, ["headline"])).toEqual({ bio: "Dở dang" });
    expect(pruneDraft(saved, { bio: "B" }, [])).toEqual({});
  });
});

describe("query trên URL", () => {
  it("giá trị lạ rơi về mặc định, không gửi tham số sai lên API", () => {
    const q = parseProfileQuery(new URLSearchParams("q=%20Lan%20&page=-3&per_page=7&homepage=2"));
    expect(q).toEqual({ q: "Lan", onlyEnabled: false, page: 1, perPage: 25 });
    expect(profileQueryToApi(q)).toBe("q=Lan&per_page=25&page=1");
  });
  it("khứ hồi q/homepage/page/per_page", () => {
    const q = parseProfileQuery(new URLSearchParams("q=Lan&homepage=1&page=2&per_page=50"));
    expect(q).toEqual({ q: "Lan", onlyEnabled: true, page: 2, perPage: 50 });
    expect(profileQueryToSearch(q)).toBe("?q=Lan&homepage=1&per_page=50&page=2");
    expect(profileQueryToApi(q)).toBe("q=Lan&homepage=1&per_page=50&page=2");
    expect(profileQueryToSearch(parseProfileQuery(new URLSearchParams("")))).toBe("");
  });
});

describe("lý do chưa hiện (AC1, AC9)", () => {
  it("dịch sang tiếng Việt theo thứ tự server trả", () => {
    expect(notShownLabel(["account_locked", "no_consent", "no_avatar"])).toBe("Chưa hiện: tài khoản bị khoá, chưa đồng ý công khai, chưa có ảnh");
    expect(notShownLabel([])).toBe("");
    expect(notShownLabel(["ma_la"])).toBe("Chưa hiện: ma_la");
  });
  it("checklist: đạt khi lý do không có", () => {
    const items = checklistFor(["no_bio"]);
    expect(items.find((i) => i.key === "no_bio")?.ok).toBe(false);
    expect(items.filter((i) => i.ok).length).toBe(items.length - 1);
  });
});

describe("mapping lỗi", () => {
  it("422 → dưới đúng ô; ảnh, nội dung; field lạ → banner", () => {
    const f = classifyProfileError(api(422, { errors: { bio: ["Giới thiệu quá dài"], avatar: ["Ảnh sai định dạng"], other: ["Lỗi khác"] } }));
    expect(f.fields).toEqual({ bio: "Giới thiệu quá dài", avatar: "Ảnh sai định dạng" });
    expect(f.banner).toBe("Lỗi khác");
  });
  it("403/404/413/NOT_TEACHER/mạng", () => {
    expect(classifyProfileError(api(403, { code: "FORBIDDEN" })).banner).toMatch(/quyền/);
    const gone = classifyProfileError(api(404, { code: "NOT_FOUND" }));
    expect(gone.gone).toBe(true);
    expect(classifyProfileError(api(413, {})).fields.avatar).toMatch(/2 MB/);
    expect(classifyProfileError(api(422, { code: "NOT_TEACHER", message: "x" })).banner).toMatch(/không còn là giáo viên/);
    expect(classifyProfileError(new NetworkError(null), { hadFile: true }).banner).toMatch(/2 MB/);
  });
  it("409 phân biệt mã, thông điệp giới hạn trang chủ đúng contract", () => {
    expect(isConsentVersionChanged(api(409, { code: "CONSENT_VERSION_CHANGED" }))).toBe(true);
    expect(isHomepageLimit(api(409, { code: "TEACHER_HOMEPAGE_LIMIT" }))).toBe(true);
    expect(isHomepageLimit(api(409, { code: "OTHER" }))).toBe(false);
    expect(profileActionError(api(409, { code: "TEACHER_HOMEPAGE_LIMIT" }))).toBe("Trang chủ chỉ hiển thị tối đa 6 giáo viên. Hãy tắt bớt một người trước.");
    expect(homepageLimitMessage(3)).toContain("tối đa 3");
  });
});

describe("cắt ảnh vuông 1:1 (BR7)", () => {
  const view = 280;
  it("ảnh vuông ở zoom 1: lấy cả ảnh", () => {
    const r = cropRect({ width: 1000, height: 1000 }, view, 1, { x: 0, y: 0 });
    expect(r.sx).toBeCloseTo(0);
    expect(r.sy).toBeCloseTo(0);
    expect(r.size).toBeCloseTo(1000);
  });
  it("ảnh ngang: khung phủ kín chiều cao, căn giữa", () => {
    const nat = { width: 1600, height: 800 };
    expect(coverScale(nat, view)).toBeCloseTo(view / 800);
    const r = cropRect(nat, view, 1, { x: 0, y: 0 });
    expect(r.size).toBeCloseTo(800);
    expect(r.sx).toBeCloseTo(400);
    expect(r.sy).toBeCloseTo(0);
  });
  it("phóng to 2x thu vùng cắt còn một nửa; kéo sang phải thì vùng cắt dịch sang trái", () => {
    const nat = { width: 1000, height: 1000 };
    const center = cropRect(nat, view, 2, { x: 0, y: 0 });
    expect(center.size).toBeCloseTo(500);
    expect(center.sx).toBeCloseTo(250);
    const dragged = cropRect(nat, view, 2, { x: 100, y: 0 });
    expect(dragged.sx).toBeLessThan(center.sx);
  });
  it("giới hạn kéo để khung luôn nằm trong ảnh", () => {
    const nat = { width: 1000, height: 1000 };
    expect(clampPos({ x: 999, y: -999 }, nat, view, 1)).toEqual({ x: 0, y: 0 });
    const c = clampPos({ x: 999, y: -999 }, nat, view, 2);
    expect(c).toEqual({ x: 140, y: -140 });
    const r = cropRect(nat, view, 2, { x: 999, y: -999 });
    expect(r.sx).toBeGreaterThanOrEqual(0);
    expect(r.sy + r.size).toBeLessThanOrEqual(1000);
  });
  it("cạnh xuất ≤ 800 và không phóng to ảnh nhỏ", () => {
    expect(outputSide({ sx: 0, sy: 0, size: 3000 })).toBe(800);
    expect(outputSide({ sx: 0, sy: 0, size: 120.4 })).toBe(120);
  });
});

describe("menu theo vai trò (FA11)", () => {
  const labels = (role: "admin" | "quan_ly_trang" | "giao_vien") => navForUser({ role, permissions: null }).map((i) => i.label);
  it("admin/QLT thấy 'Giáo viên trang chủ', không thấy 'Hồ sơ của tôi'", () => {
    for (const role of ["admin", "quan_ly_trang"] as const) {
      expect(labels(role)).toContain("Giáo viên trang chủ");
      expect(labels(role)).not.toContain("Hồ sơ của tôi");
    }
  });
  it("giáo viên thấy 'Hồ sơ của tôi', không thấy menu quản lý", () => {
    expect(labels("giao_vien")).toContain("Hồ sơ của tôi");
    expect(labels("giao_vien")).not.toContain("Giáo viên trang chủ");
  });
});

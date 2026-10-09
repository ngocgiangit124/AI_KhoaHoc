import { describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { auditLoadError } from "./errors";
import { actionLabel, actorView, changeEntries, formatAuditTime, shortUserAgent, stringifyValue, subjectView, truncate } from "./labels";
import { addDays, auditQueryToApi, auditQueryToSearch, effectiveRange, hasAuditFilter, parseAuditQuery, rangeError } from "./query";
import { auditPageSchema } from "./schemas";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));

const parse = (s: string) => parseAuditQuery(new URLSearchParams(s));

describe("auditPageSchema", () => {
  const item = { id: 1, action: "order.refund", actor_id: 3, actor_role: "admin", actor_name: "A", subject_type: "App\\Models\\Order", subject_id: 9, changes: { total: 5 }, ip: "1.2.3.4", user_agent: "x", created_at: "2026-10-09T08:00:00+07:00" };
  it("nhận item đúng contract, changes rỗng {} và mảng", () => {
    expect(auditPageSchema.safeParse({ data: [item, { ...item, id: 2, changes: {} }, { ...item, id: 3, changes: [1] }], meta: { current_page: 1, per_page: 25 }, links: { next: "x", prev: null } }).success).toBe(true);
  });
  it("actor/subject null (lệnh hệ thống) hợp lệ; thiếu trường bắt buộc bị từ chối", () => {
    expect(auditPageSchema.safeParse({ data: [{ ...item, actor_id: null, actor_name: null, actor_role: "cli", subject_type: null, subject_id: null }] }).success).toBe(true);
    expect(auditPageSchema.safeParse({ data: [{ id: 1 }] }).success).toBe(false);
  });
});

describe("nhãn", () => {
  it("action quen có nhãn, action lạ trả null (hiện nguyên mã)", () => {
    expect(actionLabel("order.manual_approve")).toBe("Duyệt đơn");
    expect(actionLabel("order.manual_cancel")).toBe("Huỷ đơn");
    expect(actionLabel("order.refund")).toBe("Hoàn tiền");
    expect(actionLabel("order.note_add")).toBe("Thêm ghi chú đơn");
    expect(actionLabel("order.view_pii")).toBe("Xem thông tin cá nhân đơn");
    expect(actionLabel("order.search_contact")).toBe("Tra cứu email/SĐT");
    expect(actionLabel("foo.bar")).toBeNull();
  });
  it("người làm: cli = Lệnh hệ thống; có tên thì kèm vai trò; thiếu tên dùng mã", () => {
    expect(actorView({ actor_id: null, actor_role: "cli", actor_name: null })).toEqual({ name: "Lệnh hệ thống", role: null });
    expect(actorView({ actor_id: 3, actor_role: "quan_ly_trang", actor_name: "Bình" })).toEqual({ name: "Bình", role: "Quản lý trang" });
    expect(actorView({ actor_id: 7, actor_role: null, actor_name: null }).name).toBe("Tài khoản #7");
    expect(actorView({ actor_id: null, actor_role: null, actor_name: null }).name).toMatch(/Hệ thống/);
  });
  it("giờ VN dd/mm/yyyy HH:mm:ss", () => {
    expect(formatAuditTime("2026-10-09T01:02:03Z")).toBe("09/10/2026 08:02:03");
    expect(formatAuditTime("2026-10-09T08:02:03+07:00")).toBe("09/10/2026 08:02:03");
  });
  it("đối tượng + link: khóa học, mã giảm giá; đơn chỉ có link khi changes có code hợp lệ", () => {
    expect(subjectView({ subject_type: "App\\Models\\Course", subject_id: 12, changes: {} })).toEqual({ text: "Khóa học #12", href: "/quan-tri/khoa-hoc/12/sua" });
    expect(subjectView({ subject_type: "App\\Models\\Coupon", subject_id: 4, changes: null }).href).toBe("/quan-tri/ma-giam-gia/4");
    expect(subjectView({ subject_type: "App\\Models\\Order", subject_id: 5, changes: { total: 1 } }).href).toBeNull();
    expect(subjectView({ subject_type: "App\\Models\\Order", subject_id: 5, changes: { code: "VV123ABC" } }).href).toBe("/quan-tri/don-hang/VV123ABC");
    expect(subjectView({ subject_type: "App\\Models\\Order", subject_id: 5, changes: { code: "../x?y" } }).href).toBeNull();
    expect(subjectView({ subject_type: "App\\Models\\User", subject_id: 2, changes: {} })).toEqual({ text: "Tài khoản #2", href: null });
    expect(subjectView({ subject_type: "App\\Models\\Weird", subject_id: 1, changes: {} }).text).toBe("Weird #1");
    expect(subjectView({ subject_type: null, subject_id: null, changes: {} })).toEqual({ text: "—", href: null });
  });
  it("changes → khoá–giá trị text; HTML giữ nguyên chữ (không diễn giải)", () => {
    expect(changeEntries({})).toEqual([]);
    expect(changeEntries(null)).toEqual([]);
    expect(changeEntries({ status: { from: "pending", to: "paid" }, late: false, note: "<b>x</b>", n: 3, z: null })).toEqual([
      { key: "status", value: '{"from":"pending","to":"paid"}' },
      { key: "late", value: "không" },
      { key: "note", value: "<b>x</b>" },
      { key: "n", value: "3" },
      { key: "z", value: "—" },
    ]);
    expect(changeEntries([5])).toEqual([{ key: "0", value: "5" }]);
    expect(stringifyValue("")).toBe("(trống)");
  });
  it("cắt giá trị dài và rút gọn user agent", () => {
    expect(truncate("a".repeat(200), 160)).toEqual({ text: `${"a".repeat(160)}…`, truncated: true });
    expect(truncate("ngắn").truncated).toBe(false);
    expect(shortUserAgent("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36")).toBe("Chrome 141 · macOS");
    expect(shortUserAgent("Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Edg/120.0")).toBe("Edge 120 · Windows");
    expect(shortUserAgent(null)).toBe("—");
    expect(shortUserAgent("curl/8.0")).toBe("curl/8.0");
  });
});

describe("bộ lọc <-> query", () => {
  it("mặc định: 7 ngày gần nhất, 25 dòng, trang 1; API luôn có from/to", () => {
    const q = parse("");
    expect(effectiveRange(q, "2026-10-09")).toEqual({ from: "2026-10-03", to: "2026-10-09" });
    expect(auditQueryToSearch(q)).toBe("");
    expect(auditQueryToApi(q, "2026-10-09").toString()).toBe("from=2026-10-03&to=2026-10-09&per_page=25");
    expect(hasAuditFilter(q)).toBe(false);
  });
  it("đọc đủ bộ lọc, ghi lại URL và gửi API đúng tên tham số", () => {
    const q = parse("from=2026-10-01&to=2026-10-05&action=order.refund&actor_id=3&subject_type=App%5CModels%5COrder&subject_id=9&per_page=50&page=3");
    expect(q).toMatchObject({ from: "2026-10-01", to: "2026-10-05", action: "order.refund", actorId: "3", subjectType: "App\\Models\\Order", subjectId: "9", perPage: 50, page: 3 });
    expect(parse(auditQueryToSearch(q).slice(1))).toEqual(q);
    const api = auditQueryToApi(q, "2026-10-09");
    expect(Object.fromEntries(api)).toEqual({ from: "2026-10-01", to: "2026-10-05", action: "order.refund", actor_id: "3", subject_type: "App\\Models\\Order", subject_id: "9", per_page: "50", page: "3" });
  });
  it("giá trị lạ rơi về mặc định (không gửi tham số sai)", () => {
    expect(parse("from=2026-13-40&to=abc&actor_id=-1&subject_id=1.5&per_page=77&page=0&action=a%20b")).toMatchObject({ from: null, to: null, actorId: "", subjectId: "", perPage: 25, page: 1, action: "" });
    expect(parse(`action=${"a".repeat(80)}`).action).toHaveLength(60);
  });
  it("khoảng ngày ngược bị báo; addDays qua tháng", () => {
    expect(rangeError({ from: "2026-10-09", to: "2026-10-01" })).toMatch(/trước hoặc bằng/);
    expect(rangeError({ from: "2026-10-01", to: "2026-10-01" })).toBeNull();
    expect(addDays("2026-03-01", -1)).toBe("2026-02-28");
  });
});

describe("auditLoadError", () => {
  const err = (status: number, body: Record<string, unknown> = {}) => new ApiError(status, { message: "m", ...body });
  it("422 gộp lời server theo field; 429 nhắc thử lại; mặc định dùng message", () => {
    expect(auditLoadError(err(422, { errors: { to: ["Ngày kết thúc phải sau hoặc bằng ngày bắt đầu."] } }))).toBe("Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.");
    expect(auditLoadError(err(422))).toMatch(/Bộ lọc không hợp lệ/);
    expect(auditLoadError(err(429))).toMatch(/quá nhanh/);
    expect(auditLoadError(err(500, { message: "Lỗi máy chủ" }))).toBe("Lỗi máy chủ");
  });
});

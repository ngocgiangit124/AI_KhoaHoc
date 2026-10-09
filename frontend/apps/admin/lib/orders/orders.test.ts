import { describe, expect, it, vi } from "vitest";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { classifyActionError, conflictNotice, listErrorMessage } from "./errors";
import { detailFixture } from "./fixtures";
import { actorText, adminStatus, formatWhen, hoursLeft, logSentence, mailHref, remainingText, telHref, warningText } from "./format";
import {
  addDays,
  effectiveRange,
  looksLikeContactSearch,
  orderQueryToApi,
  orderQueryToSearch,
  parseOrderQuery,
  rangeError,
  todayVn,
} from "./query";
import { approveFormSchema, cancelFormSchema, firstErrors, noteFormSchema, orderDetailSchema, orderPageSchema, pendingCountSchema, refundFormSchema } from "./schemas";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));

const parse = (qs: string) => parseOrderQuery(new URLSearchParams(qs));

describe("query (bộ lọc trên URL)", () => {
  it("mặc định là tab Chờ duyệt; giá trị lạ rơi về mặc định", () => {
    expect(parse("")).toMatchObject({ tab: "cho-duyet", q: "", from: null, to: null, method: "", status: "", review: false, cursor: "", perPage: 25 });
    expect(parse("tab=xyz&method=paypal&status=boom&from=2026-13-40&per_page=7&cursor=%3Cscript%3E")).toMatchObject({
      tab: "cho-duyet",
      method: "",
      status: "",
      from: null,
      perPage: 25,
      cursor: "",
    });
  });

  it("tab Chờ duyệt gọi status[]=pending&payment_method=manual&sort=oldest, KHÔNG gửi khoảng ngày", () => {
    const p = orderQueryToApi(parse("q=Minh"), "2026-10-09");
    expect(p.getAll("status[]")).toEqual(["pending"]);
    expect(p.get("payment_method")).toBe("manual");
    expect(p.get("sort")).toBe("oldest");
    expect(p.has("from")).toBe(false);
    expect(p.has("to")).toBe(false);
    expect(p.get("q")).toBe("Minh");
  });

  it("tab khác: khoảng ngày mặc định 30 ngày, sort newest, lọc phương thức/Cần xem lại/cursor", () => {
    const p = orderQueryToApi(parse("tab=da-huy&method=manual&review=1&cursor=abc_-1&per_page=50"), "2026-10-09");
    expect(p.get("from")).toBe("2026-09-09");
    expect(p.get("to")).toBe("2026-10-09");
    expect(p.getAll("status[]")).toEqual(["cancelled", "failed"]);
    expect(p.get("payment_method")).toBe("manual");
    expect(p.get("needs_review")).toBe("1");
    expect(p.get("sort")).toBe("newest");
    expect(p.get("cursor")).toBe("abc_-1");
    expect(p.get("per_page")).toBe("50");
  });

  it("tab Tất cả: lọc theo trạng thái chọn; không chọn thì không gửi status[]", () => {
    expect(orderQueryToApi(parse("tab=tat-ca&status=paid"), "2026-10-09").getAll("status[]")).toEqual(["paid"]);
    expect(orderQueryToApi(parse("tab=tat-ca"), "2026-10-09").has("status[]")).toBe(false);
  });

  it("ghi lại URL bỏ tham số mặc định; tab Chờ duyệt không mang bộ lọc ngày", () => {
    expect(orderQueryToSearch(parse(""))).toBe("");
    expect(orderQueryToSearch(parse("tab=hoan-tien&from=2026-01-01&to=2026-02-01&review=1"))).toBe("?tab=hoan-tien&from=2026-01-01&to=2026-02-01&review=1");
    expect(orderQueryToSearch(parse("from=2026-01-01&q=An"))).toBe("?q=An");
  });

  it("khoảng ngày: ≤ 366 ngày chênh lệch, from ≤ to", () => {
    expect(rangeError({ from: "2026-01-01", to: "2027-01-02" })).toBeNull();
    expect(rangeError({ from: "2026-01-01", to: "2027-01-03" })).toMatch(/366/);
    expect(rangeError({ from: "2026-02-01", to: "2026-01-01" })).toMatch(/trước hoặc bằng/);
    expect(effectiveRange({ from: null, to: "2026-10-09" }, "2026-10-09")).toEqual({ from: "2026-09-09", to: "2026-10-09" });
    expect(addDays("2026-03-01", -1)).toBe("2026-02-28");
  });

  it("todayVn theo giờ Việt Nam (UTC 18:00 đã sang ngày mới)", () => {
    expect(todayVn(new Date("2026-10-08T18:00:00Z"))).toBe("2026-10-09");
  });

  it("nhận ra tìm theo email/SĐT (giới hạn 30/phút)", () => {
    expect(looksLikeContactSearch("a@b.vn")).toBe(true);
    expect(looksLikeContactSearch("0901234567")).toBe(true);
    expect(looksLikeContactSearch("VV261008K7M2QX")).toBe(false);
    expect(looksLikeContactSearch("Minh Anh")).toBe(false);
  });
});

describe("format", () => {
  const now = new Date("2026-10-09T10:00:00+07:00").getTime();
  it("hạn chờ: Còn N giờ / dưới 1 giờ / đã quá hạn", () => {
    expect(remainingText("2026-10-09T15:30:00+07:00", now)).toBe("Còn 5 giờ");
    expect(remainingText("2026-10-09T10:30:00+07:00", now)).toBe("Còn dưới 1 giờ");
    expect(remainingText("2026-10-09T09:59:00+07:00", now)).toBe("Đã quá hạn");
    expect(hoursLeft("2026-10-10T10:00:00+07:00", now)).toBe(24);
  });
  it("giờ Việt Nam HH:mm dd/mm/yyyy", () => {
    expect(formatWhen("2026-10-08T12:59:00Z")).toBe("19:59 08/10/2026");
  });
  it("nhãn trạng thái quản trị", () => {
    expect(adminStatus("pending", null)).toEqual({ label: "Chờ duyệt", tone: "warning" });
    expect(adminStatus("paid", "manual_confirmed", "manual").label).toBe("Đã duyệt");
    expect(adminStatus("cancelled", "expired").label).toBe("Tự huỷ (hết hạn)");
    expect(adminStatus("cancelled", "user_cancelled").label).toBe("HS tự huỷ");
    expect(adminStatus("cancelled", "admin_cancelled").label).toBe("Huỷ bởi QTV");
    expect(adminStatus("refunded", null).label).toBe("Đã hoàn tiền");
  });
  it("lịch sử: 'Đã duyệt bởi X lúc HH:mm dd/mm/yyyy'", () => {
    const log = { from: "pending", to: "paid", reason: "manual_confirmed", actor_type: "staff", actor: { id: 2, name: "Đỗ Thị Mai" }, meta: {}, created_at: "2026-10-08T12:59:00Z" };
    expect(logSentence(log)).toBe("Đã duyệt bởi Đỗ Thị Mai lúc 19:59 08/10/2026");
    expect(logSentence({ ...log, from: "cancelled", meta: { late: true } })).toBe("Đã duyệt muộn bởi Đỗ Thị Mai lúc 19:59 08/10/2026");
    expect(logSentence({ ...log, from: null, to: "pending", actor_type: "user", actor: { id: 5, name: "An" } })).toBe("Học sinh An đặt đơn lúc 19:59 08/10/2026");
    expect(logSentence({ ...log, to: "cancelled", reason: "expired", actor_type: "system", actor: null })).toBe("Tự huỷ do hết hạn chờ lúc 19:59 08/10/2026");
    expect(actorText({ actor_type: "system", actor: null })).toBe("Hệ thống");
  });
  it("tel:/mailto: luôn qua encodeURIComponent", () => {
    expect(telHref("0901 234-123")).toBe("tel:0901234123");
    expect(telHref("+84 90 1234 123")).toBe("tel:%2B84901234123");
    expect(mailHref("a+b@x.vn", "VV1")).toBe("mailto:a%2Bb%40x.vn?subject=VitaminVui%20%E2%80%94%20%C4%91%C6%A1n%20VV1");
    expect(mailHref("x@y.vn?cc=evil@z.vn", "VV1")).not.toContain("?cc=");
  });
  it("cảnh báo duyệt / duyệt muộn", () => {
    expect(warningText({ code: "COURSE_UNPUBLISHED", title: "Ngữ văn 9", coupon_code: null })).toMatch(/“Ngữ văn 9” đã ngừng bán/);
    expect(warningText({ code: "COUPON_OVER_LIMIT", title: null, coupon_code: "HE26" }, true)).toMatch(/HE26.*hết lượt/);
    expect(warningText({ code: "ALREADY_OWNED", title: "Toán 9", coupon_code: null }, true)).toMatch(/đã sở hữu khóa “Toán 9”/);
    expect(warningText({ code: "NEW_CODE", title: null, coupon_code: null })).toContain("NEW_CODE");
  });
});

describe("errors", () => {
  const api = (status: number, code: string | undefined, errors?: unknown, message = "Lỗi", retry?: number) =>
    new ApiError(status, { message, ...(code ? { code } : {}), ...(errors ? { errors: errors as Record<string, string[]> } : {}) }, retry);

  it("422: lỗi nằm dưới đúng ô (reason/note/payment_reference/body/confirm)", () => {
    const f = classifyActionError(api(422, "VALIDATION_ERROR", { reason: ["Lý do quá ngắn."], note: ["Ghi chú sai."], other: ["Lạ."] }));
    expect(f.kind).toBe("validation");
    expect(f.fields).toEqual({ reason: "Lý do quá ngắn.", note: "Ghi chú sai." });
    expect(f.message).toBe("Lạ.");
    expect(f.reload).toBe(false);
  });
  it("409 (mọi mã đơn) → reload; payload đọc từ errors dạng object", () => {
    const f = classifyActionError(api(409, "COURSE_UNAVAILABLE", { courses: [{ id: 15, title: "Ngữ văn 9" }] }));
    expect(f).toMatchObject({ kind: "conflict", reload: true, code: "COURSE_UNAVAILABLE" });
    expect(f.payload?.courses).toEqual([{ id: 15, title: "Ngữ văn 9" }]);
    expect(classifyActionError(api(409, "ALREADY_PROCESSED", { status: "paid", status_reason: "manual_confirmed" })).payload?.status).toBe("paid");
  });
  it("403/404/429/mạng", () => {
    expect(classifyActionError(api(403, "FORBIDDEN")).kind).toBe("forbidden");
    expect(classifyActionError(api(404, "NOT_FOUND")).kind).toBe("not_found");
    expect(classifyActionError(api(429, "TOO_MANY_ATTEMPTS", undefined, "x", 12))).toMatchObject({ kind: "throttled", message: expect.stringContaining("12 giây") });
    expect(classifyActionError(new NetworkError(new Error("x"))).kind).toBe("network");
    expect(listErrorMessage(api(429, "TOO_MANY_ATTEMPTS", undefined, "x", 30))).toMatch(/30 lần\/phút.*30 giây/);
    expect(listErrorMessage(api(422, "VALIDATION_ERROR", { cursor: ["x"] }))).toMatch(/quay lại trang đầu/);
  });

  const order = (over: Record<string, unknown>) => detailFixture(over);
  it("ALREADY_PROCESSED khi duyệt: nêu ai đã duyệt, lúc nào", () => {
    const f = classifyActionError(api(409, "ALREADY_PROCESSED", { status: "paid" }));
    const n = conflictNotice("approve", f, order({ status: "paid", status_reason: "manual_confirmed", confirmed_by: { id: 2, name: "Đỗ Thị Mai" }, paid_at: "2026-10-08T12:59:00Z", approval: { can_cancel: false } }));
    expect(n.title).toBe("Đơn đã được người khác xử lý");
    expect(n.body).toContain("Đỗ Thị Mai đã duyệt lúc 19:59 08/10/2026");
  });
  it("ORDER_STATUS_CHANGED khi duyệt mà học sinh vừa huỷ: gợi ý Duyệt muộn khi can_approve_late", () => {
    const f = classifyActionError(api(409, "ORDER_STATUS_CHANGED", { status: "cancelled", status_reason: "user_cancelled", can_approve_late: true }));
    const o = order({
      status: "cancelled",
      status_reason: "user_cancelled",
      status_logs: [{ from: "pending", to: "cancelled", reason: "user_cancelled", actor_type: "user", actor: { id: 501, name: "Nguyễn Văn An" }, meta: {}, created_at: "2026-10-08T12:59:00Z" }],
      approval: { can_approve: false, can_approve_late: true, can_cancel: false },
    });
    const n = conflictNotice("approve", f, o);
    expect(n.title).toBe("Chưa duyệt: đơn vừa đổi trạng thái");
    expect(n.body).toContain("Học sinh đã tự huỷ đơn lúc 19:59 08/10/2026");
    expect(n.body).toContain("Duyệt muộn");
    const noLate = conflictNotice("approve", f, order({ ...o, status: "cancelled", approval: { can_approve_late: false } }));
    expect(noLate.body).not.toContain("dùng “Duyệt muộn”");
  });
  it("COURSE_UNAVAILABLE liệt kê khóa; WINDOW_PASSED nêu hạn; không tải lại được thì câu chung", () => {
    const cu = classifyActionError(api(409, "COURSE_UNAVAILABLE", { courses: [{ id: 15, title: "Ngữ văn 9" }] }));
    const n = conflictNotice("late", cu, null);
    expect(n.tone).toBe("danger");
    expect(n.body).toContain("“Ngữ văn 9”");
    const wp = classifyActionError(api(409, "ORDER_APPROVAL_WINDOW_PASSED", { approval_window_until: "2026-10-01T12:00:00Z" }, ""));
    expect(conflictNotice("late", wp, null).body).toContain("hạn 01/10/2026");
    expect(conflictNotice("approve", classifyActionError(api(409, "ALREADY_PROCESSED")), null).title).toBe("Đơn vừa đổi trạng thái");
  });
  it("huỷ nhưng đơn vừa được duyệt; hoàn tiền khi chưa paid", () => {
    const paid = order({ status: "paid", confirmed_by: { id: 2, name: "Mai" }, paid_at: "2026-10-08T12:59:00Z" });
    expect(conflictNotice("cancel", classifyActionError(api(409, "ORDER_STATUS_CHANGED")), paid).title).toBe("Không huỷ được: đơn vừa được duyệt");
    expect(conflictNotice("refund", classifyActionError(api(409, "ORDER_STATUS_CHANGED")), order({})).title).toBe("Không hoàn tiền được");
  });
});

describe("schemas", () => {
  it("duyệt: phải tick đã nhận đủ; mã giao dịch ≤ 100, ghi chú ≤ 1000", () => {
    expect(approveFormSchema.safeParse({ received: false, payment_reference: "", note: "" }).success).toBe(false);
    expect(approveFormSchema.safeParse({ received: true, payment_reference: "x".repeat(101), note: "" }).success).toBe(false);
    expect(approveFormSchema.safeParse({ received: true, payment_reference: " FT1 ", note: "" })).toMatchObject({ success: true, data: { payment_reference: "FT1" } });
  });
  it("huỷ: lý do 5–500 sau trim, tách khỏi ghi chú nội bộ", () => {
    expect(firstErrors(cancelFormSchema.safeParse({ reason: "  abc ", note: "" }).error!)).toEqual({ reason: "Nhập lý do cho học sinh, ít nhất 5 ký tự." });
    expect(cancelFormSchema.safeParse({ reason: "x".repeat(501), note: "" }).success).toBe(false);
    expect(cancelFormSchema.safeParse({ reason: "Không liên lạc được", note: "gọi 3 lần" }).success).toBe(true);
  });
  it("ghi chú 1–1000; hoàn tiền phải tick", () => {
    expect(noteFormSchema.safeParse({ body: "   " }).success).toBe(false);
    expect(noteFormSchema.safeParse({ body: "x".repeat(1001) }).success).toBe(false);
    expect(refundFormSchema.safeParse({ confirmed: false, note: "" }).success).toBe(false);
  });
  it("parse danh sách / chi tiết / pending-count theo hợp đồng; điền mặc định", () => {
    const page = orderPageSchema.parse({
      data: [
        {
          code: "VV1",
          status: "pending",
          payment_method: "manual",
          items_count: 2,
          first_item_title: "Toán 9",
          subtotal: 5,
          discount: 0,
          total: 5,
          created_at: "2026-10-08T10:15:00+07:00",
          expires_at: "2026-10-11T10:15:00+07:00",
          expiring_soon: true,
          student: { id: 1, name: "An", email_masked: "n***@gmail.com", phone_masked: null, is_deleted: false },
        },
      ],
      meta: { per_page: 25, next_cursor: "eyJ", prev_cursor: null, total: 7 },
    });
    expect(page.data[0]).toMatchObject({ needs_review: false, confirmed_by: null, status_reason: null, expiring_soon: true });
    expect(page.meta).toMatchObject({ next_cursor: "eyJ", prev_cursor: null, total: 7 });
    expect(pendingCountSchema.parse({ pending_manual: 7, expiring_soon: 2 })).toEqual({ pending_manual: 7, expiring_soon: 2 });
    expect(orderDetailSchema.safeParse({ code: "x" }).success).toBe(false);
    expect(detailFixture().approval.warnings[0]).toMatchObject({ code: "COURSE_UNPUBLISHED", course_id: 15, coupon_code: null });
  });
});

describe("api: chi tiết đơn", () => {
  it("gộp các lời gọi trùng đang bay cho cùng mã (StrictMode) → 1 audit view_pii", async () => {
    vi.resetModules();
    const authFetch = vi.fn(async () => {
      await new Promise((r) => setTimeout(r, 5));
      return detailFixture();
    });
    vi.doMock("@/lib/api", () => ({ authFetch }));
    const { getOrder } = await import("./api");
    const [a, b] = await Promise.all([getOrder("VV261008K7M2QX"), getOrder("VV261008K7M2QX")]);
    expect(authFetch).toHaveBeenCalledTimes(1);
    expect(a.code).toBe(b.code);
    await getOrder("VV261008K7M2QX");
    expect(authFetch).toHaveBeenCalledTimes(2); // sau khi xong thì không cache
    vi.doUnmock("@/lib/api");
  });
  it("đơn sai hợp đồng → ContractError, không render undefined", async () => {
    vi.resetModules();
    vi.doMock("@/lib/api", () => ({ authFetch: vi.fn(async () => ({ code: "x" })) }));
    const { getOrder, ContractError } = await import("./api");
    await expect(getOrder("VV1")).rejects.toBeInstanceOf(ContractError);
    vi.doUnmock("@/lib/api");
  });
});

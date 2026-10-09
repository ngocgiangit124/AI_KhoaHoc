import { describe, expect, it } from "vitest";
import { cart, listItem, order, preview } from "./fixtures";
import { cartSchema, checkoutPreviewSchema, checkoutResultSchema, orderDetailSchema, ordersPageSchema, paymentConfigSchema } from "./schemas";

describe("schema theo api-contract §2.1/§2.3/§2.3.1", () => {
  it("giỏ, preview (khoá mới payment_methods/default/pending_order), đơn parse được", () => {
    expect(cartSchema.parse(cart()).items).toHaveLength(2);
    const p = checkoutPreviewSchema.parse(
      preview({ pending_order: { code: "VV261008K7M2QX", payment_method: "manual", total: 500000, created_at: "2026-10-08T10:15:00+07:00", expires_at: "2026-10-11T10:15:00+07:00" } }),
    );
    expect(p.payment_methods[0]?.code).toBe("manual");
    expect(p.pending_order?.code).toBe("VV261008K7M2QX");
  });

  it("preview cũ (thiếu khoá US-022) vẫn parse, payment_methods rỗng", () => {
    const old = { items: [], coupon: null, pricing: { subtotal: 0, discount: 0, total: 0 }, notices: [], can_checkout: false, requires_payment: false };
    expect(checkoutPreviewSchema.parse(old).payment_methods).toEqual([]);
  });

  it("response POST /checkout 201/200", () => {
    const r = checkoutResultSchema.parse({ order_code: "VV1", status: "pending", payment_method: "manual", total: 500000, expires_at: "2026-10-11T10:15:00+07:00", reused: false, payment: null, link_expired: false });
    expect(r.order_code).toBe("VV1");
  });

  it("GET /orders (phân trang) và GET /orders/{code} (có cancel_reason, replaced_by_code, coupon_code, slug null)", () => {
    const page = ordersPageSchema.parse({ data: [listItem()], meta: { current_page: 1, per_page: 10, total: 1, last_page: 1 }, links: { next: null, prev: null } });
    expect(page.data[0]?.can_cancel).toBe(true);
    const detail = orderDetailSchema.parse(
      order({ status: "cancelled", status_reason: "admin_cancelled", cancel_reason: "Không liên hệ được", can_cancel: false, items: [{ course_id: 1, title: "T", slug: null, grade_level: null, unit_price: 1, discount_amount: 0, final_amount: 1 }] }),
    );
    expect(detail.cancel_reason).toBe("Không liên hệ được");
    expect(detail.items[0]?.slug).toBeNull();
  });

  it("sai contract (thiếu total) làm parse lỗi để lộ ra ngay", () => {
    expect(() => orderDetailSchema.parse({ code: "x" })).toThrow();
  });

  it("/config/public: kênh null giữ nguyên; thiếu khoá -> tắt", () => {
    const c = paymentConfigSchema.parse({
      paid_checkout_enabled: true,
      payment_methods: ["manual"],
      manual_payment: { label: "Liên hệ Quản trị viên", description: "d", pending_ttl_hours: 72, contact: { phone: null, zalo_url: null, email: "hotro@vitaminvui.vn", hours: null } },
    });
    expect(c.manual_payment?.contact.phone).toBeNull();
    expect(paymentConfigSchema.parse({})).toEqual({ paid_checkout_enabled: false, payment_methods: [], manual_payment: null });
  });
});

describe("cartSchema.pending_order (T16-1)", () => {
  it("optional+nullable: thiếu, null và đủ shape đều parse; sai shape bị từ chối", () => {
    expect(cartSchema.parse(cart()).pending_order).toBeUndefined();
    expect(cartSchema.parse({ ...cart(), pending_order: null }).pending_order).toBeNull();
    const po = { code: "VV261008K7M2QX", payment_method: "manual", total: 1, created_at: "2026-10-08T10:15:00+07:00", expires_at: null };
    expect(cartSchema.parse({ ...cart(), pending_order: po }).pending_order?.code).toBe("VV261008K7M2QX");
    expect(() => cartSchema.parse({ ...cart(), pending_order: { code: 1 } })).toThrow();
  });
});

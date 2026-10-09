import { orderDetailSchema, type OrderDetail } from "./schemas";

/** Chi tiết đơn mẫu cho test (theo hợp đồng §2.5.1), đã qua zod để có đủ giá trị mặc định. */
export function detailFixture(over: Record<string, unknown> = {}): OrderDetail {
  const base = {
    code: "VV261008K7M2QX",
    status: "pending",
    status_reason: null,
    payment_method: "manual",
    needs_review: false,
    needs_review_reasons: [],
    subtotal: 550000,
    discount: 50000,
    total: 500000,
    coupon_code: "HE2026",
    payment_reference: null,
    customer_note: "Gọi sau 18h giúp em",
    cancel_reason: null,
    created_at: "2026-10-08T10:15:00+07:00",
    expires_at: "2026-10-11T10:15:00+07:00",
    paid_at: null,
    cancelled_at: null,
    refunded_at: null,
    refund_note: null,
    confirmed_by: null,
    refunded_by: null,
    student: { id: 501, name: "Nguyễn Văn An", email: "nguyenvanan@gmail.com", email_verified: true, phone: "0901234123", phone_verified: false, account_status: "active", is_deleted: false },
    items: [
      { course_id: 12, title: "Toán 9 nâng cao", unit_price: 300000, discount_amount: 30000, final_amount: 270000, course_status: "published", current_price: 300000 },
      { course_id: 15, title: "Ngữ văn 9", unit_price: 250000, discount_amount: 20000, final_amount: 230000, course_status: "unpublished", current_price: 250000 },
    ],
    status_logs: [{ from: null, to: "pending", reason: null, actor_type: "user", actor: { id: 501, name: "Nguyễn Văn An" }, meta: { items: 2 }, created_at: "2026-10-08T10:15:00+07:00" }],
    notes: [{ id: 31, body: "Đã gọi 9h, hẹn chuyển khoản chiều nay", author: { id: 3, name: "Trần Thị Bình" }, created_at: "2026-10-08T09:05:00+07:00" }],
    attempts: [],
    approval: {
      can_approve: true,
      can_approve_late: false,
      can_cancel: true,
      approval_window_until: null,
      warnings: [{ code: "COURSE_UNPUBLISHED", course_id: 15, title: "Ngữ văn 9" }],
      late_approval_warnings: [],
    },
  };
  return orderDetailSchema.parse({ ...base, ...over });
}

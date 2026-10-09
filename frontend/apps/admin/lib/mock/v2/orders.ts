/**
 * Dữ liệu mẫu quản trị đơn hàng (US-010 + US-022, FA8). Tên trường: `GET /admin/orders` (api-contract §2.5, T24)
 * + phần ĐỀ XUẤT của US-022 (story mục "API"): `payment_method`, `customer_note`, `notes[]`, `approval_window_until`,
 * `needs_review`/`needs_review_reasons`, `confirmed_by`, `course_status` của từng dòng. Architect chưa chốt — nextjs-dev đối chiếu lại.
 * Email/SĐT ở danh sách luôn ĐÃ CHE (S14); chi tiết trả đầy đủ (audit `order.view_pii`). Không bao giờ có thông tin phụ huynh.
 */

/** "Bây giờ" cố định của bản xem trước (chữ "còn N giờ" không đổi giữa server/client). */
export const PREVIEW_NOW = "2026-10-08T20:00:00+07:00";

export type AdminOrderStatus = "pending" | "paid" | "cancelled" | "failed" | "refunded";
export type AdminOrderReason =
  | null
  | "manual_confirmed"
  | "zero_amount"
  | "user_cancelled"
  | "admin_cancelled"
  | "expired"
  | "superseded"
  | "account_deleted";
export type ReviewReason = "late_payment" | "already_owned" | "coupon_over_limit" | "course_deleted";

export interface AdminOrderListItem {
  code: string;
  status: AdminOrderStatus;
  status_reason: AdminOrderReason;
  payment_method: "manual" | "momo" | "none";
  needs_review: boolean;
  student: { id: number; name: string; email_masked: string | null; phone_masked: string | null };
  items_count: number;
  first_item_title: string;
  total: number;
  discount: number;
  created_at: string;
  expires_at: string | null;
}

export interface AdminOrderItem {
  course_id: number;
  title: string;
  grade_level: number;
  price: number;
  discount_amount: number;
  final_amount: number;
  /** Trạng thái khóa HIỆN TẠI (cảnh báo AC23/AC24). */
  course_status: "published" | "unpublished" | "deleted";
}

export interface StatusLog {
  from: AdminOrderStatus | null;
  to: AdminOrderStatus;
  reason: AdminOrderReason;
  actor_type: "user" | "staff" | "system";
  actor_name: string | null;
  created_at: string;
  /** Lý do gửi học sinh (huỷ bởi QTV), hoặc ghi chú kèm thao tác. */
  detail?: string | null;
}

export interface InternalNote {
  id: number;
  author: { id: number; name: string };
  body: string;
  created_at: string;
}

export interface AdminOrderDetail extends AdminOrderListItem {
  student_detail: {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    phone_verified: boolean;
    grade_level: number | null;
    account_status: "active" | "locked" | "deleted";
  };
  items: AdminOrderItem[];
  coupon: { code: string; discount_amount: number } | null;
  pricing: { subtotal: number; discount: number; total: number };
  customer_note: string | null;
  payment_reference: string | null;
  confirmed_by: { id: number; name: string } | null;
  paid_at: string | null;
  cancelled_at: string | null;
  cancel_reason_public: string | null;
  /** Hạn cuối được "Duyệt muộn" (cancelled_at + 30 ngày); null khi không áp dụng. */
  approval_window_until: string | null;
  needs_review_reasons: ReviewReason[];
  status_logs: StatusLog[];
  notes: InternalNote[];
}

const MAI = { id: 2, name: "Đỗ Thị Mai" };
const HUNG = { id: 1, name: "Lê Văn Hùng" };

const c101 = { course_id: 101, title: "Hình học 9: Đường tròn từ cơ bản đến nâng cao", grade_level: 9, price: 399000 };
const c102 = { course_id: 102, title: "Phương trình bậc hai và hệ thức Vi-ét", grade_level: 9, price: 349000 };
const c103 = { course_id: 103, title: "Ôn thi vào lớp 10: 30 đề chọn lọc có chữa chi tiết", grade_level: 9, price: 599000 };
const c108 = { course_id: 108, title: "Hình học 8: Tứ giác và định lý Ta-lét", grade_level: 8, price: 299000 };
const c111 = { course_id: 111, title: "Đạo hàm và ứng dụng — Toán 12", grade_level: 12, price: 549000 };
const c112 = { course_id: 112, title: "Xác suất – thống kê ôn thi tốt nghiệp THPT", grade_level: 12, price: 449000 };

const line = (c: typeof c101, discount = 0, status: AdminOrderItem["course_status"] = "published"): AdminOrderItem => ({
  ...c, discount_amount: discount, final_amount: c.price - discount, course_status: status,
});

function detail(d: Omit<AdminOrderDetail, "items_count" | "first_item_title" | "total" | "discount" | "student">): AdminOrderDetail {
  const s = d.student_detail;
  return {
    ...d,
    items_count: d.items.length,
    first_item_title: d.items[0]?.title ?? "",
    total: d.pricing.total,
    discount: d.pricing.discount,
    student: { id: s.id, name: s.name, email_masked: s.email ? maskEmail(s.email) : null, phone_masked: s.phone ? maskPhone(s.phone) : null },
  };
}

export function maskEmail(email: string): string {
  const at = email.lastIndexOf("@");
  return `${email.slice(0, Math.min(2, at))}***${email.slice(at)}`;
}
export function maskPhone(phone: string): string {
  const d = phone.replace(/\D/g, "");
  return `${d.slice(0, 2)}****${d.slice(-3)}`;
}

const pendingBase = { status: "pending" as const, status_reason: null, payment_method: "manual" as const, needs_review: false, payment_reference: null, confirmed_by: null, paid_at: null, cancelled_at: null, cancel_reason_public: null, approval_window_until: null, needs_review_reasons: [] };

export const ORDERS: AdminOrderDetail[] = [
  detail({
    ...pendingBase,
    code: "VV261005H2KD8N",
    student_detail: { id: 5012, name: "Trần Gia Bảo", email: "giabao.tran08@gmail.com", phone: "0938221470", phone_verified: true, grade_level: 12, account_status: "active" },
    items: [line(c111), line(c112, 0, "unpublished")],
    coupon: null,
    pricing: { subtotal: 998000, discount: 0, total: 998000 },
    customer_note: "Em chuyển khoản bằng tài khoản của bố (Trần Văn Nam).",
    created_at: "2026-10-05T22:15:00+07:00",
    expires_at: "2026-10-08T22:15:00+07:00",
    status_logs: [{ from: null, to: "pending", reason: null, actor_type: "user", actor_name: "Trần Gia Bảo", created_at: "2026-10-05T22:15:00+07:00" }],
    notes: [
      { id: 31, author: MAI, body: "Đã gọi 9h sáng 6/10, HS hẹn tối nay bố chuyển khoản.", created_at: "2026-10-06T09:05:00+07:00" },
      { id: 34, author: HUNG, body: "Chưa thấy tiền về tài khoản tới 17h ngày 8/10. Nhắn Zalo nhắc lần 2.", created_at: "2026-10-08T17:10:00+07:00" },
    ],
  }),
  detail({
    ...pendingBase,
    code: "VV261006P9MM3C",
    student_detail: { id: 4870, name: "Phạm Khánh Linh", email: "khanhlinh.pham@gmail.com", phone: null, phone_verified: false, grade_level: 8, account_status: "locked" },
    items: [line(c108)],
    coupon: null,
    pricing: { subtotal: 299000, discount: 0, total: 299000 },
    customer_note: null,
    created_at: "2026-10-06T08:30:00+07:00",
    expires_at: "2026-10-09T08:30:00+07:00",
    status_logs: [{ from: null, to: "pending", reason: null, actor_type: "user", actor_name: "Phạm Khánh Linh", created_at: "2026-10-06T08:30:00+07:00" }],
    notes: [],
  }),
  detail({
    ...pendingBase,
    code: "VV2610077K3QPM",
    student_detail: { id: 5230, name: "Nguyễn Minh Anh", email: "minhanh.2011@gmail.com", phone: "0912345678", phone_verified: false, grade_level: 9, account_status: "active" },
    items: [line(c102), line(c101, 50000)],
    coupon: { code: "VITAMIN50", discount_amount: 50000 },
    pricing: { subtotal: 748000, discount: 50000, total: 698000 },
    customer_note: "Gọi cho em sau 18h ạ. Zalo của mẹ em: 0987 654 321.",
    created_at: "2026-10-07T19:42:00+07:00",
    expires_at: "2026-10-10T19:42:00+07:00",
    status_logs: [{ from: null, to: "pending", reason: null, actor_type: "user", actor_name: "Nguyễn Minh Anh", created_at: "2026-10-07T19:42:00+07:00" }],
    notes: [{ id: 40, author: MAI, body: "Đã nhắn Zalo cho mẹ HS lúc 18h05, chờ phản hồi.", created_at: "2026-10-08T18:05:00+07:00" }],
  }),
  ...[
    { code: "VV2610073R8XNE", id: 5301, name: "Lê Hoàng Nam", email: "hoangnam.le@gmail.com", phone: "0977001122", c: c103, at: "2026-10-07T21:10:00+07:00" },
    { code: "VV261008A6WQ2T", id: 5322, name: "Vũ Thảo Vy", email: "thaovy.vu@gmail.com", phone: "0905667788", c: c101, at: "2026-10-08T07:55:00+07:00" },
    { code: "VV261008K1BC7S", id: 5340, name: "Hoàng Đức Minh", email: "ducminh.hd@gmail.com", phone: "0989334455", c: c112, at: "2026-10-08T15:20:00+07:00" },
  ].map((o) =>
    detail({
      ...pendingBase,
      code: o.code,
      student_detail: { id: o.id, name: o.name, email: o.email, phone: o.phone, phone_verified: true, grade_level: 9, account_status: "active" },
      items: [line(o.c)],
      coupon: null,
      pricing: { subtotal: o.c.price, discount: 0, total: o.c.price },
      customer_note: null,
      created_at: o.at,
      expires_at: new Date(new Date(o.at).getTime() + 72 * 3_600_000).toISOString(),
      status_logs: [{ from: null, to: "pending", reason: null, actor_type: "user", actor_name: o.name, created_at: o.at }],
      notes: [],
    }),
  ),
  detail({
    code: "VV2610023M8RTA",
    status: "paid",
    status_reason: "manual_confirmed",
    payment_method: "manual",
    needs_review: false,
    student_detail: { id: 5230, name: "Nguyễn Minh Anh", email: "minhanh.2011@gmail.com", phone: "0912345678", phone_verified: false, grade_level: 9, account_status: "active" },
    items: [line(c103)],
    coupon: null,
    pricing: { subtotal: 599000, discount: 0, total: 599000 },
    customer_note: null,
    payment_reference: "FT26276123456 VV2610023M8RTA",
    confirmed_by: HUNG,
    paid_at: "2026-10-03T10:05:00+07:00",
    cancelled_at: null,
    cancel_reason_public: null,
    approval_window_until: null,
    needs_review_reasons: [],
    created_at: "2026-10-02T09:15:00+07:00",
    expires_at: "2026-10-05T09:15:00+07:00",
    status_logs: [
      { from: null, to: "pending", reason: null, actor_type: "user", actor_name: "Nguyễn Minh Anh", created_at: "2026-10-02T09:15:00+07:00" },
      { from: "pending", to: "paid", reason: "manual_confirmed", actor_type: "staff", actor_name: HUNG.name, created_at: "2026-10-03T10:05:00+07:00", detail: "Mã giao dịch: FT26276123456 VV2610023M8RTA" },
    ],
    notes: [{ id: 22, author: HUNG, body: "Đã đối chiếu sao kê Vietcombank, đủ 599.000đ.", created_at: "2026-10-03T10:04:00+07:00" }],
  }),
  detail({
    code: "VV260920B7HX2K",
    status: "cancelled",
    status_reason: "expired",
    payment_method: "manual",
    needs_review: false,
    student_detail: { id: 5230, name: "Nguyễn Minh Anh", email: "minhanh.2011@gmail.com", phone: "0912345678", phone_verified: false, grade_level: 9, account_status: "active" },
    items: [line(c102)],
    coupon: null,
    pricing: { subtotal: 349000, discount: 0, total: 349000 },
    customer_note: null,
    payment_reference: null,
    confirmed_by: null,
    paid_at: null,
    cancelled_at: "2026-09-23T08:45:00+07:00",
    cancel_reason_public: null,
    approval_window_until: "2026-10-23T08:45:00+07:00",
    needs_review_reasons: [],
    created_at: "2026-09-20T08:40:00+07:00",
    expires_at: "2026-09-23T08:40:00+07:00",
    status_logs: [
      { from: null, to: "pending", reason: null, actor_type: "user", actor_name: "Nguyễn Minh Anh", created_at: "2026-09-20T08:40:00+07:00" },
      { from: "pending", to: "cancelled", reason: "expired", actor_type: "system", actor_name: null, created_at: "2026-09-23T08:45:00+07:00" },
    ],
    notes: [],
  }),
  detail({
    code: "VV260925Q1ZD4H",
    status: "cancelled",
    status_reason: "admin_cancelled",
    payment_method: "manual",
    needs_review: false,
    student_detail: { id: 5230, name: "Nguyễn Minh Anh", email: "minhanh.2011@gmail.com", phone: "0912345678", phone_verified: false, grade_level: 9, account_status: "active" },
    items: [{ course_id: 113, title: "Bồi dưỡng học sinh giỏi Toán 9", grade_level: 9, price: 899000, discount_amount: 0, final_amount: 899000, course_status: "published" }],
    coupon: null,
    pricing: { subtotal: 899000, discount: 0, total: 899000 },
    customer_note: null,
    payment_reference: null,
    confirmed_by: null,
    paid_at: null,
    cancelled_at: "2026-09-28T16:30:00+07:00",
    cancel_reason_public: "Quản trị viên đã gọi điện và nhắn Zalo 3 lần nhưng chưa liên lạc được với bạn. Khi sẵn sàng, bạn có thể đặt lại đơn từ giỏ hàng.",
    approval_window_until: "2026-10-28T16:30:00+07:00",
    needs_review_reasons: [],
    created_at: "2026-09-25T21:03:00+07:00",
    expires_at: "2026-09-28T21:03:00+07:00",
    status_logs: [
      { from: null, to: "pending", reason: null, actor_type: "user", actor_name: "Nguyễn Minh Anh", created_at: "2026-09-25T21:03:00+07:00" },
      { from: "pending", to: "cancelled", reason: "admin_cancelled", actor_type: "staff", actor_name: MAI.name, created_at: "2026-09-28T16:30:00+07:00", detail: "Không liên lạc được qua SĐT và Zalo." },
    ],
    notes: [{ id: 18, author: MAI, body: "Gọi 3 lần (26, 27, 28/9) không nghe máy, Zalo chưa đọc.", created_at: "2026-09-28T16:28:00+07:00" }],
  }),
  detail({
    code: "VV260825M3TQ9D",
    status: "cancelled",
    status_reason: "user_cancelled",
    payment_method: "manual",
    needs_review: false,
    student_detail: { id: 4120, name: "Đặng Quốc Huy", email: "quochuy.dang@gmail.com", phone: "0966112233", phone_verified: true, grade_level: 9, account_status: "active" },
    items: [line(c101)],
    coupon: null,
    pricing: { subtotal: 399000, discount: 0, total: 399000 },
    customer_note: null,
    payment_reference: null,
    confirmed_by: null,
    paid_at: null,
    cancelled_at: "2026-08-26T10:00:00+07:00",
    cancel_reason_public: null,
    approval_window_until: "2026-09-25T10:00:00+07:00",
    needs_review_reasons: [],
    created_at: "2026-08-25T19:00:00+07:00",
    expires_at: "2026-08-28T19:00:00+07:00",
    status_logs: [
      { from: null, to: "pending", reason: null, actor_type: "user", actor_name: "Đặng Quốc Huy", created_at: "2026-08-25T19:00:00+07:00" },
      { from: "pending", to: "cancelled", reason: "user_cancelled", actor_type: "user", actor_name: "Đặng Quốc Huy", created_at: "2026-08-26T10:00:00+07:00" },
    ],
    notes: [],
  }),
  detail({
    code: "VV260916T2WQ6F",
    status: "paid",
    status_reason: "manual_confirmed",
    payment_method: "manual",
    needs_review: true,
    student_detail: { id: 4555, name: "Bùi Ngọc Hân", email: "ngochan.bui@gmail.com", phone: "0944556677", phone_verified: true, grade_level: 9, account_status: "active" },
    items: [line(c101), line(c103)],
    coupon: null,
    pricing: { subtotal: 998000, discount: 0, total: 998000 },
    customer_note: null,
    payment_reference: "MBVCB.8812345 VV260916T2WQ6F",
    confirmed_by: MAI,
    paid_at: "2026-09-21T09:30:00+07:00",
    cancelled_at: null,
    cancel_reason_public: null,
    approval_window_until: null,
    needs_review_reasons: ["late_payment", "already_owned"],
    created_at: "2026-09-16T20:00:00+07:00",
    expires_at: "2026-09-19T20:00:00+07:00",
    status_logs: [
      { from: null, to: "pending", reason: null, actor_type: "user", actor_name: "Bùi Ngọc Hân", created_at: "2026-09-16T20:00:00+07:00" },
      { from: "pending", to: "cancelled", reason: "expired", actor_type: "system", actor_name: null, created_at: "2026-09-19T20:05:00+07:00" },
      { from: "cancelled", to: "paid", reason: "manual_confirmed", actor_type: "staff", actor_name: MAI.name, created_at: "2026-09-21T09:30:00+07:00", detail: "Duyệt muộn. Mã giao dịch: MBVCB.8812345 VV260916T2WQ6F" },
    ],
    notes: [{ id: 15, author: MAI, body: "HS chuyển khoản 20/9 (sau khi đơn hết hạn). Khóa Hình học 9 HS đã có từ đơn khác → cần hoàn 399.000đ ngoài hệ thống.", created_at: "2026-09-21T09:28:00+07:00" }],
  }),
  detail({
    code: "VV260830F5PB7N",
    status: "refunded",
    status_reason: null,
    payment_method: "manual",
    needs_review: false,
    student_detail: { id: 5230, name: "Nguyễn Minh Anh", email: "minhanh.2011@gmail.com", phone: "0912345678", phone_verified: false, grade_level: 9, account_status: "active" },
    items: [{ course_id: 110, title: "Lượng giác 11: công thức và phương trình", grade_level: 11, price: 449000, discount_amount: 0, final_amount: 449000, course_status: "published" }],
    coupon: null,
    pricing: { subtotal: 449000, discount: 0, total: 449000 },
    customer_note: null,
    payment_reference: "FT26243001122",
    confirmed_by: HUNG,
    paid_at: "2026-08-30T15:00:00+07:00",
    cancelled_at: null,
    cancel_reason_public: null,
    approval_window_until: null,
    needs_review_reasons: [],
    created_at: "2026-08-30T10:00:00+07:00",
    expires_at: "2026-09-02T10:00:00+07:00",
    status_logs: [
      { from: null, to: "pending", reason: null, actor_type: "user", actor_name: "Nguyễn Minh Anh", created_at: "2026-08-30T10:00:00+07:00" },
      { from: "pending", to: "paid", reason: "manual_confirmed", actor_type: "staff", actor_name: HUNG.name, created_at: "2026-08-30T15:00:00+07:00" },
      { from: "paid", to: "refunded", reason: null, actor_type: "staff", actor_name: HUNG.name, created_at: "2026-09-05T09:00:00+07:00", detail: "HS đăng ký nhầm lớp, đã hoàn tiền qua chuyển khoản." },
    ],
    notes: [],
  }),
  detail({
    code: "VV260710X8NV4R",
    status: "cancelled",
    status_reason: "account_deleted",
    payment_method: "manual",
    needs_review: false,
    student_detail: { id: 3001, name: "Tài khoản đã xoá", email: null, phone: null, phone_verified: false, grade_level: null, account_status: "deleted" },
    items: [line(c102)],
    coupon: null,
    pricing: { subtotal: 349000, discount: 0, total: 349000 },
    customer_note: null,
    payment_reference: null,
    confirmed_by: null,
    paid_at: null,
    cancelled_at: "2026-07-12T03:00:00+07:00",
    cancel_reason_public: null,
    approval_window_until: "2026-08-11T03:00:00+07:00",
    needs_review_reasons: [],
    created_at: "2026-07-10T20:00:00+07:00",
    expires_at: "2026-07-13T20:00:00+07:00",
    status_logs: [
      { from: null, to: "pending", reason: null, actor_type: "user", actor_name: null, created_at: "2026-07-10T20:00:00+07:00" },
      { from: "pending", to: "cancelled", reason: "account_deleted", actor_type: "system", actor_name: null, created_at: "2026-07-12T03:00:00+07:00" },
    ],
    notes: [],
  }),
];

export const PENDING_ORDERS = ORDERS.filter((o) => o.status === "pending").length;

export function findOrder(code: string): AdminOrderDetail | undefined {
  return ORDERS.find((o) => o.code === code);
}

/** Giờ còn lại (làm tròn xuống) tới `iso` tính từ PREVIEW_NOW. TODO(dev): giờ server. */
export function hoursLeft(iso: string, now: string = PREVIEW_NOW): number {
  return Math.floor((new Date(iso).getTime() - new Date(now).getTime()) / 3_600_000);
}

/** Nhãn trạng thái phía quản trị (bảng trạng thái US-022). */
export const REVIEW_REASON_LABEL: Record<ReviewReason, string> = {
  late_payment: "Duyệt muộn (đơn đã huỷ trước đó)",
  already_owned: "Học sinh đã sở hữu khóa trong đơn từ đơn khác — hoàn phần trùng ngoài hệ thống",
  coupon_over_limit: "Mã giảm giá đã vượt số lượt",
  course_deleted: "Có khóa đã bị xoá",
};

/**
 * Cảnh báo dự kiến khi duyệt muộn (ĐỀ XUẤT: API chi tiết đơn đã huỷ trả kèm `late_approval_warnings[]`,
 * để Quản trị viên biết TRƯỚC khi bấm). Bản xem trước bật bằng `?trang-thai=canh-bao-muon`.
 */
export const SAMPLE_LATE_WARNINGS: string[] = [
  "Học sinh đã sở hữu một khóa trong đơn từ đơn khác. Duyệt vẫn được; đơn gắn cờ “Cần xem lại” để bạn hoàn phần trùng ngoài hệ thống.",
  "Mã giảm giá của đơn đã dùng hết lượt. Duyệt vẫn giữ mức giảm đã chốt và gắn cờ “Cần xem lại”.",
];

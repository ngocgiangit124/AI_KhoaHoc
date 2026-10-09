/**
 * Dữ liệu mẫu US-022 (thanh toán thủ công "Liên hệ Quản trị viên") cho bản xem trước web.
 *
 * Tên trường:
 * - Giỏ (`GET /cart`) và preview (`GET /checkout/preview`): theo api-contract §2.3 (T16/T18 đã chốt).
 * - Phần mới của US-022 (`payment_methods`, `manual_payment`, `customer_note`, `cancel_reason_public`,
 *   `expires_at` của đơn, `replaced_by_code`): theo mục "Ảnh hưởng dữ liệu → API" của story — ĐỀ XUẤT,
 *   Architect chưa chốt trong api-contract. nextjs-dev đối chiếu lại khi T38 xong.
 */

/** Thời điểm "bây giờ" cố định của bản xem trước, để chữ "còn N giờ" không đổi giữa server/client. */
export const PREVIEW_NOW = "2026-10-08T20:00:00+07:00";

export type PaymentMethod = "manual" | "momo";

/** Phần thêm vào `GET /config/public` (story AC1). Kênh trống = null → ẩn trên giao diện. */
export interface ManualPaymentConfig {
  contact: { phone: string | null; zalo_url: string | null; email: string | null; hours: string | null };
  pending_ttl_hours: number;
}

export const checkoutConfig: { payment_methods: PaymentMethod[]; manual_payment: ManualPaymentConfig } = {
  payment_methods: ["manual"],
  manual_payment: {
    contact: {
      phone: "0909 123 456",
      zalo_url: "https://zalo.me/0909123456",
      email: "hotro@vitaminvui.vn",
      hours: "8:00–21:00, thứ Hai đến Chủ nhật",
    },
    pending_ttl_hours: 72,
  },
};

/** Biến thể "chỉ cấu hình email" (local: chỉ có SUPPORT_EMAIL) — các kênh còn lại bị ẩn. */
export const contactEmailOnly: ManualPaymentConfig["contact"] = { phone: null, zalo_url: null, email: "hotro@vitaminvui.vn", hours: null };

/* ---------- Giỏ hàng (GET /cart, T16) ---------- */

export interface CartItem {
  course_id: number;
  title: string;
  slug: string;
  grade_level: number;
  thumbnail_url: string | null;
  price: number;
  /** true: khóa đã xoá/ngừng bán/đổi miễn phí/đã sở hữu → không tính tiền, không vào đơn. */
  unavailable: boolean;
  discount_amount: number | null;
  final_amount: number | null;
  added_at: string;
}

export interface CartCoupon {
  code: string;
  name: string;
  discount_type: "percent" | "fixed";
  discount_value: number;
  discount_amount: number;
  applies_to_course_ids: number[];
}

export interface Cart {
  items: CartItem[];
  coupon: CartCoupon | null;
  pricing: { subtotal: number; discount: number; total: number };
  notices: Array<{ code: "ITEMS_UNAVAILABLE" | "COUPON_REMOVED"; message: string }>;
}

const item101: CartItem = {
  course_id: 101, title: "Hình học 9: Đường tròn từ cơ bản đến nâng cao", slug: "hinh-hoc-9-duong-tron", grade_level: 9,
  thumbnail_url: null, price: 399000, unavailable: false, discount_amount: 50000, final_amount: 349000, added_at: "2026-10-07T19:20:00+07:00",
};
const item102: CartItem = {
  course_id: 102, title: "Phương trình bậc hai và hệ thức Vi-ét", slug: "phuong-trinh-bac-hai-vi-et", grade_level: 9,
  thumbnail_url: null, price: 349000, unavailable: false, discount_amount: 0, final_amount: 349000, added_at: "2026-10-07T19:25:00+07:00",
};
const item108Unavailable: CartItem = {
  course_id: 108, title: "Hình học 8: Tứ giác và định lý Ta-lét", slug: "hinh-hoc-8-ta-let", grade_level: 8,
  thumbnail_url: null, price: 299000, unavailable: true, discount_amount: null, final_amount: null, added_at: "2026-10-01T10:00:00+07:00",
};

export const coupon: CartCoupon = {
  code: "VITAMIN50", name: "Giảm 50.000đ khóa Hình học 9", discount_type: "fixed", discount_value: 50000, discount_amount: 50000, applies_to_course_ids: [101],
};

export const cart: Cart = {
  items: [item102, item101],
  coupon,
  pricing: { subtotal: 748000, discount: 50000, total: 698000 },
  notices: [],
};

export const cartNoCoupon: Cart = {
  items: [{ ...item102 }, { ...item101, discount_amount: 0, final_amount: 399000 }],
  coupon: null,
  pricing: { subtotal: 748000, discount: 0, total: 748000 },
  notices: [],
};

export const cartWithUnavailable: Cart = {
  items: [item102, item101, item108Unavailable],
  coupon,
  pricing: { subtotal: 748000, discount: 50000, total: 698000 },
  notices: [{ code: "ITEMS_UNAVAILABLE", message: "1 khóa trong giỏ không còn bán nên không được tính vào đơn." }],
};

export const cartCouponRemoved: Cart = {
  ...cartNoCoupon,
  notices: [{ code: "COUPON_REMOVED", message: "Mã VITAMIN50 đã được gỡ vì không còn khóa nào trong giỏ dùng được mã này." }],
};

export const emptyCart: Cart = { items: [], coupon: null, pricing: { subtotal: 0, discount: 0, total: 0 }, notices: [] };

/** Đơn 0đ: mã giảm 100% (đơn hoàn tất ngay, không qua duyệt — story BR5). */
export const cartZeroTotal: Cart = {
  items: [{ ...item101, discount_amount: 399000, final_amount: 0 }],
  coupon: { code: "HOCBONG100", name: "Học bổng 100% khóa Hình học 9", discount_type: "percent", discount_value: 100, discount_amount: 399000, applies_to_course_ids: [101] },
  pricing: { subtotal: 399000, discount: 399000, total: 0 },
  notices: [],
};

/** Lỗi mã giảm giá (PUT /cart/coupon → 422/429), message hiện ngay dưới ô nhập. */
export const COUPON_ERRORS: Record<string, string> = {
  COUPON_INVALID: "Mã giảm giá không tồn tại hoặc chưa được kích hoạt.",
  COUPON_EXPIRED: "Mã giảm giá đã hết hạn.",
  COUPON_ALREADY_USED: "Bạn đã dùng mã này cho một đơn trước đó.",
  COUPON_NOT_APPLICABLE: "Mã không áp dụng cho khóa nào trong giỏ của bạn.",
  TOO_MANY_ATTEMPTS: "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau 15 phút.",
};

/* ---------- Đơn hàng của học sinh (GET /orders, GET /orders/{code}) ---------- */

export type OrderStatus = "pending" | "paid" | "cancelled" | "failed" | "refunded";
export type OrderStatusReason =
  | null
  | "manual_confirmed"
  | "zero_amount"
  | "user_cancelled"
  | "admin_cancelled"
  | "expired"
  | "superseded"
  | "account_deleted";

export interface OrderItem {
  course_id: number;
  title: string;
  slug: string;
  grade_level: number;
  price: number;
  discount_amount: number;
  final_amount: number;
}

export interface StudentOrder {
  code: string;
  status: OrderStatus;
  status_reason: OrderStatusReason;
  payment_method: "manual" | "momo" | "none";
  created_at: string;
  /** Hạn chờ duyệt (đơn manual: created_at + 72 giờ). */
  expires_at: string | null;
  paid_at: string | null;
  cancelled_at: string | null;
  items: OrderItem[];
  coupon: { code: string; discount_amount: number } | null;
  pricing: { subtotal: number; discount: number; total: number };
  /** Ghi chú học sinh gửi kèm (≤ 500 ký tự, văn bản thuần). */
  customer_note: string | null;
  /** Lý do Quản trị viên nhập khi huỷ (chỉ có ở `admin_cancelled`). */
  cancel_reason_public: string | null;
  /** Đơn `superseded`: mã đơn mới đã thay thế. */
  replaced_by_code: string | null;
}

const oi = (c: CartItem, discount = 0): OrderItem => ({
  course_id: c.course_id, title: c.title, slug: c.slug, grade_level: c.grade_level, price: c.price, discount_amount: discount, final_amount: c.price - discount,
});

const oi103: OrderItem = { course_id: 103, title: "Ôn thi vào lớp 10: 30 đề chọn lọc có chữa chi tiết", slug: "on-thi-vao-10-30-de", grade_level: 9, price: 599000, discount_amount: 0, final_amount: 599000 };
const oi113: OrderItem = { course_id: 113, title: "Bồi dưỡng học sinh giỏi Toán 9", slug: "boi-duong-hsg-toan-9", grade_level: 9, price: 899000, discount_amount: 0, final_amount: 899000 };

/** Đơn vừa gửi, đang chờ duyệt (khớp giỏ mẫu). */
export const pendingOrder: StudentOrder = {
  code: "VV2610077K3QPM",
  status: "pending",
  status_reason: null,
  payment_method: "manual",
  created_at: "2026-10-07T19:42:00+07:00",
  expires_at: "2026-10-10T19:42:00+07:00",
  paid_at: null,
  cancelled_at: null,
  items: [oi(item102), oi(item101, 50000)],
  coupon: { code: "VITAMIN50", discount_amount: 50000 },
  pricing: { subtotal: 748000, discount: 50000, total: 698000 },
  customer_note: "Gọi cho em sau 18h ạ. Zalo của mẹ em: 0987 654 321.",
  cancel_reason_public: null,
  replaced_by_code: null,
};

export const myOrders: StudentOrder[] = [
  pendingOrder,
  {
    code: "VV2610023M8RTA", status: "paid", status_reason: "manual_confirmed", payment_method: "manual",
    created_at: "2026-10-02T09:15:00+07:00", expires_at: "2026-10-05T09:15:00+07:00", paid_at: "2026-10-03T10:05:00+07:00", cancelled_at: null,
    items: [oi103], coupon: null, pricing: { subtotal: 599000, discount: 0, total: 599000 },
    customer_note: null, cancel_reason_public: null, replaced_by_code: null,
  },
  {
    code: "VV260925Q1ZD4H", status: "cancelled", status_reason: "admin_cancelled", payment_method: "manual",
    created_at: "2026-09-25T21:03:00+07:00", expires_at: "2026-09-28T21:03:00+07:00", paid_at: null, cancelled_at: "2026-09-28T16:30:00+07:00",
    items: [oi113], coupon: null, pricing: { subtotal: 899000, discount: 0, total: 899000 },
    customer_note: null,
    cancel_reason_public: "Quản trị viên đã gọi điện và nhắn Zalo 3 lần nhưng chưa liên lạc được với bạn. Khi sẵn sàng, bạn có thể đặt lại đơn từ giỏ hàng.",
    replaced_by_code: null,
  },
  {
    code: "VV260920B7HX2K", status: "cancelled", status_reason: "expired", payment_method: "manual",
    created_at: "2026-09-20T08:40:00+07:00", expires_at: "2026-09-23T08:40:00+07:00", paid_at: null, cancelled_at: "2026-09-23T08:45:00+07:00",
    items: [oi(item102)], coupon: null, pricing: { subtotal: 349000, discount: 0, total: 349000 },
    customer_note: null, cancel_reason_public: null, replaced_by_code: null,
  },
  {
    code: "VV260920N4CE9W", status: "cancelled", status_reason: "superseded", payment_method: "manual",
    created_at: "2026-09-20T08:10:00+07:00", expires_at: "2026-09-23T08:10:00+07:00", paid_at: null, cancelled_at: "2026-09-20T08:40:00+07:00",
    items: [oi(item101), oi(item102)], coupon: null, pricing: { subtotal: 748000, discount: 0, total: 748000 },
    customer_note: null, cancel_reason_public: null, replaced_by_code: "VV260920B7HX2K",
  },
  {
    code: "VV260915T2WQ6F", status: "cancelled", status_reason: "user_cancelled", payment_method: "manual",
    created_at: "2026-09-15T19:00:00+07:00", expires_at: "2026-09-18T19:00:00+07:00", paid_at: null, cancelled_at: "2026-09-15T19:20:00+07:00",
    items: [oi(item108Unavailable)], coupon: null, pricing: { subtotal: 299000, discount: 0, total: 299000 },
    customer_note: null, cancel_reason_public: null, replaced_by_code: null,
  },
  {
    code: "VV260830F5PB7N", status: "refunded", status_reason: null, payment_method: "manual",
    created_at: "2026-08-30T10:00:00+07:00", expires_at: "2026-09-02T10:00:00+07:00", paid_at: "2026-08-30T15:00:00+07:00", cancelled_at: null,
    items: [{ course_id: 110, title: "Lượng giác 11: công thức và phương trình", slug: "luong-giac-11", grade_level: 11, price: 449000, discount_amount: 0, final_amount: 449000 }],
    coupon: null, pricing: { subtotal: 449000, discount: 0, total: 449000 },
    customer_note: null, cancel_reason_public: null, replaced_by_code: null,
  },
];

/** Đơn mới tạo sau khi học sinh chọn "Đặt đơn mới" ở hộp thoại thay đơn (AC6); đơn cũ là `pendingOrder`. */
export const replacementOrder: StudentOrder = {
  ...pendingOrder,
  code: "VV2610085D2WQA",
  created_at: "2026-10-08T20:00:00+07:00",
  expires_at: "2026-10-11T20:00:00+07:00",
  items: [oi(item102), oi(item101, 50000), oi103],
  pricing: { subtotal: 1347000, discount: 50000, total: 1297000 },
  customer_note: null,
};

/** Preview mới server trả kèm 409 CHECKOUT_CHANGED (mã bị gỡ vì hết lượt). */
export const changedPricing = { subtotal: 748000, discount: 0, total: 748000 };

export function findMyOrder(code: string): StudentOrder | undefined {
  return [...myOrders, replacementOrder].find((o) => o.code === code);
}

/** Số giờ (làm tròn lên) từ PREVIEW_NOW tới `iso`; âm = đã qua. TODO(dev): dùng giờ thật của server. */
export function hoursUntil(iso: string, now: string = PREVIEW_NOW): number {
  return Math.ceil((new Date(iso).getTime() - new Date(now).getTime()) / 3_600_000);
}

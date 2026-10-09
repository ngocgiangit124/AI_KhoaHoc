import type { Cart, CheckoutPreview, OrderDetail, OrderListItem } from "./schemas";

/** Dữ liệu mẫu đúng shape contract (api-contract §2.3, §2.3.1) dùng chung cho test. */
export const item = (id: number, over: Partial<Cart["items"][number]> = {}): Cart["items"][number] => ({
  course_id: id,
  title: `Khóa ${id}`,
  slug: `khoa-${id}`,
  grade_level: 9,
  thumbnail_url: null,
  price: 300000,
  unavailable: false,
  discount_amount: 0,
  final_amount: 300000,
  added_at: "2026-10-07T19:20:00+07:00",
  ...over,
});

export const cart = (over: Partial<Cart> = {}): Cart => ({
  items: [item(1), item(2)],
  coupon: null,
  pricing: { subtotal: 600000, discount: 0, total: 600000 },
  notices: [],
  ...over,
});

export const preview = (over: Partial<CheckoutPreview> = {}): CheckoutPreview => ({
  items: [item(1), item(2)],
  removed_items: [],
  coupon: null,
  pricing: { subtotal: 600000, discount: 0, total: 600000 },
  notices: [],
  can_checkout: true,
  requires_payment: true,
  payment_methods: [{ code: "manual", label: "Liên hệ Quản trị viên", description: "Quản trị viên sẽ liên hệ hướng dẫn thanh toán và kích hoạt khóa học cho bạn" }],
  default_payment_method: "manual",
  pending_order: null,
  ...over,
});

export const order = (over: Partial<OrderDetail> = {}): OrderDetail => ({
  code: "VV261008K7M2QX",
  status: "pending",
  status_reason: null,
  payment_method: "manual",
  items: [{ course_id: 1, title: "Toán 9 nâng cao", slug: "toan-9", grade_level: 9, unit_price: 300000, discount_amount: 0, final_amount: 300000 }],
  coupon_code: null,
  subtotal: 300000,
  discount: 0,
  total: 300000,
  customer_note: null,
  cancel_reason: null,
  replaced_by_code: null,
  created_at: "2026-10-08T10:15:00+07:00",
  expires_at: "2026-10-11T10:15:00+07:00",
  paid_at: null,
  cancelled_at: null,
  refunded_at: null,
  can_cancel: true,
  payment: null,
  ...over,
});

export const listItem = (over: Partial<OrderListItem> = {}): OrderListItem => ({
  code: "VV261008K7M2QX",
  status: "pending",
  status_reason: null,
  payment_method: "manual",
  items_count: 2,
  item_titles: ["Toán 9 nâng cao", "Ngữ văn 9"],
  subtotal: 550000,
  discount: 50000,
  total: 500000,
  created_at: "2026-10-08T10:15:00+07:00",
  expires_at: "2026-10-11T10:15:00+07:00",
  paid_at: null,
  cancelled_at: null,
  can_cancel: true,
  replaced_by_code: null,
  ...over,
});

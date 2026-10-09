import { z } from "zod";

/**
 * Hình dạng response giỏ hàng / checkout / đơn của học sinh (api-contract §2.3, §2.3.1; US-022, T38).
 * Parse lúc chạy để sai contract lộ ra ngay thay vì làm vỡ giao diện.
 */

const pricingSchema = z.object({ subtotal: z.number(), discount: z.number(), total: z.number() });
export type Pricing = z.infer<typeof pricingSchema>;

export const cartItemSchema = z.object({
  course_id: z.number(),
  title: z.string(),
  slug: z.string(),
  grade_level: z.number().nullable(),
  thumbnail_url: z.string().nullable().optional(),
  price: z.number(),
  /** true: khóa đã xoá/ngừng bán/đổi miễn phí/đã sở hữu -> không tính tiền, không vào đơn. */
  unavailable: z.boolean(),
  discount_amount: z.number().nullable(),
  final_amount: z.number().nullable(),
  added_at: z.string().optional(),
});
export type CartItem = z.infer<typeof cartItemSchema>;

export const cartCouponSchema = z.object({
  code: z.string(),
  name: z.string().nullable().optional(),
  discount_type: z.string().optional(),
  discount_value: z.number().optional(),
  discount_amount: z.number(),
  applies_to_course_ids: z.array(z.number()).default([]),
});
export type CartCoupon = z.infer<typeof cartCouponSchema>;

const noticeSchema = z.object({ code: z.string(), message: z.string() });

export const pendingOrderSchema = z.object({
  code: z.string(),
  payment_method: z.string(),
  total: z.number(),
  created_at: z.string(),
  expires_at: z.string().nullable(),
});
export type PendingOrderRef = z.infer<typeof pendingOrderSchema>;

export const cartSchema = z.object({
  items: z.array(cartItemSchema),
  coupon: cartCouponSchema.nullable(),
  pricing: pricingSchema,
  notices: z.array(noticeSchema).default([]),
  /** Chỉ `GET /cart` có (T16-1): đơn `pending` hiện có của HS; các route ghi giỏ và backend cũ -> thiếu/`null`. */
  pending_order: pendingOrderSchema.nullable().optional(),
});
export type Cart = z.infer<typeof cartSchema>;

/** Mã phương thức: `manual` (Liên hệ Quản trị viên), tên cổng (`momo`) khi bật; mã lạ vẫn hiển thị theo nhãn server. */
export const paymentMethodOptionSchema = z.object({ code: z.string(), label: z.string(), description: z.string().nullable().optional() });
export type PaymentMethodOption = z.infer<typeof paymentMethodOptionSchema>;


export const checkoutPreviewSchema = z.object({
  items: z.array(cartItemSchema),
  removed_items: z.array(cartItemSchema).default([]),
  coupon: cartCouponSchema.nullable(),
  pricing: pricingSchema,
  notices: z.array(noticeSchema).default([]),
  can_checkout: z.boolean(),
  requires_payment: z.boolean(),
  payment_methods: z.array(paymentMethodOptionSchema).default([]),
  default_payment_method: z.string().nullable().optional(),
  pending_order: pendingOrderSchema.nullable().optional(),
});
export type CheckoutPreview = z.infer<typeof checkoutPreviewSchema>;

export const checkoutResultSchema = z.object({
  order_code: z.string(),
  status: z.string(),
  payment_method: z.string().optional(),
  total: z.number(),
  expires_at: z.string().nullable().optional(),
  reused: z.boolean().optional(),
  payment: z.unknown().nullish(),
  link_expired: z.boolean().optional(),
});
export type CheckoutResult = z.infer<typeof checkoutResultSchema>;

/** `errors` của 409 `PENDING_ORDER_EXISTS` (api-contract §2.3.1). */
export const pendingOrderConflictSchema = z.object({
  order_code: z.string(),
  payment_method: z.string().optional(),
  items_count: z.number(),
  total: z.number(),
  created_at: z.string(),
  expires_at: z.string().nullable().optional(),
});
export type PendingOrderConflict = z.infer<typeof pendingOrderConflictSchema>;

export const ORDER_STATUSES = ["pending", "paid", "cancelled", "failed", "refunded"] as const;
export type OrderStatus = (typeof ORDER_STATUSES)[number];

/** Trạng thái lạ (backend thêm sau) không làm vỡ trang: coi như chuỗi, nhãn rơi về "Đã huỷ"/mặc định. */
const statusSchema = z.string();
const reasonSchema = z.string().nullable();

export const orderListItemSchema = z.object({
  code: z.string(),
  status: statusSchema,
  status_reason: reasonSchema,
  payment_method: z.string(),
  items_count: z.number(),
  item_titles: z.array(z.string()).default([]),
  subtotal: z.number(),
  discount: z.number(),
  total: z.number(),
  created_at: z.string(),
  expires_at: z.string().nullable(),
  paid_at: z.string().nullable().optional(),
  cancelled_at: z.string().nullable().optional(),
  can_cancel: z.boolean(),
  replaced_by_code: z.string().nullable().optional(),
});
export type OrderListItem = z.infer<typeof orderListItemSchema>;

export const ordersPageSchema = z.object({
  data: z.array(orderListItemSchema),
  meta: z.object({ current_page: z.number(), per_page: z.number(), total: z.number(), last_page: z.number() }),
});
export type OrdersPage = z.infer<typeof ordersPageSchema>;

export const orderItemSchema = z.object({
  course_id: z.number(),
  title: z.string(),
  /** `null` khi khóa đã xoá/không còn published: không tạo liên kết. */
  slug: z.string().nullable(),
  grade_level: z.number().nullable(),
  unit_price: z.number(),
  discount_amount: z.number(),
  final_amount: z.number(),
});
export type OrderItem = z.infer<typeof orderItemSchema>;

export const orderDetailSchema = z.object({
  code: z.string(),
  status: statusSchema,
  status_reason: reasonSchema,
  payment_method: z.string(),
  items: z.array(orderItemSchema),
  coupon_code: z.string().nullable().optional(),
  subtotal: z.number(),
  discount: z.number(),
  total: z.number(),
  customer_note: z.string().nullable().optional(),
  cancel_reason: z.string().nullable().optional(),
  replaced_by_code: z.string().nullable().optional(),
  created_at: z.string(),
  expires_at: z.string().nullable(),
  paid_at: z.string().nullable().optional(),
  cancelled_at: z.string().nullable().optional(),
  refunded_at: z.string().nullable().optional(),
  can_cancel: z.boolean(),
  payment: z.unknown().nullish(),
});
export type OrderDetail = z.infer<typeof orderDetailSchema>;

/** Phần thanh toán của `GET /config/public` (US-022). Thiếu khoá -> coi như không có phương thức nào. */
export const manualContactSchema = z.object({
  phone: z.string().nullable().default(null),
  zalo_url: z.string().nullable().default(null),
  email: z.string().nullable().default(null),
  hours: z.string().nullable().default(null),
});
export type ManualContact = z.infer<typeof manualContactSchema>;

export const manualPaymentSchema = z.object({
  label: z.string(),
  description: z.string().nullable().optional(),
  pending_ttl_hours: z.number(),
  contact: manualContactSchema,
});
export type ManualPaymentConfig = z.infer<typeof manualPaymentSchema>;

export const paymentConfigSchema = z.object({
  paid_checkout_enabled: z.boolean().default(false),
  payment_methods: z.array(z.string()).default([]),
  manual_payment: manualPaymentSchema.nullable().default(null),
});
export type PaymentConfig = z.infer<typeof paymentConfigSchema>;

import { publicFetch, authFetch } from "@/lib/api";
import {
  cartSchema,
  checkoutPreviewSchema,
  checkoutResultSchema,
  orderDetailSchema,
  ordersPageSchema,
  paymentConfigSchema,
  type Cart,
  type CheckoutPreview,
  type CheckoutResult,
  type OrderDetail,
  type OrdersPage,
  type PaymentConfig,
} from "./schemas";

/** Chỉ gọi từ trình duyệt (cookie phiên `vv_session` là host-only của host API). Ghi dữ liệu: `authFetch` tự gắn CSRF. */

const json = (body: unknown): RequestInit => ({ body: JSON.stringify(body), headers: { "Content-Type": "application/json" } });

export async function fetchCart(_arg?: unknown, signal?: AbortSignal): Promise<Cart> {
  return cartSchema.parse(await authFetch<unknown>("/api/v1/cart", { signal }));
}

export async function addCartItem(courseId: number): Promise<Cart> {
  return cartSchema.parse(await authFetch<unknown>("/api/v1/cart/items", { method: "POST", ...json({ course_id: courseId }) }));
}

export async function removeCartItem(courseId: number): Promise<Cart> {
  return cartSchema.parse(await authFetch<unknown>(`/api/v1/cart/items/${courseId}`, { method: "DELETE" }));
}

export async function applyCoupon(code: string): Promise<Cart> {
  return cartSchema.parse(await authFetch<unknown>("/api/v1/cart/coupon", { method: "PUT", ...json({ code }) }));
}

export async function removeCoupon(): Promise<Cart> {
  return cartSchema.parse(await authFetch<unknown>("/api/v1/cart/coupon", { method: "DELETE" }));
}

export async function fetchCheckoutPreview(_arg?: unknown, signal?: AbortSignal): Promise<CheckoutPreview> {
  return checkoutPreviewSchema.parse(await authFetch<unknown>("/api/v1/checkout/preview", { signal }));
}

export interface CheckoutPayload {
  expected_total: number;
  /** Bỏ trống với đơn 0đ (server bỏ qua field này). */
  payment_method?: string;
  customer_note?: string;
  replace_pending?: boolean;
}

export async function submitCheckout(payload: CheckoutPayload): Promise<CheckoutResult> {
  return checkoutResultSchema.parse(await authFetch<unknown>("/api/v1/checkout", { method: "POST", ...json(payload) }));
}

export async function fetchOrders(page: number, signal?: AbortSignal): Promise<OrdersPage> {
  const qs = page > 1 ? `?page=${page}` : "";
  return ordersPageSchema.parse(await authFetch<unknown>(`/api/v1/orders${qs}`, { signal }));
}

export async function fetchOrder(code: string, signal?: AbortSignal): Promise<OrderDetail> {
  return orderDetailSchema.parse(await authFetch<unknown>(`/api/v1/orders/${encodeURIComponent(code)}`, { signal }));
}

export async function cancelOrder(code: string): Promise<OrderDetail> {
  return orderDetailSchema.parse(await authFetch<unknown>(`/api/v1/orders/${encodeURIComponent(code)}/cancel`, { method: "POST" }));
}

/** `/config/public` (công khai): phương thức thanh toán + kênh liên hệ. Lỗi mạng/shape sai -> ném lỗi. */
export async function fetchPaymentConfig(signal?: AbortSignal): Promise<PaymentConfig> {
  return paymentConfigSchema.parse(await publicFetch<unknown>("/api/v1/config/public", { signal }));
}

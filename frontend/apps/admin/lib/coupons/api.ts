import { authFetch } from "@/lib/api";
import { couponQueryToApi } from "./query";
import type { Coupon, CouponPage, CouponQuery } from "./types";

const BASE = "/api/v1/admin/coupons";
const JSON_HEADERS = { "Content-Type": "application/json" };

/** Object đơn trả PHẲNG; vẫn chấp nhận `{data}` nếu backend đổi. */
function unwrap(raw: Coupon | { data: Coupon }): Coupon {
  return "data" in raw && !("id" in raw) ? raw.data : (raw as Coupon);
}

export function listCoupons(query: CouponQuery, signal?: AbortSignal): Promise<CouponPage> {
  return authFetch<CouponPage>(`${BASE}?${couponQueryToApi(query)}`, { signal });
}

export async function getCoupon(id: number, signal?: AbortSignal): Promise<Coupon> {
  return unwrap(await authFetch<Coupon | { data: Coupon }>(`${BASE}/${id}`, { signal }));
}

/** `POST /admin/coupons` → 201. */
export async function createCoupon(body: Record<string, unknown>): Promise<Coupon> {
  return unwrap(await authFetch<Coupon | { data: Coupon }>(BASE, { method: "POST", headers: JSON_HEADERS, body: JSON.stringify(body) }));
}

/** `PUT /admin/coupons/{id}` (thay toàn bộ; gửi đủ trường). */
export async function updateCoupon(id: number, body: Record<string, unknown>): Promise<Coupon> {
  return unwrap(await authFetch<Coupon | { data: Coupon }>(`${BASE}/${id}`, { method: "PUT", headers: JSON_HEADERS, body: JSON.stringify(body) }));
}

/** `POST .../deactivate` | `.../activate` (idempotent, 200 Coupon). */
export async function setCouponActive(id: number, active: boolean): Promise<Coupon> {
  return unwrap(await authFetch<Coupon | { data: Coupon }>(`${BASE}/${id}/${active ? "activate" : "deactivate"}`, { method: "POST" }));
}

/** `DELETE` → 204; 409 `COUPON_IN_USE`. */
export async function deleteCoupon(id: number): Promise<void> {
  await authFetch<void>(`${BASE}/${id}`, { method: "DELETE" });
}

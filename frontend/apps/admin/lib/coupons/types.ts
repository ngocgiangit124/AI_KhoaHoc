import type { PaginatedResponse } from "@vitaminvui/api-client";

export type CouponState = "active" | "inactive" | "expired" | "exhausted" | "upcoming";
export type DiscountType = "percent" | "fixed_amount";

export const COUPON_STATE_LABELS: Record<CouponState, string> = {
  active: "Đang hoạt động",
  upcoming: "Sắp diễn ra",
  expired: "Hết hạn",
  exhausted: "Hết lượt",
  inactive: "Đã tắt",
};
/** Thứ tự tab lọc (AC6). */
export const COUPON_STATES: readonly CouponState[] = ["active", "upcoming", "expired", "exhausted", "inactive"];

export const PER_PAGE_OPTIONS = [25, 50] as const;
export type PerPage = (typeof PER_PAGE_OPTIONS)[number];

/** `Coupon` của api-contract (T15, phẳng). Danh sách có `*_count`; chi tiết có `courses`/`subjects`. */
export interface Coupon {
  id: number;
  code: string;
  name: string | null;
  discount_type: DiscountType;
  discount_value: number;
  max_uses: number | null;
  max_uses_per_user: 1;
  used_count: number;
  valid_from: string;
  valid_until: string | null;
  status: "active" | "inactive";
  state: CouponState;
  is_restricted: boolean;
  courses_count?: number;
  subjects_count?: number;
  courses?: Array<{ id: number; title: string }>;
  subjects?: Array<{ id: number; name: string }>;
  created_by?: number | null;
  created_at: string;
  updated_at?: string;
}

export type CouponPage = PaginatedResponse<Coupon>;

/** Bộ lọc trên URL của `/quan-tri/ma-giam-gia`. */
export interface CouponQuery {
  q: string;
  state: CouponState | "";
  page: number;
  perPage: PerPage;
}

import { COUPON_STATES, PER_PAGE_OPTIONS, type CouponQuery, type PerPage } from "./types";

export const COUPONS_PATH = "/quan-tri/ma-giam-gia";
export const DEFAULT_PER_PAGE: PerPage = 25;
export const SEARCH_MAX_LENGTH = 100;

type Params = Pick<URLSearchParams, "get">;

/** Đọc bộ lọc từ URL; giá trị lạ rơi về mặc định (không gửi tham số sai lên API → tránh 422). */
export function parseCouponQuery(params: Params): CouponQuery {
  const q = (params.get("q") ?? "").trim().slice(0, SEARCH_MAX_LENGTH);
  const stateRaw = params.get("state");
  const state = (COUPON_STATES as readonly string[]).includes(stateRaw ?? "") ? (stateRaw as CouponQuery["state"]) : "";
  const per = Number(params.get("per_page"));
  const perPage = (PER_PAGE_OPTIONS as readonly number[]).includes(per) ? (per as PerPage) : DEFAULT_PER_PAGE;
  const pageRaw = params.get("page");
  const pageNum = pageRaw !== null && /^\d+$/.test(pageRaw) ? Number(pageRaw) : 1;
  return { q, state, page: pageNum >= 1 && pageNum <= 100_000 ? pageNum : 1, perPage };
}

/** Query string cho URL trang: bỏ tham số mặc định. */
export function couponQueryToSearch(query: CouponQuery): string {
  const p = new URLSearchParams();
  if (query.q) p.set("q", query.q);
  if (query.state) p.set("state", query.state);
  if (query.perPage !== DEFAULT_PER_PAGE) p.set("per_page", String(query.perPage));
  if (query.page > 1) p.set("page", String(query.page));
  const s = p.toString();
  return s ? `?${s}` : "";
}

export function couponQueryToApi(query: CouponQuery): string {
  const p = new URLSearchParams();
  if (query.q) p.set("q", query.q);
  if (query.state) p.set("state", query.state);
  p.set("per_page", String(query.perPage));
  p.set("page", String(query.page));
  return p.toString();
}

export const hasCouponFilter = (query: CouponQuery) => query.q !== "" || query.state !== "";

import { PER_PAGE_OPTIONS, REQUEST_STATUSES, type PerPage, type RequestQuery, type RequestStatus } from "./types";

export const REQUESTS_PATH = "/quan-tri/duyet-dang-ky";
export const DEFAULT_STATUS: RequestStatus = "pending_approval";
export const DEFAULT_PER_PAGE: PerPage = 25;

type Params = Pick<URLSearchParams, "get">;

function positiveInt(raw: string | null, max: number): number | null {
  if (raw === null || !/^\d+$/.test(raw)) return null;
  const n = Number(raw);
  return Number.isSafeInteger(n) && n >= 1 && n <= max ? n : null;
}

/** Đọc bộ lọc từ URL; giá trị lạ rơi về mặc định (không gửi tham số sai lên API → tránh 422). */
export function parseRequestQuery(params: Params): RequestQuery {
  const statusRaw = params.get("status");
  const status = (REQUEST_STATUSES as readonly string[]).includes(statusRaw ?? "") ? (statusRaw as RequestStatus) : DEFAULT_STATUS;
  const per = Number(params.get("per_page"));
  const perPage = (PER_PAGE_OPTIONS as readonly number[]).includes(per) ? (per as PerPage) : DEFAULT_PER_PAGE;
  return { status, courseId: positiveInt(params.get("course_id"), 1_000_000_000), page: positiveInt(params.get("page"), 100_000) ?? 1, perPage };
}

/** Query string cho URL trang: bỏ tham số mặc định để link gọn. */
export function requestQueryToSearch(query: RequestQuery): string {
  const p = new URLSearchParams();
  if (query.status !== DEFAULT_STATUS) p.set("status", query.status);
  if (query.courseId !== null) p.set("course_id", String(query.courseId));
  if (query.perPage !== DEFAULT_PER_PAGE) p.set("per_page", String(query.perPage));
  if (query.page > 1) p.set("page", String(query.page));
  const s = p.toString();
  return s ? `?${s}` : "";
}

export function requestQueryToApi(query: RequestQuery): string {
  const p = new URLSearchParams();
  p.set("status", query.status);
  if (query.courseId !== null) p.set("course_id", String(query.courseId));
  p.set("per_page", String(query.perPage));
  p.set("page", String(query.page));
  return p.toString();
}

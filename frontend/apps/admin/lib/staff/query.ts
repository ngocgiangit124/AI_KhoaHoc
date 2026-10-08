import { STAFF_ROLES } from "@/lib/auth/types";
import { PER_PAGE_OPTIONS, STAFF_STATUSES, type PerPage, type StaffQuery } from "./types";

export const STAFF_PATH = "/quan-tri/tai-khoan";
export const DEFAULT_PER_PAGE: PerPage = 25;
export const SEARCH_MAX_LENGTH = 100;

type Params = Pick<URLSearchParams, "get">;

/** Đọc bộ lọc từ URL; giá trị lạ rơi về mặc định (không gửi tham số sai lên API → tránh 422). */
export function parseStaffQuery(params: Params): StaffQuery {
  const q = (params.get("q") ?? "").trim().slice(0, SEARCH_MAX_LENGTH);
  const roleRaw = params.get("role") ?? "";
  const statusRaw = params.get("status") ?? "";
  const per = Number(params.get("per_page"));
  const pageRaw = params.get("page");
  const pageNum = pageRaw !== null && /^\d+$/.test(pageRaw) ? Number(pageRaw) : 1;
  return {
    q,
    role: (STAFF_ROLES as readonly string[]).includes(roleRaw) ? (roleRaw as StaffQuery["role"]) : "",
    status: (STAFF_STATUSES as readonly string[]).includes(statusRaw) ? (statusRaw as StaffQuery["status"]) : "",
    page: pageNum >= 1 && pageNum <= 100_000 ? pageNum : 1,
    perPage: (PER_PAGE_OPTIONS as readonly number[]).includes(per) ? (per as PerPage) : DEFAULT_PER_PAGE,
  };
}

/** Query string cho URL trang: bỏ tham số mặc định. */
export function staffQueryToSearch(query: StaffQuery): string {
  const p = new URLSearchParams();
  if (query.q) p.set("q", query.q);
  if (query.role) p.set("role", query.role);
  if (query.status) p.set("status", query.status);
  if (query.perPage !== DEFAULT_PER_PAGE) p.set("per_page", String(query.perPage));
  if (query.page > 1) p.set("page", String(query.page));
  const s = p.toString();
  return s ? `?${s}` : "";
}

export function staffQueryToApi(query: StaffQuery): string {
  const p = new URLSearchParams();
  if (query.q) p.set("q", query.q);
  if (query.role) p.set("role", query.role);
  if (query.status) p.set("status", query.status);
  p.set("per_page", String(query.perPage));
  p.set("page", String(query.page));
  return p.toString();
}

export const hasStaffFilter = (query: StaffQuery) => query.q !== "" || query.role !== "" || query.status !== "";

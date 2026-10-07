import { PER_PAGE_OPTIONS, type PerPage, type ProfileListQuery } from "./types";

export const HOMEPAGE_TEACHERS_PATH = "/quan-tri/giao-vien";
export const MY_PROFILE_PATH = "/quan-tri/ho-so";
export const DEFAULT_PER_PAGE: PerPage = 25;
export const SEARCH_MAX = 100;

type Params = Pick<URLSearchParams, "get">;

/** Đọc bộ lọc từ URL; giá trị lạ rơi về mặc định (không bao giờ gửi tham số sai lên API → tránh 422). */
export function parseProfileQuery(params: Params): ProfileListQuery {
  const q = (params.get("q") ?? "").trim().slice(0, SEARCH_MAX);
  const pageRaw = Number(params.get("page"));
  const page = Number.isInteger(pageRaw) && pageRaw >= 1 && pageRaw <= 100_000 ? pageRaw : 1;
  const perRaw = Number(params.get("per_page"));
  const perPage = (PER_PAGE_OPTIONS as readonly number[]).includes(perRaw) ? (perRaw as PerPage) : DEFAULT_PER_PAGE;
  return { q, onlyEnabled: params.get("homepage") === "1", page, perPage };
}

export function profileQueryToSearch(query: ProfileListQuery): string {
  const p = new URLSearchParams();
  if (query.q) p.set("q", query.q);
  if (query.onlyEnabled) p.set("homepage", "1");
  if (query.perPage !== DEFAULT_PER_PAGE) p.set("per_page", String(query.perPage));
  if (query.page > 1) p.set("page", String(query.page));
  const s = p.toString();
  return s ? `?${s}` : "";
}

export function profileQueryToApi(query: ProfileListQuery): string {
  const p = new URLSearchParams();
  if (query.q) p.set("q", query.q);
  if (query.onlyEnabled) p.set("homepage", "1");
  p.set("per_page", String(query.perPage));
  p.set("page", String(query.page));
  return p.toString();
}

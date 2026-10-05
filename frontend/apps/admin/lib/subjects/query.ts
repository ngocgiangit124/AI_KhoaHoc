import { PER_PAGE_OPTIONS, type PerPage, type SubjectQuery } from "./types";

export const SUBJECTS_PATH = "/quan-tri/chuyen-de";
export const DEFAULT_PER_PAGE: PerPage = 25;
export const NAME_MAX_LENGTH = 100;

type Params = Pick<URLSearchParams, "get">;

/** Đọc bộ lọc từ URL, giá trị lạ rơi về mặc định (không bao giờ gửi tham số sai lên API → tránh 422). */
export function parseSubjectQuery(params: Params): SubjectQuery {
  const q = (params.get("q") ?? "").trim().slice(0, NAME_MAX_LENGTH);
  const statusRaw = params.get("status");
  const status = statusRaw === "active" || statusRaw === "hidden" ? statusRaw : "";
  const pageRaw = Number(params.get("page"));
  const page = Number.isInteger(pageRaw) && pageRaw >= 1 && pageRaw <= 100_000 ? pageRaw : 1;
  const perRaw = Number(params.get("per_page"));
  const perPage = (PER_PAGE_OPTIONS as readonly number[]).includes(perRaw) ? (perRaw as PerPage) : DEFAULT_PER_PAGE;
  return { q, status, page, perPage };
}

/** Query string cho URL trang: bỏ tham số mặc định để link gọn. */
export function subjectQueryToSearch(query: SubjectQuery): string {
  const p = new URLSearchParams();
  if (query.q) p.set("q", query.q);
  if (query.status) p.set("status", query.status);
  if (query.perPage !== DEFAULT_PER_PAGE) p.set("per_page", String(query.perPage));
  if (query.page > 1) p.set("page", String(query.page));
  const s = p.toString();
  return s ? `?${s}` : "";
}

/** Query string gọi API (`GET /admin/subjects`); giáo viên không gửi `status` (API bỏ qua). */
export function subjectQueryToApi(query: SubjectQuery, opts: { includeStatus: boolean }): string {
  const p = new URLSearchParams();
  if (query.q) p.set("q", query.q);
  if (opts.includeStatus && query.status) p.set("status", query.status);
  p.set("per_page", String(query.perPage));
  p.set("page", String(query.page));
  return p.toString();
}

/** Chuẩn hoá tên như backend: trim + gộp khoảng trắng. */
export function normalizeSubjectName(raw: string): string {
  return raw.replace(/\s+/g, " ").trim();
}

/** Kiểm tra sơ bộ phía client (server mới là nguồn sự thật). Trả thông điệp lỗi hoặc null. */
export function validateSubjectName(raw: string): string | null {
  const name = normalizeSubjectName(raw);
  if (name.length === 0) return "Vui lòng nhập tên chuyên đề";
  if ([...name].length > NAME_MAX_LENGTH) return `Tên chuyên đề tối đa ${NAME_MAX_LENGTH} ký tự`;
  if (/[<>]/.test(name)) return "Tên chuyên đề không được chứa ký tự < hoặc >";
  if (/[\u0000-\u001f\u007f]/.test(name)) return "Tên chuyên đề không được chứa ký tự điều khiển";
  return null;
}

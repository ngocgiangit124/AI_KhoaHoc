export const AUDIT_PATH = "/quan-tri/nhat-ky";
export const PER_PAGE_OPTIONS = [25, 50, 100] as const;
export type PerPage = (typeof PER_PAGE_OPTIONS)[number];
export const DEFAULT_PER_PAGE: PerPage = 25;
export const DEFAULT_RANGE_DAYS = 7;
export const ACTION_MAX = 60;
export const SUBJECT_TYPE_MAX = 40;
const PAGE_MAX = 100_000;

export interface AuditQuery {
  /** `null` = mặc định (7 ngày gần nhất tính tới hôm nay). */
  from: string | null;
  to: string | null;
  action: string;
  /** Chuỗi số nguyên dương hoặc "" (không lọc). */
  actorId: string;
  subjectType: string;
  subjectId: string;
  page: number;
  perPage: PerPage;
}

type Params = Pick<URLSearchParams, "get">;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const POS_INT = /^[1-9]\d{0,17}$/;
const ACTION_RE = /^[A-Za-z0-9_.:-]*$/;
const TYPE_RE = /^[A-Za-z0-9_\\]*$/;

const isRealDate = (s: string) => {
  if (!DATE_RE.test(s)) return false;
  const d = new Date(`${s}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === s;
};

/** Đọc bộ lọc từ URL; giá trị lạ rơi về mặc định (không gửi tham số sai lên API → tránh 422). */
export function parseAuditQuery(params: Params): AuditQuery {
  const fromRaw = params.get("from") ?? "";
  const toRaw = params.get("to") ?? "";
  const action = (params.get("action") ?? "").trim().slice(0, ACTION_MAX);
  const type = (params.get("subject_type") ?? "").trim().slice(0, SUBJECT_TYPE_MAX);
  const actor = (params.get("actor_id") ?? "").trim();
  const subjectId = (params.get("subject_id") ?? "").trim();
  const per = Number(params.get("per_page"));
  const pageRaw = params.get("page");
  const pageNum = pageRaw !== null && /^\d+$/.test(pageRaw) ? Number(pageRaw) : 1;
  return {
    from: isRealDate(fromRaw) ? fromRaw : null,
    to: isRealDate(toRaw) ? toRaw : null,
    action: ACTION_RE.test(action) ? action : "",
    actorId: POS_INT.test(actor) ? actor : "",
    subjectType: TYPE_RE.test(type) ? type : "",
    subjectId: POS_INT.test(subjectId) ? subjectId : "",
    page: pageNum >= 1 && pageNum <= PAGE_MAX ? pageNum : 1,
    perPage: (PER_PAGE_OPTIONS as readonly number[]).includes(per) ? (per as PerPage) : DEFAULT_PER_PAGE,
  };
}

/** Query string cho URL trang: bỏ tham số mặc định. */
export function auditQueryToSearch(query: AuditQuery): string {
  const p = new URLSearchParams();
  if (query.from) p.set("from", query.from);
  if (query.to) p.set("to", query.to);
  if (query.action) p.set("action", query.action);
  if (query.actorId) p.set("actor_id", query.actorId);
  if (query.subjectType) p.set("subject_type", query.subjectType);
  if (query.subjectId) p.set("subject_id", query.subjectId);
  if (query.perPage !== DEFAULT_PER_PAGE) p.set("per_page", String(query.perPage));
  if (query.page > 1) p.set("page", String(query.page));
  const s = p.toString();
  return s ? `?${s}` : "";
}

/** Ngày hôm nay (YYYY-MM-DD) theo giờ Việt Nam. */
export function todayVn(now: Date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Ho_Chi_Minh", year: "numeric", month: "2-digit", day: "2-digit" }).format(now);
}

export function addDays(date: string, days: number): string {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

/** Khoảng ngày áp dụng: mặc định 7 ngày gần nhất (hôm nay và 6 ngày trước). */
export function effectiveRange(query: Pick<AuditQuery, "from" | "to">, today: string): { from: string; to: string } {
  const to = query.to ?? today;
  const from = query.from ?? addDays(to, -(DEFAULT_RANGE_DAYS - 1));
  return { from, to };
}

export function rangeError(range: { from: string; to: string }): string | null {
  return range.from > range.to ? "“Từ ngày” phải trước hoặc bằng “Đến ngày”." : null;
}

/** Có bộ lọc nào khác mặc định không (để hiện "Xoá bộ lọc"). */
export function hasAuditFilter(q: AuditQuery): boolean {
  return Boolean(q.from || q.to || q.action || q.actorId || q.subjectType || q.subjectId);
}

/** Query gửi API. `page` do paginator của Laravel đọc (simplePaginate), không nằm trong validate. */
export function auditQueryToApi(query: AuditQuery, today: string): URLSearchParams {
  const p = new URLSearchParams();
  const range = effectiveRange(query, today);
  p.set("from", range.from);
  p.set("to", range.to);
  if (query.action) p.set("action", query.action);
  if (query.actorId) p.set("actor_id", query.actorId);
  if (query.subjectType) p.set("subject_type", query.subjectType);
  if (query.subjectId) p.set("subject_id", query.subjectId);
  p.set("per_page", String(query.perPage));
  if (query.page > 1) p.set("page", String(query.page));
  return p;
}

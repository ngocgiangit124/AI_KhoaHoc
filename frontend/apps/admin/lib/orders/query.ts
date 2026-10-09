import { ORDER_STATUSES, type OrderStatus } from "./schemas";

export const ORDERS_PATH = "/quan-tri/don-hang";
export const ORDER_TABS = ["cho-duyet", "da-thanh-toan", "da-huy", "hoan-tien", "tat-ca"] as const;
export type OrderTab = (typeof ORDER_TABS)[number];
export const ORDER_TAB_LABELS: Record<OrderTab, string> = {
  "cho-duyet": "Chờ duyệt",
  "da-thanh-toan": "Đã thanh toán",
  "da-huy": "Đã huỷ",
  "hoan-tien": "Đã hoàn tiền",
  "tat-ca": "Tất cả",
};
export const METHODS = ["manual", "momo", "none"] as const;
export type MethodFilter = "" | (typeof METHODS)[number];
export const PER_PAGE_OPTIONS = [25, 50] as const;
export type PerPage = (typeof PER_PAGE_OPTIONS)[number];
export const DEFAULT_PER_PAGE: PerPage = 25;
export const MAX_RANGE_DAYS = 366;
export const DEFAULT_RANGE_DAYS = 30;
export const SEARCH_MAX_LENGTH = 100;
const CURSOR_MAX = 600;

export interface OrderQuery {
  tab: OrderTab;
  q: string;
  /** `null` = dùng mặc định (30 ngày gần nhất). */
  from: string | null;
  to: string | null;
  method: MethodFilter;
  /** Chỉ dùng ở tab "Tất cả". */
  status: "" | OrderStatus;
  review: boolean;
  cursor: string;
  perPage: PerPage;
}

type Params = Pick<URLSearchParams, "get">;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

const isRealDate = (s: string) => {
  if (!DATE_RE.test(s)) return false;
  const d = new Date(`${s}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === s;
};

/** Đọc bộ lọc từ URL; giá trị lạ rơi về mặc định (không gửi tham số sai lên API → tránh 422). */
export function parseOrderQuery(params: Params): OrderQuery {
  const tabRaw = params.get("tab") ?? "";
  const tab = (ORDER_TABS as readonly string[]).includes(tabRaw) ? (tabRaw as OrderTab) : "cho-duyet";
  const methodRaw = params.get("method") ?? "";
  const statusRaw = params.get("status") ?? "";
  const fromRaw = params.get("from") ?? "";
  const toRaw = params.get("to") ?? "";
  const per = Number(params.get("per_page"));
  const cursor = (params.get("cursor") ?? "").slice(0, CURSOR_MAX);
  return {
    tab,
    q: (params.get("q") ?? "").trim().slice(0, SEARCH_MAX_LENGTH),
    from: isRealDate(fromRaw) ? fromRaw : null,
    to: isRealDate(toRaw) ? toRaw : null,
    method: (METHODS as readonly string[]).includes(methodRaw) ? (methodRaw as MethodFilter) : "",
    status: (ORDER_STATUSES as readonly string[]).includes(statusRaw) ? (statusRaw as OrderStatus) : "",
    review: params.get("review") === "1",
    cursor: /^[A-Za-z0-9_\-=.+/]*$/.test(cursor) ? cursor : "",
    perPage: (PER_PAGE_OPTIONS as readonly number[]).includes(per) ? (per as PerPage) : DEFAULT_PER_PAGE,
  };
}

/** Query string cho URL trang: bỏ tham số mặc định. */
export function orderQueryToSearch(query: OrderQuery): string {
  const p = new URLSearchParams();
  if (query.tab !== "cho-duyet") p.set("tab", query.tab);
  if (query.q) p.set("q", query.q);
  if (query.tab !== "cho-duyet") {
    if (query.from) p.set("from", query.from);
    if (query.to) p.set("to", query.to);
    if (query.method) p.set("method", query.method);
    if (query.review) p.set("review", "1");
    if (query.tab === "tat-ca" && query.status) p.set("status", query.status);
  }
  if (query.perPage !== DEFAULT_PER_PAGE) p.set("per_page", String(query.perPage));
  if (query.cursor) p.set("cursor", query.cursor);
  const s = p.toString();
  return s ? `?${s}` : "";
}

/** Ngày hôm nay (YYYY-MM-DD) theo giờ Việt Nam, giống nhau ở server và trình duyệt. */
export function todayVn(now: Date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Ho_Chi_Minh", year: "numeric", month: "2-digit", day: "2-digit" }).format(now);
}

export function addDays(date: string, days: number): string {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

export function effectiveRange(query: Pick<OrderQuery, "from" | "to">, today: string): { from: string; to: string } {
  const to = query.to ?? today;
  const from = query.from ?? addDays(to, -DEFAULT_RANGE_DAYS);
  return { from, to };
}

/** Lỗi khoảng ngày (null = hợp lệ). Kiểm ở trình duyệt trước khi gọi API. */
export function rangeError(range: { from: string; to: string }): string | null {
  if (range.from > range.to) return "“Từ ngày” phải trước hoặc bằng “Đến ngày”.";
  const diff = (Date.parse(`${range.to}T00:00:00Z`) - Date.parse(`${range.from}T00:00:00Z`)) / 86_400_000;
  if (diff > MAX_RANGE_DAYS) return `Khoảng ngày tối đa ${MAX_RANGE_DAYS} ngày.`;
  return null;
}

export const TAB_STATUSES: Record<Exclude<OrderTab, "tat-ca">, readonly OrderStatus[]> = {
  "cho-duyet": ["pending"],
  "da-thanh-toan": ["paid"],
  "da-huy": ["cancelled", "failed"],
  "hoan-tien": ["refunded"],
};

/** Query gửi API. Tab "Chờ duyệt": `status[]=pending&payment_method=manual&sort=oldest`, KHÔNG gửi khoảng ngày (AC15). */
export function orderQueryToApi(query: OrderQuery, today: string): URLSearchParams {
  const p = new URLSearchParams();
  if (query.tab === "cho-duyet") {
    p.append("status[]", "pending");
    p.set("payment_method", "manual");
    p.set("sort", "oldest");
  } else {
    const range = effectiveRange(query, today);
    p.set("from", range.from);
    p.set("to", range.to);
    const statuses = query.tab === "tat-ca" ? (query.status ? [query.status] : []) : TAB_STATUSES[query.tab];
    for (const s of statuses) p.append("status[]", s);
    if (query.method) p.set("payment_method", query.method);
    if (query.review) p.set("needs_review", "1");
    p.set("sort", "newest");
  }
  if (query.q) p.set("q", query.q);
  p.set("per_page", String(query.perPage));
  if (query.cursor) p.set("cursor", query.cursor);
  return p;
}

/** Tìm theo email/SĐT bị giới hạn 30/phút (S1): nhận ra để nhắc người dùng khi gặp 429. */
export const looksLikeContactSearch = (q: string) => q.includes("@") || /^[+\d][\d\s.\-]{6,}$/.test(q.trim());

import { authFetch } from "@/lib/api";
import { auditPageSchema, type AuditPage } from "./schemas";
import { auditQueryToApi, type AuditQuery } from "./query";

const BASE = "/api/v1/admin/audit-logs";

/** Phản hồi sai hợp đồng (zod không parse được). */
export class AuditContractError extends Error {
  constructor(cause?: unknown) {
    super("Dữ liệu nhật ký không đúng định dạng mong đợi.");
    this.name = "AuditContractError";
    this.cause = cause;
  }
}

/** Chỉ đọc: chỉ có GET. Không lưu storage; mỗi lần gọi là một request mới. */
export async function listAuditLogs(query: AuditQuery, today: string, signal?: AbortSignal): Promise<AuditPage> {
  const raw = await authFetch<unknown>(`${BASE}?${auditQueryToApi(query, today).toString()}`, { signal });
  const r = auditPageSchema.safeParse(raw);
  if (!r.success) throw new AuditContractError(r.error);
  return { data: r.data.data, hasNext: Boolean(r.data.links?.next) };
}

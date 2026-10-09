import { z } from "zod";

/** `changes` do server trả: object (đã lọc PII/secret lúc ghi) hoặc mảng; không tin kiểu từng giá trị. */
const changesSchema = z.union([z.record(z.string(), z.unknown()), z.array(z.unknown())]).nullish();

/** Một dòng nhật ký (api-contract, `GET /admin/audit-logs`). */
export const auditLogSchema = z.object({
  id: z.number(),
  action: z.string(),
  actor_id: z.number().nullable(),
  actor_role: z.string().nullable(),
  actor_name: z.string().nullable(),
  subject_type: z.string().nullable(),
  subject_id: z.number().nullable(),
  changes: changesSchema,
  ip: z.string().nullable(),
  user_agent: z.string().nullable(),
  created_at: z.string(),
});
export type AuditLog = z.infer<typeof auditLogSchema>;

/** simplePaginate: `meta` không có total/last_page; chỉ dựa vào `links.next`. */
export const auditPageSchema = z.object({
  data: z.array(auditLogSchema),
  meta: z.object({ current_page: z.number().optional(), per_page: z.number().optional() }).passthrough().optional(),
  links: z.object({ next: z.string().nullable().optional(), prev: z.string().nullable().optional() }).partial().optional(),
});
export type AuditPage = { data: AuditLog[]; hasNext: boolean };

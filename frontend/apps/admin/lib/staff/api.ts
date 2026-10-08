import { authFetch } from "@/lib/api";
import type { StaffRole } from "@/lib/auth/types";
import { staffQueryToApi } from "./query";
import type { StaffAccount, StaffPage, StaffQuery, StaffRoleResult, StaffWithPassword } from "./types";

const BASE = "/api/v1/admin/staff";
const JSON_HEADERS = { "Content-Type": "application/json" };

export function listStaff(query: StaffQuery, signal?: AbortSignal): Promise<StaffPage> {
  return authFetch<StaffPage>(`${BASE}?${staffQueryToApi(query)}`, { signal });
}

/** `POST /admin/staff` → 201 kèm `initial_password` (chỉ trả một lần). */
export function createStaff(body: { name: string; email: string; role: StaffRole }): Promise<StaffWithPassword> {
  return authFetch<StaffWithPassword>(BASE, { method: "POST", headers: JSON_HEADERS, body: JSON.stringify(body) });
}

export function lockStaff(id: number): Promise<StaffAccount> {
  return authFetch<StaffAccount>(`${BASE}/${id}/lock`, { method: "POST" });
}

export function unlockStaff(id: number): Promise<StaffAccount> {
  return authFetch<StaffAccount>(`${BASE}/${id}/unlock`, { method: "POST" });
}

/** `POST .../reset-password` → 200 kèm `initial_password` mới (một lần). */
export function resetStaffPassword(id: number): Promise<StaffWithPassword> {
  return authFetch<StaffWithPassword>(`${BASE}/${id}/reset-password`, { method: "POST" });
}

/** `PATCH .../role` → 200 kèm `released_course_ids` (khóa bị gỡ giáo viên). */
export async function changeStaffRole(id: number, role: StaffRole): Promise<StaffRoleResult> {
  const res = await authFetch<StaffRoleResult>(`${BASE}/${id}/role`, { method: "PATCH", headers: JSON_HEADERS, body: JSON.stringify({ role }) });
  return { ...res, released_course_ids: Array.isArray(res.released_course_ids) ? res.released_course_ids : [] };
}

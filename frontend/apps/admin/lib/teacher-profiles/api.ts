import { authFetch } from "@/lib/api";
import { profileQueryToApi } from "./query";
import type { ContentPatch, ProfileListQuery, ProfileTarget, TeacherProfile, TeacherProfilePage } from "./types";

const JSON_HEADERS = { "Content-Type": "application/json" };
const ME = "/api/v1/admin/me/teacher-profile";
const USERS = "/api/v1/admin/teacher-profiles";

/** Object đơn trả phẳng (`TeacherProfile`); vẫn chấp nhận `{data}` nếu backend bọc lại. */
function unwrap(raw: TeacherProfile | { data: TeacherProfile }): TeacherProfile {
  return "data" in raw && !("user" in raw) ? raw.data : (raw as TeacherProfile);
}

const base = (t: ProfileTarget) => (t.kind === "me" ? ME : `${USERS}/${t.id}`);

export async function getProfile(target: ProfileTarget, signal?: AbortSignal): Promise<TeacherProfile> {
  return unwrap(await authFetch<TeacherProfile | { data: TeacherProfile }>(base(target), { signal }));
}

/** PATCH chỉ gồm trường đã đổi (AC21). */
export async function patchProfile(target: ProfileTarget, patch: ContentPatch): Promise<TeacherProfile> {
  return unwrap(await authFetch(base(target), { method: "PATCH", headers: JSON_HEADERS, body: JSON.stringify(patch) }));
}

/** Multipart `avatar` (không tự đặt Content-Type để trình duyệt gắn boundary). */
export async function uploadAvatar(target: ProfileTarget, file: Blob, filename = "avatar.jpg"): Promise<TeacherProfile> {
  const form = new FormData();
  form.append("avatar", file, filename);
  return unwrap(await authFetch(`${base(target)}/avatar`, { method: "POST", body: form }));
}

export async function deleteAvatar(target: ProfileTarget): Promise<TeacherProfile> {
  return unwrap(await authFetch(`${base(target)}/avatar`, { method: "DELETE" }));
}

/** Chỉ giáo viên với hồ sơ của mình; không có route đồng ý thay người khác. */
export async function giveConsent(version: string): Promise<TeacherProfile> {
  return unwrap(await authFetch(`${ME}/consent`, { method: "POST", headers: JSON_HEADERS, body: JSON.stringify({ version }) }));
}

export async function withdrawConsent(): Promise<TeacherProfile> {
  return unwrap(await authFetch(`${ME}/consent`, { method: "DELETE" }));
}

export function listProfiles(query: ProfileListQuery, signal?: AbortSignal): Promise<TeacherProfilePage> {
  return authFetch<TeacherProfilePage>(`${USERS}?${profileQueryToApi(query)}`, { signal });
}

export async function patchHomepage(id: number, body: { show_on_homepage?: boolean; homepage_order?: number | null }): Promise<TeacherProfile> {
  return unwrap(await authFetch(`${USERS}/${id}/homepage`, { method: "PATCH", headers: JSON_HEADERS, body: JSON.stringify(body) }));
}

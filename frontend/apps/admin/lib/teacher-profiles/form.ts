import { BIO_MAX, HEADLINE_MAX, type ContentPatch, type TeacherProfile } from "./types";

export type ContentField = "headline" | "bio";
export type FieldErrors = Partial<Record<ContentField | "avatar" | "consent", string>>;
export interface Draft {
  headline?: string;
  bio?: string;
}

/** Chuẩn hoá như backend: `\r\n` → `\n`, trim (bio giữ xuống dòng giữa nội dung). */
export function normalizeText(raw: string): string {
  return raw.replace(/\r\n?/g, "\n").trim();
}

const count = (s: string) => [...s].length;

/** Có `<`/`>`, ký tự điều khiển (trừ `\n` ở bio) hoặc U+2028/2029. */
function hasBadChars(value: string, allowNewline: boolean): boolean {
  const control = allowNewline ? /[\u0000-\u0009\u000b-\u001f\u007f-\u009f\u2028\u2029]/ : /[\u0000-\u001f\u007f-\u009f\u2028\u2029]/;
  return /[<>]/.test(value) || control.test(value);
}

/** Kiểm sơ bộ ở client (server là nguồn sự thật). Chỉ kiểm trường người dùng đã sửa. */
export function validateDraft(draft: Draft): FieldErrors {
  const errors: FieldErrors = {};
  if (draft.headline !== undefined) {
    const v = normalizeText(draft.headline);
    if (count(v) > HEADLINE_MAX) errors.headline = `Chuyên môn tối đa ${HEADLINE_MAX} ký tự.`;
    else if (hasBadChars(v, false)) errors.headline = "Chuyên môn là một dòng chữ thường, không chứa < hoặc > và ký tự điều khiển.";
  }
  if (draft.bio !== undefined) {
    const v = normalizeText(draft.bio);
    if (count(v) > BIO_MAX) errors.bio = `Giới thiệu tối đa ${BIO_MAX} ký tự.`;
    else if (hasBadChars(v, true)) errors.bio = "Giới thiệu là văn bản thuần, không chứa < hoặc > và ký tự điều khiển.";
  }
  return errors;
}

/**
 * PATCH chỉ chứa trường đã đổi (AC21): so sánh bản đã chuẩn hoá với giá trị đang lưu; rỗng → `null`.
 * Trả `null` khi không có gì đổi.
 */
export function buildContentPatch(profile: Pick<TeacherProfile, "headline" | "bio">, draft: Draft): ContentPatch | null {
  const patch: ContentPatch = {};
  for (const field of ["headline", "bio"] as const) {
    const raw = draft[field];
    if (raw === undefined) continue;
    const next = normalizeText(raw);
    const current = normalizeText(profile[field] ?? "");
    if (next !== current) patch[field] = next === "" ? null : next;
  }
  return Object.keys(patch).length > 0 ? patch : null;
}

/** Giá trị hiện trong ô: bản đang sửa nếu có, không thì giá trị đã lưu. */
export function fieldValue(profile: Pick<TeacherProfile, "headline" | "bio">, draft: Draft, field: ContentField): string {
  return draft[field] ?? profile[field] ?? "";
}

/** Bỏ khỏi bản nháp những trường đã gửi (hoặc đã trùng giá trị lưu) để ô theo dữ liệu mới nhất từ server. */
export function pruneDraft(profile: Pick<TeacherProfile, "headline" | "bio">, draft: Draft, sent: readonly ContentField[]): Draft {
  const next: Draft = {};
  for (const field of ["headline", "bio"] as const) {
    const raw = draft[field];
    if (raw === undefined || sent.includes(field)) continue;
    if (normalizeText(raw) !== normalizeText(profile[field] ?? "")) next[field] = raw;
  }
  return next;
}

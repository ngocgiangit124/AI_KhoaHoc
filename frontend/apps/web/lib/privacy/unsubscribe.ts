/** Token tối đa 512 ký tự (api-contract §2.8.2); dài hơn hoặc rỗng -> coi như thiếu. */
export const MAX_UNSUBSCRIBE_TOKEN = 512;

/** Đọc `t` từ query string (`?t=...`). */
export function readUnsubscribeToken(search: string): string | null {
  const t = new URLSearchParams(search).get("t")?.trim();
  return t && t.length <= MAX_UNSUBSCRIBE_TOKEN ? t : null;
}

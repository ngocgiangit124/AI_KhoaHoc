/**
 * Chống open redirect (S23, ADR-004 §2.6): chỉ chấp nhận đường dẫn tương đối bắt đầu
 * bằng "/", không phải "//" hay "/\" (trình duyệt có thể hiểu như protocol-relative URL),
 * không chứa ký tự điều khiển. Mọi trường hợp khác trả về `fallback`.
 */
export function safeRedirect(next: string | null | undefined, fallback = "/"): string {
  if (!next) return fallback;
  if (!next.startsWith("/")) return fallback;
  if (next.startsWith("//")) return fallback;
  if (next.startsWith("/\\")) return fallback;
  // eslint-disable-next-line no-control-regex -- cố ý kiểm ký tự điều khiển 0x00–0x1F
  if (/[\u0000-\u001f]/.test(next)) return fallback;
  return next;
}

/**
 * Chỉ cho phép chuyển trang tới `pay_url` khi origin là HTTPS và host thuộc allowlist MoMo
 * (S23). `allowedHosts` truyền từ `NEXT_PUBLIC_MOMO_HOSTS` của app gọi hàm này.
 */
export function isAllowedPayUrl(url: string, allowedHosts: readonly string[]): boolean {
  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    return false;
  }
  if (parsed.protocol !== "https:") return false;
  return allowedHosts.includes(parsed.hostname);
}

/** JSON-LD an toàn cho SEO: escape "<" để không bị hiểu thành thẻ `</script>` (S23). */
export function jsonLd(data: unknown): string {
  return JSON.stringify(data).replace(/</g, "\\u003c");
}

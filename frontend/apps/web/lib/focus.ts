/**
 * Đưa focus vào ô lỗi ĐẦU TIÊN sau khi submit (WCAG 3.3.1/2.4.3): nhận danh sách id ô theo thứ tự hiển thị, chọn ô đầu có lỗi.
 * Chờ khung hình sau để lỗi/thuộc tính `aria-invalid` đã render; ô bị khoá (`disabled`) không nhận focus nên bỏ qua.
 */
export function focusFirstError(candidates: ReadonlyArray<readonly [id: string, hasError: boolean]>): void {
  const first = candidates.find(([, hasError]) => hasError)?.[0];
  if (!first || typeof window === "undefined") return;
  requestAnimationFrame(() => document.getElementById(first)?.focus());
}

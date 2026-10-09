export const NOTE_MAX = 500;

/**
 * Ghi chú gửi Quản trị viên (`customer_note`): văn bản thuần ≤ 500 ký tự. Server cấm `<`, `>` và ký tự điều khiển (trừ xuống dòng);
 * kiểm sớm ở đây chỉ để báo lỗi tại ô nhập, Laravel validate lại. Trả thông báo lỗi hoặc `null` nếu hợp lệ.
 */
export function validateNote(note: string): string | null {
  if (note.length > NOTE_MAX) return `Ghi chú tối đa ${NOTE_MAX} ký tự.`;
  if (/[<>]/.test(note)) return "Ghi chú chỉ gồm văn bản thuần, không dùng dấu < hoặc >.";
  if (/[\u0000-\u0009\u000B\u000C\u000E-\u001F\u007F]/.test(note)) return "Ghi chú có ký tự không hợp lệ.";
  return null;
}

/** Chuỗi gửi lên: bỏ khoảng trắng đầu/cuối; rỗng -> `undefined` (không gửi field). */
export function noteForRequest(note: string): string | undefined {
  const t = note.trim();
  return t === "" ? undefined : t;
}

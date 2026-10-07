/** Định dạng hiển thị cho danh mục/chi tiết khóa học (thuần, dễ test). */

const numberFormatter = new Intl.NumberFormat("vi-VN");

export function formatEnrollments(count: number): string {
  // US-003 BR/edge: 0 học sinh -> câu chữ tích cực, không hiện "0 học sinh".
  if (count <= 0) return "Chưa có học sinh đăng ký";
  return `${numberFormatter.format(count)} học sinh đã đăng ký`;
}

/** 3725 -> "1 giờ 2 phút"; < 60 giây -> "< 1 phút". */
export function formatTotalDuration(totalSeconds: number): string {
  if (totalSeconds < 60) return totalSeconds > 0 ? "< 1 phút" : "—";
  const totalMinutes = Math.round(totalSeconds / 60);
  const hours = Math.floor(totalMinutes / 60);
  const minutes = totalMinutes % 60;
  if (hours === 0) return `${minutes} phút`;
  return minutes === 0 ? `${hours} giờ` : `${hours} giờ ${minutes} phút`;
}

/** Thời lượng một bài: 125 -> "2:05", 3725 -> "1:02:05". */
export function formatLessonDuration(seconds: number | null): string | null {
  if (seconds === null || seconds <= 0) return null;
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);
  const ss = String(s).padStart(2, "0");
  return h > 0 ? `${h}:${String(m).padStart(2, "0")}:${ss}` : `${m}:${ss}`;
}

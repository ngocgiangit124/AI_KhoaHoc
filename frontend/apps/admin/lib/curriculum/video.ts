/** Giới hạn tải video (PO 2026-10-07: `VIDEO_MAX_UPLOAD_MB=1024`). Server vẫn kiểm; đây chỉ để chặn sớm ở UI. */
export const MAX_VIDEO_BYTES = 1024 * 1024 * 1024;
export const VIDEO_EXTENSIONS = [".mp4", ".mov", ".mkv", ".webm"] as const;
/** Mỗi PATCH TUS ≤ 8 MB (VideoLab trả 413 nếu quá); dùng 4 MB để chịu được mạng chậm. */
export const TUS_CHUNK_SIZE = 4 * 1024 * 1024;

export const VIDEO_ACCEPT = ".mp4,.mov,.mkv,.webm,video/mp4,video/quicktime,video/x-matroska,video/webm";
export const VIDEO_HINT = "MP4, MOV, MKV hoặc WebM · tối đa 1 GB";

export function formatBytes(bytes: number): string {
  if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toLocaleString("vi-VN", { maximumFractionDigits: 2 })} GB`;
  if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toLocaleString("vi-VN", { maximumFractionDigits: 1 })} MB`;
  return `${Math.max(1, Math.round(bytes / 1024)).toLocaleString("vi-VN")} KB`;
}

/** Kiểm tệp trước khi tải. Trả thông báo tiếng Việt, hoặc `null` nếu hợp lệ. */
export function validateVideoFile(file: { name: string; size: number }): string | null {
  const lower = file.name.toLowerCase();
  if (!VIDEO_EXTENSIONS.some((ext) => lower.endsWith(ext))) return "Định dạng không được hỗ trợ. Hãy chọn tệp MP4, MOV, MKV hoặc WebM.";
  if (file.size <= 0) return "Tệp video trống. Hãy chọn tệp khác.";
  if (file.size > MAX_VIDEO_BYTES) return `Tệp quá lớn (${formatBytes(file.size)}). Video tối đa 1 GB, hãy nén hoặc cắt ngắn rồi tải lại.`;
  return null;
}

/** Số giây ước lượng còn lại từ tốc độ trung bình (làm tròn lên phút khi > 90 giây). `null` nếu chưa đủ dữ liệu. */
export function formatEta(secondsLeft: number | null): string | null {
  if (secondsLeft === null || !Number.isFinite(secondsLeft) || secondsLeft < 0) return null;
  if (secondsLeft < 10) return "còn vài giây";
  if (secondsLeft < 90) return `còn khoảng ${Math.round(secondsLeft)} giây`;
  return `còn khoảng ${Math.ceil(secondsLeft / 60)} phút`;
}

/** Khoảng chờ giữa hai lần hỏi trạng thái: 3 giây, dãn dần tới 10 giây sau 2 phút (`elapsedMs` từ lần hỏi đầu). */
export function nextPollDelay(elapsedMs: number): number {
  if (elapsedMs < 30_000) return 3_000;
  if (elapsedMs < 120_000) return 5_000;
  return 10_000;
}

export function isVideoPending(status: string | null): boolean {
  return status === "created" || status === "uploading" || status === "processing";
}

/** Quá thời gian này mà bài vẫn `created/uploading/processing` thì dừng hỏi trạng thái, hiện nút "Kiểm tra lại". */
export const POLL_MAX_MS = 10 * 60 * 1000;

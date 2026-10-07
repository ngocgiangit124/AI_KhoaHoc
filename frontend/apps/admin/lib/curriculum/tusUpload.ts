import { DetailedError, Upload } from "tus-js-client";
import { TUS_CHUNK_SIZE } from "./video";
import type { VideoUploadSession } from "./types";

export type TusFailure = {
  /** Mất kết nối / máy chủ tạm lỗi: tải lại được từ chỗ dừng (`start()` gửi HEAD lấy offset). */
  retryable: boolean;
  /** Thông điệp từ API (ví dụ 422 VIDEO_INVALID) nếu có. */
  message: string | null;
  status: number | null;
};

export interface TusHandle {
  /** Tiếp tục từ offset đã lưu ở server (sau khi mất mạng). */
  resume(): void;
  /** Dừng và (nếu máy chủ hỗ trợ) xoá phiên tải dở. */
  abort(): Promise<void>;
}

export interface TusCallbacks {
  onProgress: (sent: number, total: number) => void;
  onSuccess: () => void;
  onError: (failure: TusFailure) => void;
}

/** Thời gian chờ giữa các lần tự thử lại (ms). Hết danh sách thì báo lỗi; giao diện tự tiếp tục khi trình duyệt báo `online`. */
export const RETRY_DELAYS = [0, 1000, 3000, 5000, 10_000, 20_000];

function parseMessage(body: string | undefined): string | null {
  if (!body) return null;
  try {
    const parsed = JSON.parse(body) as { message?: unknown };
    return typeof parsed.message === "string" ? parsed.message : null;
  } catch {
    return null;
  }
}

export function toFailure(error: Error): TusFailure {
  if (error instanceof DetailedError) {
    const res = error.originalResponse;
    const status = res ? res.getStatus() : null;
    // Không có phản hồi = lỗi mạng; 5xx/423/409 tus-js-client tự thử lại rồi mới tới đây → vẫn cho tải lại tay.
    const retryable = status === null || status >= 500 || status === 423 || status === 409;
    return { retryable, message: res ? parseMessage(res.getBody()) : null, status };
  }
  return { retryable: false, message: null, status: null };
}

/**
 * Tải TUS bằng đúng endpoint + header do API trả (VideoLab hoặc Bunny: mã không đổi).
 * `chunkSize` ≤ 8 MB (api-contract §T12). Không lưu fingerprint: mỗi lần chọn tệp là một asset mới ở API.
 */
export function createTusUpload(file: File, session: VideoUploadSession["upload"], cb: TusCallbacks): TusHandle {
  const upload = new Upload(file, {
    endpoint: session.tus_endpoint,
    headers: session.headers,
    chunkSize: TUS_CHUNK_SIZE,
    retryDelays: RETRY_DELAYS,
    storeFingerprintForResuming: false,
    removeFingerprintOnSuccess: true,
    metadata: { filename: file.name, filetype: file.type || "application/octet-stream", title: file.name },
    onProgress: (sent, total) => cb.onProgress(sent, total),
    onSuccess: () => cb.onSuccess(),
    onError: (error) => cb.onError(toFailure(error)),
  });
  upload.start();
  return {
    resume: () => upload.start(),
    abort: () => upload.abort(true).catch(() => undefined),
  };
}

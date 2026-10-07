import { ApiError } from "@vitaminvui/api-client";

export const HEARTBEAT_INTERVAL_MS = 20_000;
/** Throttle backend: 6/phút/user/bài → tối thiểu ~10 giây giữa hai lần gửi. */
export const HEARTBEAT_MIN_GAP_MS = 10_000;
const MAX_DELTA = 60;
/** Hai mẫu `timeupdate` cách nhau hơn mức này (giây phương tiện) là tua, không phải xem liên tục. */
const MAX_STEP_SECONDS = 2.5;
const MAX_POSITION = 86_400;

export interface HeartbeatPayload {
  position_seconds: number;
  watched_delta_seconds: number;
}

/**
 * Cộng dồn số giây video ĐÃ XEM THẬT (đơn vị giây video, nên xem 2× cộng gấp đôi giây tường — backend chặn ở 2× + 5s) và dựng body
 * heartbeat. Tua (nhảy > 2,5 giây giữa hai mẫu) không được tính. Body luôn là SỐ NGUYÊN (backend 422 với số thực).
 */
export class HeartbeatTracker {
  private pending = 0;
  private last: number | null = null;
  private lastSentAt: number | null = null;
  private lastSentPosition: number | null = null;
  private position = 0;
  /** Đã có ít nhất một lần phát/tua: chưa từng phát thì không gửi gì (mở bài rồi rời đi không tạo heartbeat). */
  private touched = false;

  /** Gọi khi bắt đầu phát, sau khi tua xong, khi tạm dừng: đặt lại mốc để không tính khoảng nhảy. */
  reset(currentTime: number): void {
    this.touched = true;
    this.last = currentTime;
    this.position = currentTime;
  }

  /** Gọi mỗi `timeupdate` KHI ĐANG PHÁT. */
  observe(currentTime: number): void {
    this.touched = true;
    if (this.last !== null) {
      const step = currentTime - this.last;
      if (step > 0 && step <= MAX_STEP_SECONDS) this.pending += step;
    }
    this.last = currentTime;
    this.position = currentTime;
  }

  /** Đã đủ khoảng cách tối thiểu kể từ lần gửi trước (tránh 429) chưa. */
  canSend(nowMs: number, gapMs = HEARTBEAT_MIN_GAP_MS): boolean {
    return this.lastSentAt === null || nowMs - this.lastSentAt >= gapMs;
  }

  /**
   * Lấy body để gửi (và coi như đã gửi). `null` nếu không có gì mới: chưa xem thêm giây nào và vị trí không đổi.
   * Phần lẻ dưới 1 giây được giữ lại cho lần sau; phần vượt trần 60 giây bị bỏ (không tích luỹ mãi khi gửi lỗi).
   */
  take(nowMs: number): HeartbeatPayload | null {
    const delta = Math.min(MAX_DELTA, Math.floor(this.pending));
    const position = Math.min(MAX_POSITION, Math.max(0, Math.floor(this.position)));
    if (!this.touched) return null;
    if (delta === 0 && position === this.lastSentPosition) return null;
    this.pending = delta === MAX_DELTA ? 0 : this.pending - delta;
    this.lastSentAt = nowMs;
    this.lastSentPosition = position;
    return { position_seconds: position, watched_delta_seconds: delta };
  }

  /** Gửi lỗi tạm thời (mạng/429/5xx): trả lại số giây để lần sau gửi bù. */
  restore(payload: HeartbeatPayload): void {
    this.pending += payload.watched_delta_seconds;
    this.lastSentPosition = null;
  }
}

export type HeartbeatFailure = "revoked" | "gone" | "drop" | "retry";

/** Thất bại của heartbeat → hành động: thu hồi quyền (dừng player), bài/khoá bị xoá (dừng), body sai (bỏ), còn lại thử lại lần sau. */
export function classifyHeartbeatFailure(err: unknown): HeartbeatFailure {
  if (err instanceof ApiError) {
    if (err.status === 403) return "revoked";
    if (err.status === 404) return "gone";
    if (err.status === 422) return "drop";
  }
  return "retry";
}

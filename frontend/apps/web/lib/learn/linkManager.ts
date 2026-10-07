import { classifyPlaybackError, isTerminalPlaybackError, type PlaybackErrorKind } from "./errors";
import type { PlaybackInfo } from "./schemas";

export type LinkReason = "initial" | "scheduled" | "forbidden" | "retry" | "resume";

export interface LinkManagerOptions {
  fetchPlayback: () => Promise<PlaybackInfo>;
  /** Có link mới (lần đầu hoặc làm mới). */
  onInfo: (info: PlaybackInfo, reason: LinkReason) => void;
  /** `fatal`: dừng hẳn (người dùng cần bấm Thử lại). Không fatal: link cũ vẫn dùng được, chỉ ghi nhận. */
  onError: (kind: PlaybackErrorKind, err: unknown, fatal: boolean) => void;
  now?: () => number;
  setTimer?: (fn: () => void, ms: number) => unknown;
  clearTimer?: (handle: unknown) => void;
  /** Xin link mới sớm hơn `expires_at` chừng này (tối đa; link ngắn hơn thì lấy 1/4 thời hạn). */
  leadMs?: number;
  /** Khoảng chờ tối thiểu giữa hai lần xin (chống vòng lặp khi đồng hồ máy lệch; throttle playback 30/phút). */
  minDelayMs?: number;
  retryDelayMs?: number;
  maxRetries?: number;
  /** Số lần liên tiếp CDN báo 403 mà vẫn chưa phát được thì bỏ cuộc. */
  maxForbiddenStreak?: number;
}

/**
 * Giờ xin link mới: trước `expires_at` một khoảng `lead`, không bao giờ sớm hơn `minDelay` kể từ bây giờ.
 * `expires_at` null/không hợp lệ (link ngoài) → không cần làm mới.
 */
export function refreshDelayMs(expiresAt: string | null, nowMs: number, leadMs = 60_000, minDelayMs = 10_000): number | null {
  if (!expiresAt) return null;
  const expires = Date.parse(expiresAt);
  if (Number.isNaN(expires)) return null;
  const remaining = expires - nowMs;
  const lead = Math.min(leadMs, Math.max(0, remaining) / 4);
  return Math.max(minDelayMs, remaining - lead);
}

/**
 * Giữ link phát luôn còn hạn: xin link đầu, tự hẹn giờ xin link mới trước `expires_at` (bài dài hơn thời hạn link, mặc định 15
 * phút), và xin lại ngay khi CDN trả 403 (`reportForbidden`). Mọi lần xin đều đi qua MỘT promise đang chạy (không gọi trùng).
 * Không phụ thuộc React/DOM nên test được bằng timer giả.
 */
export class PlaybackLinkManager {
  private readonly o: Required<Pick<LinkManagerOptions, "leadMs" | "minDelayMs" | "retryDelayMs" | "maxRetries" | "maxForbiddenStreak">> & LinkManagerOptions;
  private timer: unknown = null;
  private inflight: Promise<void> | null = null;
  private disposed = false;
  private generation = 0;
  private forbiddenStreak = 0;
  private retries = 0;
  private expiresAt: string | null = null;

  constructor(options: LinkManagerOptions) {
    this.o = { leadMs: 60_000, minDelayMs: 10_000, retryDelayMs: 10_000, maxRetries: 3, maxForbiddenStreak: 3, ...options };
  }

  private now(): number {
    return (this.o.now ?? Date.now)();
  }

  start(): Promise<void> {
    return this.load("initial");
  }

  /** CDN/trình phát báo 403: link hết hạn hoặc bị từ chối → xin lại ngay (không báo lỗi cho học sinh). */
  reportForbidden(): Promise<void> {
    if (this.disposed) return Promise.resolve();
    if (this.inflight) return this.inflight;
    this.forbiddenStreak += 1;
    if (this.forbiddenStreak > this.o.maxForbiddenStreak) {
      this.clear();
      this.o.onError("unavailable", new Error("CDN từ chối link phát nhiều lần liên tiếp"), true);
      return Promise.resolve();
    }
    return this.load("forbidden");
  }

  /** Trình phát đã phát được hình với link hiện tại: xoá bộ đếm 403 liên tiếp. */
  reportPlaying(): void {
    this.forbiddenStreak = 0;
  }

  /** Phiên vừa được khôi phục (học sinh đăng nhập lại và bấm phát): xin link mới và hẹn giờ lại như bình thường. */
  resume(): Promise<void> {
    this.retries = 0;
    return this.load("resume");
  }

  /** Bấm "Thử lại" sau lỗi fatal. */
  restart(): Promise<void> {
    this.forbiddenStreak = 0;
    this.retries = 0;
    this.clear();
    return this.load("initial");
  }

  /** Dừng hẳn (đổi bài, rời trang, mất phiên): huỷ hẹn giờ, bỏ qua kết quả đến muộn. */
  dispose(): void {
    this.disposed = true;
    this.generation += 1;
    this.clear();
  }

  /** Dừng hẹn giờ nhưng chưa huỷ (hộp thoại mất phiên đang mở). */
  pause(): void {
    this.clear();
  }

  private clear(): void {
    if (this.timer !== null) (this.o.clearTimer ?? ((h) => clearTimeout(h as ReturnType<typeof setTimeout>)))(this.timer);
    this.timer = null;
  }

  private schedule(ms: number, reason: LinkReason): void {
    this.clear();
    const set = this.o.setTimer ?? ((fn, delay) => setTimeout(fn, delay));
    this.timer = set(() => {
      this.timer = null;
      void this.load(reason);
    }, ms);
  }

  private load(reason: LinkReason): Promise<void> {
    if (this.disposed) return Promise.resolve();
    if (this.inflight) return this.inflight;
    const gen = this.generation;
    this.clear();
    this.inflight = (async () => {
      try {
        const info = await this.o.fetchPlayback();
        if (this.disposed || gen !== this.generation) return;
        this.retries = 0;
        this.expiresAt = info.expires_at;
        this.o.onInfo(info, reason);
        const delay = refreshDelayMs(info.expires_at, this.now(), this.o.leadMs, this.o.minDelayMs);
        if (delay !== null) this.schedule(delay, "scheduled");
      } catch (err) {
        if (this.disposed || gen !== this.generation) return;
        this.handleFailure(reason, err);
      } finally {
        this.inflight = null;
      }
    })();
    return this.inflight;
  }

  private handleFailure(reason: LinkReason, err: unknown): void {
    const kind = classifyPlaybackError(err);
    // Lần đầu, hoặc xin lại sau 403, hoặc lỗi không thể thử lại: học sinh đã/đang cần link ngay → fatal.
    if (reason === "initial" || reason === "forbidden" || isTerminalPlaybackError(kind)) {
      this.clear();
      this.o.onError(kind, err, true);
      return;
    }
    // Làm mới theo lịch thất bại (mạng chập chờn, 5xx, 429): link cũ có thể còn dùng được → thử lại vài lần trước hạn.
    const stillValid = this.expiresAt !== null && Date.parse(this.expiresAt) > this.now();
    if (this.retries < this.o.maxRetries && stillValid) {
      this.retries += 1;
      this.schedule(this.o.retryDelayMs, "retry");
      return;
    }
    // Hết cách: không báo lỗi fatal (video đang phát được thì cứ phát); khi link thật sự hết hạn, 403 sẽ kích hoạt `reportForbidden`.
    this.o.onError(kind, err, false);
  }
}

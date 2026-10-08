import type { SaveFailure } from "./errors";

export type QuestionSave = "saving" | "saved" | "error" | "rejected";

export interface SaverSnapshot {
  /** Trạng thái từng câu vừa thao tác trong phiên (câu chưa đụng tới không có mặt). */
  questions: Readonly<Record<number, QuestionSave>>;
  /** Số câu có đáp án chưa tới được server (đang chờ gửi, đang gửi hoặc chờ gửi lại). */
  unsaved: number;
  /** Lần gửi gần nhất lỗi tạm thời (mất mạng/429/5xx): đáp án đang được giữ trong bộ nhớ và sẽ gửi lại. */
  offline: boolean;
  /** Server báo lượt đã nộp/hết hạn (409): ngừng lưu, chuyển sang xem kết quả. */
  closed: boolean;
  /** Mất quyền (403) hoặc lượt không còn (404). */
  revoked: boolean;
  /** 401: ngừng gửi lại; đáp án vẫn nằm trong bộ nhớ trang. */
  sessionLost: boolean;
  /** Câu thay đổi gần nhất (để hiện "Đang lưu/Đã lưu" cạnh câu đó). */
  lastQuestionId: number | null;
}

export interface SaverDeps {
  put(questionId: number, optionId: number, opts: { keepalive?: boolean }): Promise<void>;
  classify(err: unknown): SaveFailure;
  /** Gom các lần chọn dồn dập trước khi gửi. */
  debounceMs?: number;
  /** Thời gian chờ trước lần gửi lại thứ n (lần cuối lặp lại). */
  retryDelaysMs?: readonly number[];
}

const DEFAULT_DEBOUNCE_MS = 400;
const DEFAULT_RETRY_MS = [2_000, 4_000, 8_000, 15_000] as const;

/**
 * Lưu đáp án tự động cho một lượt làm bài.
 * - Mỗi câu chỉ có TỐI ĐA một request đang bay; chọn lại khi đang bay thì gửi giá trị mới ngay sau khi xong (không gửi trùng,
 *   không đảo thứ tự: server luôn nhận giá trị cuối cùng).
 * - Lỗi tạm thời → giữ đáp án trong bộ nhớ, gửi lại theo lịch lùi dần, hoặc ngay khi `retryNow()` (sự kiện `online`).
 * - `flushNow()` (trước khi nộp) và `flushKeepalive()` (rời trang) gửi mọi đáp án còn lại.
 */
export class AnswerSaver {
  private readonly desired = new Map<number, number>();
  private readonly confirmed = new Map<number, number>();
  private readonly inflight = new Map<number, Promise<void>>();
  private readonly listeners = new Set<() => void>();
  private debounceTimer: ReturnType<typeof setTimeout> | null = null;
  private retryTimer: ReturnType<typeof setTimeout> | null = null;
  private retryCount = 0;
  private snap: SaverSnapshot = {
    questions: {},
    unsaved: 0,
    offline: false,
    closed: false,
    revoked: false,
    sessionLost: false,
    lastQuestionId: null,
  };

  constructor(
    private readonly deps: SaverDeps,
    initial: Readonly<Record<string, number>> = {},
  ) {
    for (const [q, o] of Object.entries(initial)) this.confirmed.set(Number(q), o);
  }

  subscribe = (fn: () => void): (() => void) => {
    this.listeners.add(fn);
    return () => this.listeners.delete(fn);
  };

  getSnapshot = (): SaverSnapshot => this.snap;

  private update(patch: Partial<SaverSnapshot> & { question?: [number, QuestionSave] }): void {
    const { question, ...rest } = patch;
    const questions = question ? { ...this.snap.questions, [question[0]]: question[1] } : this.snap.questions;
    const unsaved = new Set([...this.desired.keys(), ...this.inflight.keys()]).size;
    this.snap = { ...this.snap, ...rest, questions, unsaved };
    for (const fn of this.listeners) fn();
  }

  private get halted(): boolean {
    return this.snap.closed || this.snap.revoked;
  }

  /** Học sinh chọn (hoặc đổi) đáp án cho một câu. */
  choose(questionId: number, optionId: number): void {
    if (this.halted) return;
    if (this.confirmed.get(questionId) === optionId && !this.inflight.has(questionId)) {
      // Chọn lại đúng giá trị server đã có: không cần gửi.
      this.desired.delete(questionId);
      this.update({ question: [questionId, "saved"], lastQuestionId: questionId });
      return;
    }
    this.desired.set(questionId, optionId);
    this.update({ question: [questionId, "saving"], lastQuestionId: questionId });
    if (this.snap.sessionLost) return; // giữ trong bộ nhớ, không gửi khi phiên đã mất
    this.scheduleDebounce();
  }

  private scheduleDebounce(): void {
    if (this.debounceTimer) clearTimeout(this.debounceTimer);
    this.debounceTimer = setTimeout(() => {
      this.debounceTimer = null;
      void this.sendAll();
    }, this.deps.debounceMs ?? DEFAULT_DEBOUNCE_MS);
  }

  private sendAll(opts: { keepalive?: boolean } = {}): Promise<void> {
    return Promise.all([...this.desired.keys()].map((q) => this.send(q, opts))).then(() => undefined);
  }

  /** Gửi giá trị mong muốn hiện tại của một câu. Không bao giờ reject. */
  private send(questionId: number, opts: { keepalive?: boolean } = {}): Promise<void> {
    const running = this.inflight.get(questionId);
    if (running) return running;
    const optionId = this.desired.get(questionId);
    if (optionId === undefined || this.halted || this.snap.sessionLost) return Promise.resolve();

    const task = (async () => {
      await null; // để `inflight.set` bên dưới chạy trước, kể cả khi `put` ném lỗi đồng bộ
      try {
        await this.deps.put(questionId, optionId, opts);
        this.confirmed.set(questionId, optionId);
        const changed = this.desired.get(questionId) !== optionId;
        if (!changed) this.desired.delete(questionId);
        this.retryCount = 0;
        this.inflight.delete(questionId);
        this.update({ offline: false, question: [questionId, changed ? "saving" : "saved"] });
        if (changed) await this.send(questionId); // người dùng đổi ý khi đang gửi
      } catch (err) {
        this.inflight.delete(questionId);
        this.fail(questionId, this.deps.classify(err));
      }
    })();
    this.inflight.set(questionId, task);
    return task;
  }

  private fail(questionId: number, kind: SaveFailure): void {
    if (kind === "closed") {
      this.desired.clear();
      this.update({ closed: true, offline: false });
    } else if (kind === "revoked") {
      this.desired.clear();
      this.update({ revoked: true, offline: false });
    } else if (kind === "session") {
      this.pause();
    } else if (kind === "reject") {
      this.desired.delete(questionId);
      this.update({ question: [questionId, "rejected"] });
    } else {
      this.update({ offline: true, question: [questionId, "error"] });
      this.scheduleRetry();
    }
  }

  private scheduleRetry(): void {
    if (this.retryTimer || this.halted || this.snap.sessionLost) return;
    const delays = this.deps.retryDelaysMs ?? DEFAULT_RETRY_MS;
    const delay = delays[Math.min(this.retryCount, delays.length - 1)] ?? 15_000;
    this.retryCount++;
    this.retryTimer = setTimeout(() => {
      this.retryTimer = null;
      void this.sendAll();
    }, delay);
  }

  /** Có mạng trở lại: gửi lại ngay thay vì chờ lịch. */
  retryNow(): void {
    if (this.halted || this.snap.sessionLost || this.desired.size === 0) return;
    if (this.retryTimer) clearTimeout(this.retryTimer);
    this.retryTimer = null;
    void this.sendAll();
  }

  /**
   * Gửi MỌI đáp án còn lại và chờ xong (trước khi nộp). `true` nếu không còn gì chưa lưu.
   * Lỗi tạm thời không lặp vô hạn: tối đa vài vòng rồi trả `false` để nơi gọi quyết định (chặn nộp hoặc vẫn nộp khi hết giờ).
   */
  async flushNow(): Promise<boolean> {
    if (this.debounceTimer) clearTimeout(this.debounceTimer);
    this.debounceTimer = null;
    if (this.retryTimer) clearTimeout(this.retryTimer);
    this.retryTimer = null;
    for (let round = 0; round < 3; round++) {
      if (this.desired.size === 0 && this.inflight.size === 0) return true;
      await Promise.all([...this.inflight.values(), this.sendAll()]);
      if (this.halted || this.snap.sessionLost || this.snap.offline) break;
    }
    return this.desired.size === 0 && this.inflight.size === 0;
  }

  /** Rời trang/ẩn tab: gửi đáp án chưa lưu bằng `keepalive` để request sống sót qua việc đóng trang. */
  flushKeepalive(): void {
    if (this.debounceTimer) clearTimeout(this.debounceTimer);
    this.debounceTimer = null;
    if (this.desired.size === 0) return;
    void this.sendAll({ keepalive: true });
  }

  /** Mất phiên: ngừng mọi lần gửi/hẹn giờ (đáp án vẫn nằm trong bộ nhớ). */
  pause(): void {
    if (this.debounceTimer) clearTimeout(this.debounceTimer);
    if (this.retryTimer) clearTimeout(this.retryTimer);
    this.debounceTimer = null;
    this.retryTimer = null;
    if (!this.snap.sessionLost) this.update({ sessionLost: true });
  }

  /** Huỷ các hẹn giờ (rời trang/unmount). Không đóng hẳn: StrictMode dev gắn lại effect với cùng một instance. */
  dispose(): void {
    if (this.debounceTimer) clearTimeout(this.debounceTimer);
    if (this.retryTimer) clearTimeout(this.retryTimer);
    this.debounceTimer = null;
    this.retryTimer = null;
  }
}

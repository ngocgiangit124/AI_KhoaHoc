import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { PlaybackLinkManager, refreshDelayMs, type LinkReason } from "./linkManager";
import type { PlaybackInfo } from "./schemas";

const T0 = Date.parse("2026-10-07T10:00:00Z");
const iso = (offsetMs: number) => new Date(T0 + offsetMs).toISOString();
const MIN = 60_000;

function info(n: number, expiresInMs: number | null = 15 * MIN): PlaybackInfo {
  return { kind: "hls", url: `https://cdn.test/${n}/playlist.m3u8`, expires_at: expiresInMs === null ? null : iso(expiresInMs), resume_at_seconds: 0 };
}

describe("refreshDelayMs (xin link mới TRƯỚC expires_at)", () => {
  it("link 15 phút: xin sau 14 phút (trước hạn 60 giây)", () => {
    expect(refreshDelayMs(iso(15 * MIN), T0)).toBe(14 * MIN);
  });
  it("link ngắn (1 phút): chỉ lấy 1/4 thời hạn làm đệm, vẫn trước hạn", () => {
    expect(refreshDelayMs(iso(MIN), T0, 60_000, 1_000)).toBe(45_000);
  });
  it("đồng hồ máy lệch (đã quá hạn): không xin dồn dập, chờ tối thiểu", () => {
    expect(refreshDelayMs(iso(-5 * MIN), T0)).toBe(10_000);
  });
  it("link ngoài (expires_at null) hoặc sai định dạng: không cần làm mới", () => {
    expect(refreshDelayMs(null, T0)).toBeNull();
    expect(refreshDelayMs("không-phải-ngày", T0)).toBeNull();
  });
});

describe("PlaybackLinkManager", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(T0);
  });
  afterEach(() => vi.useRealTimers());

  function setup(fetchPlayback: () => Promise<PlaybackInfo>, extra: Partial<ConstructorParameters<typeof PlaybackLinkManager>[0]> = {}) {
    const infos: Array<{ info: PlaybackInfo; reason: LinkReason }> = [];
    const errors: Array<{ kind: string; fatal: boolean }> = [];
    const manager = new PlaybackLinkManager({
      fetchPlayback,
      onInfo: (i, reason) => infos.push({ info: i, reason }),
      onError: (kind, _e, fatal) => errors.push({ kind, fatal }),
      ...extra,
    });
    return { manager, infos, errors };
  }

  it("lấy link đầu rồi TỰ làm mới trước khi hết hạn (bài dài hơn 15 phút), không chờ 403", async () => {
    let n = 0;
    const fetchPlayback = vi.fn(async () => info(++n));
    const { manager, infos } = setup(fetchPlayback);
    await manager.start();
    expect(infos.map((i) => i.reason)).toEqual(["initial"]);

    await vi.advanceTimersByTimeAsync(13 * MIN + 59_000); // chưa tới giờ làm mới
    expect(fetchPlayback).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(2_000); // qua mốc 14 phút
    expect(fetchPlayback).toHaveBeenCalledTimes(2);
    expect(infos[1]).toMatchObject({ reason: "scheduled" });
    expect(infos[1]?.info.url).toContain("/2/");

    // Link mới lại có hạn 15 phút tính từ NOW (mock trả cố định T0+15' nên chỉnh đồng hồ): lịch kế tiếp được đặt lại.
    manager.dispose();
  });

  it("làm mới liên tục suốt bài 40 phút bằng link luôn còn hạn", async () => {
    let n = 0;
    const fetchPlayback = vi.fn(async () => {
      n += 1;
      return { ...info(n), expires_at: new Date(Date.now() + 15 * MIN).toISOString() };
    });
    const { manager } = setup(fetchPlayback);
    await manager.start();
    await vi.advanceTimersByTimeAsync(40 * MIN);
    expect(fetchPlayback.mock.calls.length).toBe(3); // 0', 14', 28' (và 42' chưa tới)
    manager.dispose();
  });

  it("CDN báo 403 → xin link mới NGAY và báo link mới (không báo lỗi cho học sinh)", async () => {
    let n = 0;
    const { manager, infos, errors } = setup(async () => info(++n));
    await manager.start();
    await manager.reportForbidden();
    expect(infos.map((i) => i.reason)).toEqual(["initial", "forbidden"]);
    expect(errors).toEqual([]);
    manager.dispose();
  });

  it("nhiều 403 cùng lúc (nhiều đoạn video lỗi một lượt) chỉ gọi API MỘT lần", async () => {
    let n = 0;
    let release!: (i: PlaybackInfo) => void;
    const fetchPlayback = vi.fn(() => (n++ === 0 ? Promise.resolve(info(1)) : new Promise<PlaybackInfo>((r) => (release = r))));
    const { manager } = setup(fetchPlayback);
    await manager.start();
    const a = manager.reportForbidden();
    const b = manager.reportForbidden();
    const c = manager.reportForbidden();
    expect(fetchPlayback).toHaveBeenCalledTimes(2);
    release(info(2));
    await Promise.all([a, b, c]);
    manager.dispose();
  });

  it("403 lặp lại liên tiếp mà vẫn không phát được → dừng, báo lỗi fatal (không xin vô hạn)", async () => {
    let n = 0;
    const fetchPlayback = vi.fn(async () => info(++n));
    const { manager, errors } = setup(fetchPlayback, { maxForbiddenStreak: 2 });
    await manager.start();
    await manager.reportForbidden();
    await manager.reportForbidden();
    expect(errors).toEqual([]);
    await manager.reportForbidden();
    expect(errors).toEqual([{ kind: "unavailable", fatal: true }]);
    expect(fetchPlayback).toHaveBeenCalledTimes(3);
    manager.dispose();
  });

  it("phát được hình (reportPlaying) thì đếm 403 liên tiếp về 0", async () => {
    let n = 0;
    const { manager, errors } = setup(async () => info(++n), { maxForbiddenStreak: 1 });
    await manager.start();
    await manager.reportForbidden();
    manager.reportPlaying();
    await manager.reportForbidden();
    expect(errors).toEqual([]);
    manager.dispose();
  });

  it("link đầu thất bại: lỗi fatal có phân loại (409 → processing, 403 → not_owned, mạng → network)", async () => {
    for (const [err, kind] of [
      [new ApiError(409, { message: "x", code: "VIDEO_NOT_READY" }), "processing"],
      [new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED" }), "not_owned"],
      [new ApiError(404, { message: "x", code: "VIDEO_NOT_AVAILABLE" }), "no_video"],
      [new ApiError(429, { message: "x" }), "throttled"],
      [new ApiError(503, { message: "x", code: "VIDEO_PROVIDER_UNAVAILABLE" }), "unavailable"],
      [new NetworkError(new Error("x")), "network"],
    ] as const) {
      const { manager, errors } = setup(() => Promise.reject(err));
      await manager.start();
      expect(errors).toEqual([{ kind, fatal: true }]);
      manager.dispose();
    }
  });

  it("làm mới theo lịch gặp lỗi mạng: thử lại sau 10 giây khi link cũ còn hạn, không báo lỗi", async () => {
    let n = 0;
    const fetchPlayback = vi.fn(async () => {
      n += 1;
      if (n === 2) throw new NetworkError(new Error("x"));
      return info(n);
    });
    const { manager, infos, errors } = setup(fetchPlayback);
    await manager.start();
    await vi.advanceTimersByTimeAsync(14 * MIN + 1_000); // lần theo lịch: lỗi mạng
    expect(infos).toHaveLength(1);
    expect(errors).toEqual([]);
    await vi.advanceTimersByTimeAsync(10_000); // thử lại thành công
    expect(infos.map((i) => i.reason)).toEqual(["initial", "retry"]);
    manager.dispose();
  });

  it("làm mới theo lịch gặp 403 COURSE_NOT_OWNED (thu hồi giữa chừng) → fatal", async () => {
    let n = 0;
    const { manager, errors } = setup(async () => {
      if (++n === 2) throw new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED" });
      return info(n);
    });
    await manager.start();
    await vi.advanceTimersByTimeAsync(14 * MIN + 1_000);
    expect(errors).toEqual([{ kind: "not_owned", fatal: true }]);
    manager.dispose();
  });

  it("link ngoài (embed, expires_at null): không hẹn giờ làm mới", async () => {
    const fetchPlayback = vi.fn(async () => info(1, null));
    const { manager } = setup(fetchPlayback);
    await manager.start();
    await vi.advanceTimersByTimeAsync(60 * MIN);
    expect(fetchPlayback).toHaveBeenCalledTimes(1);
    manager.dispose();
  });

  it("dispose/pause: huỷ hẹn giờ, kết quả đến muộn bị bỏ", async () => {
    let n = 0;
    const fetchPlayback = vi.fn(async () => info(++n));
    const { manager, infos } = setup(fetchPlayback);
    await manager.start();
    manager.pause();
    await vi.advanceTimersByTimeAsync(30 * MIN);
    expect(fetchPlayback).toHaveBeenCalledTimes(1);

    const slow = setup(() => new Promise<PlaybackInfo>((r) => setTimeout(() => r(info(9)), 1_000)));
    const started = slow.manager.start();
    slow.manager.dispose();
    await vi.advanceTimersByTimeAsync(2_000);
    await started;
    expect(slow.infos).toEqual([]);
    expect(infos).toHaveLength(1);
  });

  it("restart sau lỗi fatal: xin lại từ đầu", async () => {
    let n = 0;
    const { manager, infos, errors } = setup(async () => {
      if (++n === 1) throw new NetworkError(new Error("x"));
      return info(n);
    });
    await manager.start();
    expect(errors).toHaveLength(1);
    await manager.restart();
    expect(infos.map((i) => i.reason)).toEqual(["initial"]);
    manager.dispose();
  });
});

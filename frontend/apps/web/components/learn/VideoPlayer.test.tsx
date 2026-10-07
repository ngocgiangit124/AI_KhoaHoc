import { act, fireEvent, render, screen } from "@testing-library/react";
import { ApiError, FORCED_LOGOUT_EVENT, LOGIN_REQUIRED_EVENT } from "@vitaminvui/api-client";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { EngineCallbacks } from "@/lib/learn/hlsEngine";
import type { PlaybackInfo } from "@/lib/learn/schemas";

const api = vi.hoisted(() => ({ fetchPlayback: vi.fn(), sendHeartbeat: vi.fn(), warmCsrf: vi.fn() }));
vi.mock("@/lib/learn/api", () => api);

const engine = vi.hoisted(() => ({
  callbacks: null as EngineCallbacks | null,
  instance: { setUrl: vi.fn(), destroy: vi.fn() },
  create: vi.fn(),
}));
vi.mock("@/lib/learn/hlsEngine", () => ({
  createHlsEngine: (...args: unknown[]) => {
    engine.create(...args);
    engine.callbacks = args[3] as EngineCallbacks;
    return Promise.resolve(engine.instance);
  },
}));

import { VideoPlayer } from "./VideoPlayer";

const MIN = 60_000;
const hls = (n: number): PlaybackInfo => ({
  kind: "hls",
  url: `https://video.test/videolab/cdn/tok${n}/1/g/playlist.m3u8`,
  expires_at: new Date(Date.now() + 15 * MIN).toISOString(),
  resume_at_seconds: 42,
});

/** jsdom không cài đặt play/pause/paused của <video>: dựng mô hình tối thiểu. */
function stubMedia() {
  const pause = vi.fn();
  let paused = true;
  Object.defineProperty(HTMLMediaElement.prototype, "paused", { configurable: true, get: () => paused });
  HTMLMediaElement.prototype.pause = function pauseStub() {
    pause();
    paused = true;
    this.dispatchEvent(new Event("pause"));
  };
  HTMLMediaElement.prototype.play = function playStub() {
    paused = false;
    this.dispatchEvent(new Event("play"));
    return Promise.resolve();
  };
  HTMLMediaElement.prototype.load = () => undefined;
  return { pause, setPaused: (v: boolean) => (paused = v) };
}

async function mount(props: Partial<React.ComponentProps<typeof VideoPlayer>> = {}) {
  const view = render(<VideoPlayer lessonId={7} title="Bài 1" durationSeconds={600} canTrack videoReady {...props} />);
  await act(async () => {
    await vi.advanceTimersByTimeAsync(0);
  });
  return view;
}

function tick(video: HTMLVideoElement, time: number) {
  Object.defineProperty(video, "currentTime", { configurable: true, writable: true, value: time });
  video.dispatchEvent(new Event("timeupdate"));
}

describe("VideoPlayer", () => {
  let media: ReturnType<typeof stubMedia>;
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(Date.parse("2026-10-07T10:00:00Z"));
    api.fetchPlayback.mockReset().mockImplementation(async () => hls(1));
    api.warmCsrf.mockReset().mockResolvedValue(undefined);
    api.sendHeartbeat.mockReset().mockResolvedValue({ status: "in_progress", completed: false, course_percent: 10 });
    engine.create.mockReset();
    engine.instance.setUrl.mockReset();
    engine.instance.destroy.mockReset();
    media = stubMedia();
  });
  afterEach(() => vi.useRealTimers());

  it("xin link từ trình duyệt đúng bài và gắn trình phát với vị trí học tiếp", async () => {
    await mount();
    expect(api.fetchPlayback).toHaveBeenCalledWith(7);
    expect(engine.create).toHaveBeenCalledTimes(1);
    const [, url, startAt] = engine.create.mock.calls[0] as [unknown, string, number];
    expect(url).toContain("tok1");
    expect(startAt).toBe(42);
  });

  it("bài chưa có video sẵn sàng: hiện 'đang xử lý', KHÔNG gọi playback (tránh 409)", async () => {
    await mount({ videoReady: false });
    expect(screen.getByText("Video bài này đang được xử lý")).toBeInTheDocument();
    expect(api.fetchPlayback).not.toHaveBeenCalled();
  });

  it("làm mới link trước expires_at và đổi nguồn của trình phát đang chạy (không chờ 403)", async () => {
    let n = 0;
    api.fetchPlayback.mockImplementation(async () => hls(++n));
    await mount();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(14 * MIN + 1_000);
    });
    expect(api.fetchPlayback).toHaveBeenCalledTimes(2);
    expect(engine.instance.setUrl).toHaveBeenCalledTimes(1);
    expect(engine.instance.setUrl.mock.calls[0]?.[0]).toContain("tok2");
    expect(engine.instance.setUrl.mock.calls[0]?.[1]).toBe(false); // theo lịch: KHÔNG đổi nguồn lúc đang phát (tránh giật)
  });

  it("CDN trả 403 (hết hạn) → xin link mới và đổi nguồn, KHÔNG hiện lỗi", async () => {
    let n = 0;
    api.fetchPlayback.mockImplementation(async () => hls(++n));
    await mount();
    await act(async () => {
      engine.callbacks?.onForbidden();
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(api.fetchPlayback).toHaveBeenCalledTimes(2);
    expect(engine.instance.setUrl.mock.calls[0]?.[0]).toContain("tok2");
    expect(engine.instance.setUrl.mock.calls[0]?.[1]).toBe(true); // 403: đổi ngay
    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("API trả 403 COURSE_NOT_OWNED → chặn, không thử lại", async () => {
    api.fetchPlayback.mockRejectedValue(new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED" }));
    const onRevoked = vi.fn();
    await mount({ onRevoked });
    expect(screen.getByRole("alert")).toHaveTextContent("chưa sở hữu");
    expect(onRevoked).toHaveBeenCalledWith(null);
    expect(engine.create).not.toHaveBeenCalled();
  });

  it("lỗi tải: hiện thông báo AC5 và nút Thử lại xin link lại", async () => {
    api.fetchPlayback.mockRejectedValueOnce(new ApiError(503, { message: "x", code: "VIDEO_PROVIDER_UNAVAILABLE" }));
    await mount();
    expect(screen.getByRole("alert")).toHaveTextContent("Không tải được video, vui lòng thử lại.");
    fireEvent.click(screen.getByRole("button", { name: "Thử lại" }));
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(api.fetchPlayback).toHaveBeenCalledTimes(2);
    expect(engine.create).toHaveBeenCalledTimes(1);
  });

  it("heartbeat mỗi ~20 giây khi đang phát, body là số nguyên", async () => {
    const { container } = await mount();
    const video = container.querySelector("video")!;
    tick(video, 42);
    await act(async () => void video.play());
    for (let t = 42.5; t <= 62; t += 0.5) tick(video, t);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(20_000);
    });
    expect(api.sendHeartbeat).toHaveBeenCalledTimes(1);
    const [lessonId, body] = api.sendHeartbeat.mock.calls[0] as [number, { position_seconds: number; watched_delta_seconds: number }];
    expect(lessonId).toBe(7);
    expect(body.position_seconds).toBe(62);
    expect(body.watched_delta_seconds).toBe(20);
  });

  it("heartbeat thành công → báo tiến độ lên trang (toast/mục lục)", async () => {
    const onProgress = vi.fn();
    api.sendHeartbeat.mockResolvedValue({ status: "completed", completed: true, course_percent: 50 });
    const { container } = await mount({ onProgress });
    const video = container.querySelector("video")!;
    tick(video, 0);
    await act(async () => void video.play());
    for (let t = 0.5; t <= 20; t += 0.5) tick(video, t);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(20_000);
    });
    expect(onProgress).toHaveBeenCalledWith({ status: "completed", completed: true, course_percent: 50 });
  });

  it("người xem preview chưa sở hữu (can_track=false): KHÔNG gọi heartbeat", async () => {
    const { container } = await mount({ canTrack: false });
    const video = container.querySelector("video")!;
    tick(video, 0);
    await act(async () => void video.play());
    for (let t = 0.5; t <= 30; t += 0.5) tick(video, t);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(40_000);
    });
    expect(api.sendHeartbeat).not.toHaveBeenCalled();
  });

  it("heartbeat lỗi mạng: giây chưa gửi được gửi bù lần sau", async () => {
    api.sendHeartbeat.mockRejectedValueOnce(new ApiError(500, { message: "x" }));
    const { container } = await mount();
    const video = container.querySelector("video")!;
    tick(video, 0);
    await act(async () => void video.play());
    for (let t = 0.5; t <= 20; t += 0.5) tick(video, t);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(20_000);
    });
    for (let t = 20.5; t <= 40; t += 0.5) tick(video, t);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(20_000);
    });
    expect(api.sendHeartbeat).toHaveBeenCalledTimes(2);
    expect((api.sendHeartbeat.mock.calls[1] as [number, { watched_delta_seconds: number }])[1].watched_delta_seconds).toBe(40);
  });

  it("heartbeat 403 (thu hồi giữa phiên): dừng video và chặn", async () => {
    api.sendHeartbeat.mockRejectedValue(new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED" }));
    const onRevoked = vi.fn();
    const { container } = await mount({ onRevoked });
    const video = container.querySelector("video")!;
    tick(video, 0);
    await act(async () => void video.play());
    for (let t = 0.5; t <= 20; t += 0.5) tick(video, t);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(20_000);
    });
    expect(media.pause).toHaveBeenCalled();
    expect(onRevoked).toHaveBeenCalled();
  });

  it.each([
    [FORCED_LOGOUT_EVENT, "SESSION_REPLACED"],
    [LOGIN_REQUIRED_EVENT, "SESSION_REVOKED"],
  ])("hộp thoại mất phiên (%s) → pause() video, ngừng heartbeat và làm mới link", async (eventName, code) => {
    const { container } = await mount();
    const video = container.querySelector("video")!;
    tick(video, 0);
    await act(async () => void video.play());
    for (let t = 0.5; t <= 10; t += 0.5) tick(video, t);
    media.pause.mockClear();

    act(() => void window.dispatchEvent(new CustomEvent(eventName, { detail: { code } })));
    expect(media.pause).toHaveBeenCalledTimes(1);

    media.setPaused(false); // dù video vẫn bị bật lại, heartbeat/làm mới link đã dừng
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30 * MIN);
    });
    expect(api.sendHeartbeat).not.toHaveBeenCalled();
    expect(api.fetchPlayback).toHaveBeenCalledTimes(1);
  });

  it("đổi bài/rời trang: gửi nốt tiến độ chưa gửi (force + keepalive) của bài cũ", async () => {
    const { container, unmount } = await mount();
    const video = container.querySelector("video")!;
    tick(video, 0);
    await act(async () => void video.play());
    for (let t = 0.5; t <= 8; t += 0.5) tick(video, t);
    expect(api.sendHeartbeat).not.toHaveBeenCalled();
    unmount();
    expect(api.sendHeartbeat).toHaveBeenCalledTimes(1);
    const [lessonId, body, opts] = api.sendHeartbeat.mock.calls[0] as [number, { watched_delta_seconds: number }, { keepalive?: boolean }];
    expect(lessonId).toBe(7);
    expect(body.watched_delta_seconds).toBe(8);
    expect(opts.keepalive).toBe(true);
  });

  it("rời trang cứng: `pagehide` (visibilityState vẫn 'visible') gửi heartbeat cuối bằng keepalive; CSRF token lấy sẵn từ lúc mở bài", async () => {
    const { container } = await mount();
    expect(api.warmCsrf).toHaveBeenCalledTimes(1);
    const video = container.querySelector("video")!;
    tick(video, 0);
    await act(async () => void video.play());
    for (let t = 0.5; t <= 9; t += 0.5) tick(video, t);
    expect(document.visibilityState).toBe("visible");
    act(() => void window.dispatchEvent(new Event("beforeunload")));
    expect(api.sendHeartbeat).toHaveBeenCalledTimes(1); // đóng tab: beforeunload cũng flush
    const [, body, opts] = api.sendHeartbeat.mock.calls[0] as [number, { position_seconds: number; watched_delta_seconds: number }, { keepalive?: boolean }];
    expect(body).toEqual({ position_seconds: 9, watched_delta_seconds: 9 });
    expect(opts.keepalive).toBe(true);
  });

  it("pagehide + beforeunload + visibilitychange bắn liền nhau chỉ gửi MỘT heartbeat (throttle 6/phút/bài)", async () => {
    const { container } = await mount();
    const video = container.querySelector("video")!;
    tick(video, 0);
    await act(async () => void video.play());
    for (let t = 0.5; t <= 9; t += 0.5) tick(video, t);
    act(() => {
      window.dispatchEvent(new Event("pagehide"));
      window.dispatchEvent(new Event("beforeunload"));
      document.dispatchEvent(new Event("visibilitychange"));
    });
    expect(api.sendHeartbeat).toHaveBeenCalledTimes(1);
  });

  it("người xem preview (can_track=false) không lấy sẵn CSRF và không gửi gì khi pagehide", async () => {
    const { container } = await mount({ canTrack: false });
    expect(api.warmCsrf).not.toHaveBeenCalled();
    const video = container.querySelector("video")!;
    tick(video, 0);
    await act(async () => void video.play());
    for (let t = 0.5; t <= 9; t += 0.5) tick(video, t);
    act(() => void window.dispatchEvent(new Event("pagehide")));
    expect(api.sendHeartbeat).not.toHaveBeenCalled();
  });

  it("heartbeat 403 có errors.course → onRevoked nhận slug để trang hiện nút 'Xem khóa học'", async () => {
    api.sendHeartbeat.mockRejectedValue(new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED", errors: { course: { id: 1, slug: "hinh-hoc-9", title: "H9" } } as never }));
    const onRevoked = vi.fn();
    const { container } = await mount({ onRevoked });
    const video = container.querySelector("video")!;
    tick(video, 0);
    await act(async () => void video.play());
    for (let t = 0.5; t <= 20; t += 0.5) tick(video, t);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(20_000);
    });
    expect(onRevoked).toHaveBeenCalledWith({ slug: "hinh-hoc-9", title: "H9" });
  });

  it("mở bài rồi rời đi mà chưa phát: không gửi heartbeat", async () => {
    const { unmount } = await mount();
    unmount();
    expect(api.sendHeartbeat).not.toHaveBeenCalled();
  });

  it("đăng nhập lại sau hộp thoại mất phiên rồi bấm phát: heartbeat chạy lại và xin link mới", async () => {
    const { container } = await mount();
    const video = container.querySelector("video")!;
    tick(video, 0);
    await act(async () => void video.play());
    act(() => void window.dispatchEvent(new CustomEvent(LOGIN_REQUIRED_EVENT, { detail: { code: "SESSION_REVOKED" } })));
    expect(api.fetchPlayback).toHaveBeenCalledTimes(1);

    await act(async () => {
      await video.play(); // người dùng bấm phát lại
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(api.fetchPlayback).toHaveBeenCalledTimes(2);
    for (let t = 0.5; t <= 20; t += 0.5) tick(video, t);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(20_000);
    });
    expect(api.sendHeartbeat).toHaveBeenCalled();
  });

  it("toàn màn hình: không có Fullscreen API (iPhone) thì dùng webkitEnterFullscreen của <video>", async () => {
    const { container } = await mount();
    act(() => void engine.callbacks?.onReady());
    const video = container.querySelector("video") as HTMLVideoElement & { webkitEnterFullscreen?: () => void };
    video.webkitEnterFullscreen = vi.fn();
    Object.defineProperty(document, "fullscreenEnabled", { configurable: true, value: false });
    fireEvent.click(screen.getByRole("button", { name: "Toàn màn hình" }));
    expect(video.webkitEnterFullscreen).toHaveBeenCalled();
  });

  it("nút tắt tiếng có nhãn cố định + aria-pressed; thanh tua cao 44px (h-11)", async () => {
    await mount();
    act(() => void engine.callbacks?.onReady());
    const mute = screen.getByRole("button", { name: "Tắt tiếng" });
    expect(mute).toHaveAttribute("aria-pressed", "false");
    expect(mute.className).not.toContain("hidden");
    expect(screen.getByLabelText("Tua video").className).toContain("h-11");
  });

  it("link ngoài: iframe có sandbox, không có heartbeat; URL host lạ bị từ chối", async () => {
    api.fetchPlayback.mockResolvedValue({ kind: "embed", url: "https://www.youtube-nocookie.com/embed/abc", expires_at: null, resume_at_seconds: 0 });
    const { container, unmount } = await mount();
    const frame = container.querySelector("iframe")!;
    expect(frame.getAttribute("sandbox")).toBe("allow-scripts allow-same-origin allow-presentation");
    expect(frame.getAttribute("src")).toBe("https://www.youtube-nocookie.com/embed/abc");
    expect(frame.getAttribute("referrerpolicy")).toBe("strict-origin-when-cross-origin");
    unmount();

    api.fetchPlayback.mockResolvedValue({ kind: "embed", url: "https://evil.example/x", expires_at: null, resume_at_seconds: 0 });
    const second = await mount();
    expect(second.container.querySelector("iframe")).toBeNull();
    expect(screen.getByRole("alert")).toBeInTheDocument();
  });

  it("rời trang: dừng hẹn giờ và huỷ trình phát", async () => {
    const { unmount } = await mount();
    unmount();
    expect(engine.instance.destroy).toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(30 * MIN);
    expect(api.fetchPlayback).toHaveBeenCalledTimes(1);
  });
});

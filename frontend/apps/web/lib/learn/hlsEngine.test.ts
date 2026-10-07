import { beforeEach, describe, expect, it, vi } from "vitest";

/** hls.js giả: ghi lại các instance để kiểm tra khi nào bị dựng lại. */
const hlsMock = vi.hoisted(() => {
  type Handler = (event: string, data: unknown) => void;
  class FakeHls {
    static supported = true;
    static instances: FakeHls[] = [];
    static Events = { MANIFEST_PARSED: "mp", FRAG_BUFFERED: "fb", ERROR: "err" };
    static ErrorTypes = { NETWORK_ERROR: "net", MEDIA_ERROR: "media" };
    static isSupported = () => FakeHls.supported;
    handlers = new Map<string, Handler>();
    destroyed = false;
    loaded: string | null = null;
    stopLoad = vi.fn();
    startLoad = vi.fn();
    recoverMediaError = vi.fn();
    attachMedia = vi.fn();
    constructor(public config: { startPosition: number }) {
      FakeHls.instances.push(this);
    }
    on(name: string, fn: Handler) {
      this.handlers.set(name, fn);
    }
    emit(name: string, data: unknown = {}) {
      this.handlers.get(name)?.(name, data);
    }
    loadSource(url: string) {
      this.loaded = url;
    }
    destroy() {
      this.destroyed = true;
    }
  }
  return FakeHls;
});
vi.mock("hls.js", () => ({ default: hlsMock }));

import { createHlsEngine, type EngineCallbacks } from "./hlsEngine";

function makeVideo(playing: boolean, native = false) {
  const video = document.createElement("video");
  let paused = !playing;
  Object.defineProperty(video, "paused", { configurable: true, get: () => paused });
  Object.defineProperty(video, "currentTime", { configurable: true, writable: true, value: 30 });
  video.canPlayType = () => (native ? "maybe" : "");
  video.load = vi.fn();
  video.play = vi.fn(() => Promise.resolve());
  return { video, setPaused: (v: boolean) => (paused = v) };
}

function callbacks(): EngineCallbacks {
  return { onForbidden: vi.fn(), onFatal: vi.fn(), onReady: vi.fn(), onPlaying: vi.fn() };
}

beforeEach(() => {
  hlsMock.instances.length = 0;
  hlsMock.supported = true;
});

describe("HLS gốc (Safari/iPhone không có MSE)", () => {
  it("báo SẴN SÀNG khi có metadata/canplay (không chờ `playing`: chưa bấm phát thì không bao giờ có)", async () => {
    hlsMock.supported = false;
    const { video } = makeVideo(false, true);
    const cb = callbacks();
    await createHlsEngine(video, "https://cdn.test/a.m3u8", 12, cb);
    expect(video.src).toContain("a.m3u8");
    expect(cb.onReady).not.toHaveBeenCalled();
    video.dispatchEvent(new Event("loadedmetadata"));
    expect(cb.onReady).toHaveBeenCalledTimes(1);
    expect(video.currentTime).toBe(12); // học tiếp đúng vị trí
    video.dispatchEvent(new Event("canplay"));
    expect(cb.onReady).toHaveBeenCalledTimes(2);
    expect(cb.onPlaying).not.toHaveBeenCalled();
    video.dispatchEvent(new Event("playing"));
    expect(cb.onPlaying).toHaveBeenCalledTimes(1);
  });

  it("lỗi tải → xin link mới; không có hls.js lẫn HLS gốc → ném lỗi", async () => {
    hlsMock.supported = false;
    const { video } = makeVideo(false, true);
    const cb = callbacks();
    await createHlsEngine(video, "https://cdn.test/a.m3u8", 0, cb);
    video.dispatchEvent(new Event("error"));
    expect(cb.onForbidden).toHaveBeenCalled();

    const none = makeVideo(false, false);
    await expect(createHlsEngine(none.video, "https://cdn.test/a.m3u8", 0, callbacks())).rejects.toThrow();
  });
});

describe("hls.js — lỗi tải playlist (mạng chập chờn lúc mở bài)", () => {
  const manifestError = { fatal: true, type: "net", details: "manifestLoadError" };

  it("nạp lại playlist (loadSource) với backoff 1s/2s/4s/8s rồi mới báo lỗi fatal + Thử lại; KHÔNG dựng lại instance", async () => {
    vi.useFakeTimers();
    try {
      const { video } = makeVideo(false);
      const cb = callbacks();
      await createHlsEngine(video, "https://cdn.test/a.m3u8", 0, cb);
      const h = hlsMock.instances[0]!;
      const loads = vi.spyOn(h, "loadSource");
      h.emit("err", manifestError);
      expect(loads).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(1_000);
      expect(loads).toHaveBeenCalledTimes(1);
      h.emit("err", { ...manifestError, details: "levelLoadError" });
      await vi.advanceTimersByTimeAsync(2_000);
      h.emit("err", manifestError);
      await vi.advanceTimersByTimeAsync(4_000);
      h.emit("err", manifestError);
      await vi.advanceTimersByTimeAsync(8_000);
      expect(loads).toHaveBeenCalledTimes(4);
      expect(cb.onFatal).not.toHaveBeenCalled();
      h.emit("err", manifestError); // hết lượt
      expect(cb.onFatal).toHaveBeenCalledTimes(1);
      expect(hlsMock.instances).toHaveLength(1);
      expect(h.startLoad).not.toHaveBeenCalled(); // startLoad không nạp lại playlist: không dùng cho lỗi này
    } finally {
      vi.useRealTimers();
    }
  });

  it("phục hồi sau lần thử lại (đoạn đầu nạp được) thì bộ đếm về 0; destroy huỷ hẹn giờ", async () => {
    vi.useFakeTimers();
    try {
      const { video } = makeVideo(false);
      const cb = callbacks();
      const engine = await createHlsEngine(video, "https://cdn.test/a.m3u8", 0, cb);
      const h = hlsMock.instances[0]!;
      const loads = vi.spyOn(h, "loadSource");
      for (let i = 0; i < 3; i++) {
        h.emit("err", manifestError);
        await vi.advanceTimersByTimeAsync(10_000);
      }
      h.emit("fb");
      for (let i = 0; i < 4; i++) {
        h.emit("err", manifestError);
        await vi.advanceTimersByTimeAsync(10_000);
      }
      expect(cb.onFatal).not.toHaveBeenCalled();
      expect(loads).toHaveBeenCalledTimes(7);
      h.emit("err", manifestError);
      engine.destroy();
      await vi.advanceTimersByTimeAsync(10_000);
      expect(loads).toHaveBeenCalledTimes(7);
    } finally {
      vi.useRealTimers();
    }
  });
});

describe("hls.js — đổi link", () => {
  it("làm mới theo lịch lúc ĐANG PHÁT: không dựng lại (không giật), đổi khi video tạm dừng", async () => {
    const { video, setPaused } = makeVideo(true);
    const engine = await createHlsEngine(video, "https://cdn.test/old.m3u8", 0, callbacks());
    expect(hlsMock.instances).toHaveLength(1);
    engine.setUrl("https://cdn.test/new.m3u8", false);
    expect(hlsMock.instances).toHaveLength(1);
    setPaused(true);
    video.dispatchEvent(new Event("pause"));
    expect(hlsMock.instances).toHaveLength(2);
    expect(hlsMock.instances[1]?.loaded).toBe("https://cdn.test/new.m3u8");
    expect(hlsMock.instances[1]?.config.startPosition).toBe(30);
    expect(hlsMock.instances[0]?.destroyed).toBe(true);
  });

  it("đang phát mà CDN trả 403 sau khi đã cất link mới: dùng luôn link đã cất, KHÔNG gọi onForbidden", async () => {
    const { video } = makeVideo(true);
    const cb = callbacks();
    const engine = await createHlsEngine(video, "https://cdn.test/old.m3u8", 0, cb);
    engine.setUrl("https://cdn.test/new.m3u8", false);
    hlsMock.instances[0]?.emit("err", { response: { code: 403 }, fatal: false });
    expect(cb.onForbidden).not.toHaveBeenCalled();
    expect(hlsMock.instances).toHaveLength(2);
    expect(hlsMock.instances[1]?.loaded).toBe("https://cdn.test/new.m3u8");
  });

  it("403 mà chưa có link cất: dừng tải và gọi onForbidden; setUrl(immediate) dựng lại ngay giữ vị trí + đang phát", async () => {
    const { video } = makeVideo(true);
    const cb = callbacks();
    const engine = await createHlsEngine(video, "https://cdn.test/old.m3u8", 0, cb);
    hlsMock.instances[0]?.emit("err", { response: { code: 403 }, fatal: false });
    expect(hlsMock.instances[0]?.stopLoad).toHaveBeenCalled();
    expect(cb.onForbidden).toHaveBeenCalledTimes(1);
    engine.setUrl("https://cdn.test/fresh.m3u8", true);
    expect(hlsMock.instances).toHaveLength(2);
    expect(hlsMock.instances[1]?.config.startPosition).toBe(30);
    hlsMock.instances[1]?.emit("mp");
    expect(video.play).toHaveBeenCalled();
  });

  it("FRAG_BUFFERED → onReady; lỗi mạng fatal thử lại tối đa 3 lần rồi onFatal", async () => {
    const { video } = makeVideo(false);
    const cb = callbacks();
    await createHlsEngine(video, "https://cdn.test/a.m3u8", 0, cb);
    const h = hlsMock.instances[0]!;
    h.emit("fb");
    expect(cb.onReady).toHaveBeenCalled();
    for (let i = 0; i < 3; i++) h.emit("err", { fatal: true, type: "net" });
    expect(h.startLoad).toHaveBeenCalledTimes(3);
    expect(cb.onFatal).not.toHaveBeenCalled();
    h.emit("err", { fatal: true, type: "net" });
    expect(cb.onFatal).toHaveBeenCalled();
  });
});

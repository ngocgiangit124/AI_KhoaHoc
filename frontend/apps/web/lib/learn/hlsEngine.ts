import type Hls from "hls.js";

export interface EngineCallbacks {
  /** CDN từ chối link (403, hoặc lỗi tải không phân biệt được ở trình phát gốc) và chưa có link dự phòng: cần xin link mới. */
  onForbidden: () => void;
  /** Lỗi không cứu được: hiện "Không tải được video" + Thử lại. */
  onFatal: () => void;
  /** Đã nạp được dữ liệu từ link hiện tại (hls.js: đoạn đầu; HLS gốc: metadata): bỏ lớp phủ "đang tải", hiện nút phát. */
  onReady: () => void;
  /** Video thật sự đang phát hình/tiếng. */
  onPlaying: () => void;
}

export interface HlsEngine {
  /**
   * Đưa link mới cho trình phát. `immediate=true` (CDN vừa 403): dựng lại ngay, giữ vị trí + trạng thái phát/dừng.
   * `immediate=false` (làm mới theo lịch): token nằm trong path nên đoạn đã nạp vẫn dùng token cũ tới hạn; để KHÔNG giật hình,
   * chỉ cất link mới và đổi khi video tạm dừng, hoặc ngay khi CDN báo 403 (dùng luôn link đã cất, không phải xin lại).
   */
  setUrl: (url: string, immediate?: boolean) => void;
  destroy: () => void;
}

const NATIVE_TYPE = "application/vnd.apple.mpegurl";

/** Lỗi tải playlist (master/level): `startLoad()` không nạp lại được vì chưa có manifest → phải `loadSource(url)` lại. */
const PLAYLIST_ERRORS = new Set(["manifestLoadError", "manifestLoadTimeOut", "levelLoadError", "levelLoadTimeOut"]);
const PLAYLIST_RETRIES = 4;
const PLAYLIST_BACKOFF_MS = 1000; // 1s, 2s, 4s, 8s (tổng ~15s, đủ qua cơn chập chờn mạng 10 giây)

/**
 * Một trình phát cho cả VideoLab và Bunny (cùng là HLS, khác host/định dạng token). `hls.js` được nạp động: chỉ trang học tải nó.
 * Safari/iOS không có MSE đầy đủ thì dùng HLS gốc của trình duyệt (`video.src`).
 */
export async function createHlsEngine(video: HTMLVideoElement, url: string, startAt: number, cb: EngineCallbacks): Promise<HlsEngine> {
  const { default: HlsLib } = await import("hls.js");
  if (HlsLib.isSupported()) return hlsJsEngine(HlsLib, video, url, startAt, cb);
  if (video.canPlayType(NATIVE_TYPE)) return nativeEngine(video, url, startAt, cb);
  throw new Error("Trình duyệt không hỗ trợ phát HLS");
}

function isPlaying(video: HTMLVideoElement): boolean {
  return !video.paused && !video.ended;
}

function hlsJsEngine(HlsLib: typeof Hls, video: HTMLVideoElement, firstUrl: string, firstStart: number, cb: EngineCallbacks): HlsEngine {
  let hls: Hls | null = null;
  let destroyed = false;
  let pendingUrl: string | null = null;
  let retryTimer: ReturnType<typeof setTimeout> | undefined;

  function build(url: string, startAt: number, autoplay: boolean) {
    hls?.destroy();
    clearTimeout(retryTimer);
    pendingUrl = null;
    let networkRetries = 0;
    let mediaRecoveries = 0;
    let playlistRetries = 0;
    const instance = new HlsLib({ startPosition: startAt > 0 ? startAt : -1, enableWorker: true });
    hls = instance;
    instance.on(HlsLib.Events.MANIFEST_PARSED, () => {
      if (autoplay) void video.play().catch(() => undefined);
    });
    instance.on(HlsLib.Events.FRAG_BUFFERED, () => {
      playlistRetries = 0;
      cb.onReady();
    });
    instance.on(HlsLib.Events.ERROR, (_event, data) => {
      if (destroyed || hls !== instance) return;
      const code = data.response?.code;
      if (code === 403 || code === 401) {
        // Dừng tải ngay (hls.js sẽ không tự thoát vòng lặp thử lại). Có link đã cất từ lần làm mới theo lịch thì dùng luôn.
        instance.stopLoad();
        if (pendingUrl) build(pendingUrl, video.currentTime, isPlaying(video));
        else cb.onForbidden();
        return;
      }
      if (!data.fatal) return;
      if (data.type === HlsLib.ErrorTypes.NETWORK_ERROR && PLAYLIST_ERRORS.has(String(data.details))) {
        // Không dựng lại instance (giữ MediaSource), chỉ nạp lại playlist sau một khoảng chờ tăng dần; hết lượt → báo lỗi + Thử lại.
        if (playlistRetries >= PLAYLIST_RETRIES) {
          cb.onFatal();
          return;
        }
        const wait = PLAYLIST_BACKOFF_MS * 2 ** playlistRetries;
        playlistRetries += 1;
        retryTimer = setTimeout(() => {
          if (!destroyed && hls === instance) instance.loadSource(pendingUrl ?? url);
        }, wait);
        return;
      }
      if (data.type === HlsLib.ErrorTypes.NETWORK_ERROR && networkRetries < 3) {
        networkRetries += 1;
        instance.startLoad();
      } else if (data.type === HlsLib.ErrorTypes.MEDIA_ERROR && mediaRecoveries < 2) {
        mediaRecoveries += 1;
        instance.recoverMediaError();
      } else {
        cb.onFatal();
      }
    });
    instance.attachMedia(video);
    instance.loadSource(url);
  }

  const onPlaying = () => cb.onPlaying();
  // Đang chờ đổi link theo lịch: học sinh tạm dừng thì đổi ngay (không ai thấy gián đoạn).
  const onPause = () => {
    if (pendingUrl && !destroyed) build(pendingUrl, video.currentTime, false);
  };
  video.addEventListener("playing", onPlaying);
  video.addEventListener("pause", onPause);

  build(firstUrl, firstStart, false);
  return {
    setUrl(url, immediate = true) {
      if (destroyed) return;
      if (!immediate && isPlaying(video)) {
        pendingUrl = url;
        return;
      }
      // Dựng lại thay vì `loadSource` trên instance cũ: tách–gắn lại MediaSource làm currentTime về 0.
      build(url, video.currentTime, isPlaying(video));
    },
    destroy() {
      destroyed = true;
      video.removeEventListener("playing", onPlaying);
      video.removeEventListener("pause", onPause);
      clearTimeout(retryTimer);
      hls?.destroy();
      hls = null;
    },
  };
}

function nativeEngine(video: HTMLVideoElement, firstUrl: string, firstStart: number, cb: EngineCallbacks): HlsEngine {
  let destroyed = false;
  let pendingUrl: string | null = null;

  function load(url: string, startAt: number, autoplay: boolean) {
    pendingUrl = null;
    video.src = url;
    video.addEventListener(
      "loadedmetadata",
      () => {
        if (startAt > 0) video.currentTime = startAt;
        if (autoplay) void video.play().catch(() => undefined);
      },
      { once: true },
    );
    video.load();
  }

  const onError = () => {
    if (destroyed) return;
    // Trình phát gốc không cho biết mã HTTP: mọi lỗi tải đều thử link khác trước (có trần liên tiếp ở PlaybackLinkManager).
    if (pendingUrl) load(pendingUrl, video.currentTime, isPlaying(video));
    else cb.onForbidden();
  };
  // Sẵn sàng khi đã có metadata/dữ liệu (không chờ `playing`: video chưa bấm phát thì `playing` không bao giờ bắn).
  const onReady = () => cb.onReady();
  const onPlaying = () => cb.onPlaying();
  const onPause = () => {
    if (pendingUrl && !destroyed) load(pendingUrl, video.currentTime, false);
  };
  video.addEventListener("error", onError);
  video.addEventListener("loadedmetadata", onReady);
  video.addEventListener("canplay", onReady);
  video.addEventListener("playing", onPlaying);
  video.addEventListener("pause", onPause);

  load(firstUrl, firstStart, false);
  return {
    setUrl(url, immediate = true) {
      if (destroyed) return;
      if (!immediate && isPlaying(video)) {
        pendingUrl = url;
        return;
      }
      load(url, video.currentTime, isPlaying(video));
    },
    destroy() {
      destroyed = true;
      for (const [name, fn] of [
        ["error", onError],
        ["loadedmetadata", onReady],
        ["canplay", onReady],
        ["playing", onPlaying],
        ["pause", onPause],
      ] as const) {
        video.removeEventListener(name, fn);
      }
      video.removeAttribute("src");
      video.load();
    },
  };
}

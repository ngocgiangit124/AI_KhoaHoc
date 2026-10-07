"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import {
  Button,
  IconAlertTriangle,
  IconButton,
  IconHourglass,
  IconLock,
  IconMaximize,
  IconPause,
  IconPlay,
  IconRotateCcw,
  IconVideo,
  IconVolume,
  Spinner,
  formatClock,
} from "@vitaminvui/ui/v2";
import { ApiError } from "@vitaminvui/api-client";
import { fetchPlayback, sendHeartbeat, warmCsrf } from "@/lib/learn/api";
import { courseRefFromError } from "@/lib/learn/errors";
import { isAllowedEmbedUrl, EMBED_SANDBOX } from "@/lib/learn/embed";
import { PLAYBACK_MESSAGES, type PlaybackErrorKind } from "@/lib/learn/errors";
import { classifyHeartbeatFailure, HEARTBEAT_INTERVAL_MS, HeartbeatTracker } from "@/lib/learn/heartbeat";
import { createHlsEngine, type HlsEngine } from "@/lib/learn/hlsEngine";
import { PlaybackLinkManager } from "@/lib/learn/linkManager";
import type { HeartbeatResult, PlaybackInfo } from "@/lib/learn/schemas";
import { onSessionEnded } from "@/lib/learn/sessionPause";

const SPEEDS = [0.75, 1, 1.25, 1.5, 2];

type Phase = "loading" | "ready" | "embed" | "error" | "processing" | "no_video" | "blocked";

export interface VideoPlayerProps {
  lessonId: number;
  title: string;
  /** Thời lượng khai báo của bài (giây) — dùng khi video chưa báo `duration`. */
  durationSeconds: number | null;
  /** `can_track` của `GET /learn/lessons/{id}`: người xem preview chưa sở hữu khoá KHÔNG gọi heartbeat. */
  canTrack: boolean;
  /** `video_ready` của bài: chưa sẵn sàng thì không gọi playback (sẽ 409) mà hiện "đang xử lý". */
  videoReady: boolean;
  /** Heartbeat thành công. */
  onProgress?: (result: HeartbeatResult) => void;
  /** Mất quyền học giữa chừng (heartbeat/playback 403 COURSE_NOT_OWNED). */
  onRevoked?: (course: { slug: string; title: string } | null) => void;
  /** Báo loại video để trang hiện ghi chú phù hợp (link ngoài không ghi được tiến độ). */
  onKind?: (kind: "hls" | "embed" | null) => void;
}

/**
 * Khung trình phát 16:9 (không đổi kích thước theo trạng thái → không giật bố cục). Dựng theo `VideoFrame` của designer
 * (design-system-v2 §12.3) nhưng nối dữ liệu thật: link phát lấy từ TRÌNH DUYỆT, làm mới trước `expires_at` và khi gặp 403,
 * heartbeat ~20 giây, `pause()` khi phiên kết thúc.
 */
export function VideoPlayer({ lessonId, title, durationSeconds, canTrack, videoReady, onProgress, onRevoked, onKind }: VideoPlayerProps) {
  const frameRef = useRef<HTMLElement>(null);
  const videoRef = useRef<HTMLVideoElement>(null);
  const managerRef = useRef<PlaybackLinkManager | null>(null);
  const engineRef = useRef<HlsEngine | null>(null);
  const latestUrlRef = useRef<string>("");
  const trackerRef = useRef(new HeartbeatTracker());
  // `endedRef`: heartbeat báo thu hồi/bài mất (dừng hẳn). `sessionEndedRef`: hộp thoại mất phiên đang mở (tạm dừng, khôi phục khi phát lại).
  const endedRef = useRef(false);
  const sessionEndedRef = useRef(false);
  const sendingRef = useRef(false);

  const [attempt, setAttempt] = useState(0);
  const [phase, setPhase] = useState<Phase>(videoReady ? "loading" : "processing");
  const [errorKind, setErrorKind] = useState<PlaybackErrorKind>("unknown");
  const [info, setInfo] = useState<PlaybackInfo | null>(null);

  const [playing, setPlaying] = useState(false);
  const [time, setTime] = useState(0);
  const [duration, setDuration] = useState(durationSeconds ?? 0);
  const [muted, setMuted] = useState(false);
  const [speed, setSpeed] = useState(1);

  // Callback của trang có thể đổi mỗi lần render: giữ bản mới nhất trong ref để effect không phải chạy lại.
  const cbRef = useRef({ onProgress, onRevoked, onKind });
  useEffect(() => {
    cbRef.current = { onProgress, onRevoked, onKind };
  });

  const fail = useCallback((kind: PlaybackErrorKind, err?: unknown) => {
    managerRef.current?.pause(); // không để hẹn giờ làm mới chạy tiếp (tốn throttle playback) khi đã báo lỗi
    engineRef.current?.destroy();
    engineRef.current = null;
    setErrorKind(kind);
    if (kind === "processing") setPhase("processing");
    else if (kind === "no_video") setPhase("no_video");
    else if (kind === "not_owned") {
      setPhase("blocked");
      cbRef.current.onRevoked?.(courseRefFromError(err));
    } else setPhase("error");
  }, []);

  // 1) Xin link phát (từ trình duyệt) và giữ cho còn hạn.
  useEffect(() => {
    if (!videoReady) return;
    const manager = new PlaybackLinkManager({
      fetchPlayback: () => fetchPlayback(lessonId),
      onInfo: (next, reason) => {
        latestUrlRef.current = next.url;
        if (reason === "initial") {
          if (next.kind === "embed" && !isAllowedEmbedUrl(next.url)) {
            setErrorKind("unknown");
            setPhase("error");
            return;
          }
          cbRef.current.onKind?.(next.kind);
          setTime(next.resume_at_seconds);
          if (next.kind === "embed") setPhase("embed");
          setInfo(next);
        } else {
          // Chỉ 403 (và khôi phục phiên) cần đổi nguồn ngay; làm mới theo lịch chờ lúc tạm dừng để không giật hình.
          engineRef.current?.setUrl(next.url, reason === "forbidden" || reason === "resume");
        }
      },
      onError: (kind, err, fatal) => {
        if (fatal) fail(kind, err);
      },
    });
    managerRef.current = manager;
    void manager.start();
    return () => {
      manager.dispose();
      managerRef.current = null;
      cbRef.current.onKind?.(null);
    };
  }, [lessonId, videoReady, attempt, fail]);

  // 2) Gắn hls.js (hoặc HLS gốc) vào <video> khi đã có link đầu tiên.
  useEffect(() => {
    const video = videoRef.current;
    if (!info || info.kind !== "hls" || !video) return;
    let cancelled = false;
    createHlsEngine(video, latestUrlRef.current || info.url, info.resume_at_seconds, {
      onForbidden: () => void managerRef.current?.reportForbidden(),
      onFatal: () => fail("unknown"),
      onReady: () => {
        managerRef.current?.reportPlaying();
        setPhase((p) => (p === "loading" ? "ready" : p));
      },
      onPlaying: () => managerRef.current?.reportPlaying(),
    })
      .then((engine) => {
        if (cancelled) engine.destroy();
        else engineRef.current = engine;
      })
      .catch(() => {
        if (!cancelled) fail("unknown");
      });
    return () => {
      cancelled = true;
      engineRef.current?.destroy();
      engineRef.current = null;
    };
  }, [info, fail]);

  // 3) Heartbeat tiến độ.
  const flush = useCallback(
    (opts: { force?: boolean; keepalive?: boolean } = {}) => {
      if (!canTrack || endedRef.current || sessionEndedRef.current || sendingRef.current) return;
      const tracker = trackerRef.current;
      const now = Date.now();
      if (!opts.force && !tracker.canSend(now)) return;
      const payload = tracker.take(now);
      if (!payload) return;
      sendingRef.current = true;
      sendHeartbeat(lessonId, payload, { keepalive: opts.keepalive })
        .then((result) => cbRef.current.onProgress?.(result))
        .catch((err: unknown) => {
          const action = classifyHeartbeatFailure(err);
          if (action === "retry" && !(err instanceof ApiError && err.status === 401)) tracker.restore(payload);
          if (action === "revoked") {
            videoRef.current?.pause();
            endedRef.current = true;
            managerRef.current?.pause();
            setErrorKind("not_owned");
            setPhase("blocked");
            cbRef.current.onRevoked?.(courseRefFromError(err));
          }
          if (action === "gone") endedRef.current = true;
        })
        .finally(() => {
          sendingRef.current = false;
        });
    },
    [canTrack, lessonId],
  );

  useEffect(() => {
    const video = videoRef.current;
    if (!video || info?.kind !== "hls") return;
    const tracker = trackerRef.current;
    const onPlay = () => {
      if (sessionEndedRef.current) {
        // Người dùng bấm phát lại sau khi đăng nhập lại: phiên đã khôi phục → bật lại heartbeat và xin link mới.
        sessionEndedRef.current = false;
        void managerRef.current?.resume();
      }
      tracker.reset(video.currentTime);
      setPlaying(true);
    };
    const onPause = () => {
      tracker.reset(video.currentTime);
      setPlaying(false);
      flush();
    };
    const onEnd = () => {
      setPlaying(false);
      flush({ force: true });
    };
    const onTime = () => {
      setTime(video.currentTime);
      if (!video.paused && !video.seeking) tracker.observe(video.currentTime);
    };
    const onSeeked = () => tracker.reset(video.currentTime);
    const onMeta = () => {
      if (Number.isFinite(video.duration) && video.duration > 0) setDuration(video.duration);
    };
    const onRate = () => setSpeed(video.playbackRate);
    const onVolume = () => setMuted(video.muted);
    const events: Array<[string, () => void]> = [
      ["play", onPlay],
      ["pause", onPause],
      ["ended", onEnd],
      ["timeupdate", onTime],
      ["seeked", onSeeked],
      ["loadedmetadata", onMeta],
      ["durationchange", onMeta],
      ["ratechange", onRate],
      ["volumechange", onVolume],
    ];
    for (const [name, fn] of events) video.addEventListener(name, fn);
    const interval = window.setInterval(() => {
      if (!video.paused) flush();
    }, HEARTBEAT_INTERVAL_MS);
    // Đóng tab/đổi tab: gửi nốt phần chưa gửi (keepalive để request sống sau khi trang đóng).
    // `pagehide` bắn khi đổi URL cứng/F5/đóng tab và lúc đó `visibilityState` vẫn là "visible" (visibilitychange bắn SAU, quá muộn):
    // flush vô điều kiện. CSRF token đã được lấy sẵn ở dưới nên POST keepalive đi được ngay trong sự kiện.
    // pagehide + beforeunload + visibilitychange bắn gần như cùng lúc: chỉ gửi MỘT lần (throttle heartbeat 6/phút/bài).
    let lastUnloadFlush = 0;
    const onHide = () => {
      if (Date.now() - lastUnloadFlush < 2_000) return;
      lastUnloadFlush = Date.now();
      flush({ force: true, keepalive: true });
    };
    const onVisibility = () => {
      if (document.visibilityState === "hidden") onHide();
    };
    if (canTrack) void warmCsrf();
    document.addEventListener("visibilitychange", onVisibility);
    window.addEventListener("pagehide", onHide);
    window.addEventListener("beforeunload", onHide); // đóng tab bằng `close({runBeforeUnload})`/một số trình duyệt không bắn pagehide kịp
    return () => {
      for (const [name, fn] of events) video.removeEventListener(name, fn);
      window.clearInterval(interval);
      document.removeEventListener("visibilitychange", onVisibility);
      window.removeEventListener("pagehide", onHide);
      window.removeEventListener("beforeunload", onHide);
      // Đổi bài/rời trang bằng điều hướng mềm không bắn pagehide: gửi nốt tiến độ của bài này trước khi bỏ.
      flush({ force: true, keepalive: true });
    };
  }, [info, flush, canTrack]);

  // 4) Hộp thoại phiên kết thúc đang mở → dừng video, ngừng heartbeat và làm mới link (nền modal chỉ inert, không dừng media).
  useEffect(
    () =>
      onSessionEnded(() => {
        sessionEndedRef.current = true;
        videoRef.current?.pause();
        managerRef.current?.pause();
      }),
    [],
  );

  // "Thử lại": về trạng thái đang tải rồi chạy lại effect xin link (đổi bài thì component remount nhờ `key` nên không cần đặt lại ở effect).
  const retry = () => {
    setPhase("loading");
    setInfo(null);
    setAttempt((a) => a + 1);
  };

  const toggle = () => {
    const video = videoRef.current;
    if (!video) return;
    if (video.paused) void video.play().catch(() => undefined);
    else video.pause();
  };

  const fullscreen = () => {
    const frame = frameRef.current;
    const video = videoRef.current as (HTMLVideoElement & { webkitEnterFullscreen?: () => void }) | null;
    if (!frame) return;
    if (document.fullscreenElement) void document.exitFullscreen();
    else if (document.fullscreenEnabled && frame.requestFullscreen) void frame.requestFullscreen();
    else video?.webkitEnterFullscreen?.(); // iPhone Safari: chỉ cho toàn màn hình phần tử <video>
  };

  const total = duration || durationSeconds || 0;
  const showControls = phase === "ready" && info?.kind === "hls";

  return (
    <section ref={frameRef} aria-label={`Video: ${title}`} className="relative aspect-video w-full overflow-hidden bg-player text-player-ink sm:rounded-card">
      {info?.kind === "hls" && phase !== "error" && phase !== "blocked" ? (
        <video
          ref={videoRef}
          playsInline
          preload="metadata"
          aria-label={title}
          onClick={toggle}
          className="absolute inset-0 size-full bg-player object-contain"
        />
      ) : null}

      {info?.kind === "embed" && phase === "embed" ? (
        <iframe
          src={info.url}
          title={`Video: ${title}`}
          sandbox={EMBED_SANDBOX}
          allow="fullscreen; picture-in-picture; encrypted-media"
          allowFullScreen
          referrerPolicy="strict-origin-when-cross-origin"
          className="absolute inset-0 size-full border-0"
        />
      ) : null}

      {phase === "loading" ? (
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-player/70">
          <Spinner className="size-10" label="Đang tải video…" />
          <span className="text-sm">Đang tải video…</span>
        </div>
      ) : null}

      {phase === "error" ? (
        <div role="alert" className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-player px-6 text-center">
          <IconAlertTriangle size={32} className="text-accent" />
          <p className="text-base font-semibold">{PLAYBACK_MESSAGES[errorKind]}</p>
          <Button variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />} onClick={retry}>
            Thử lại
          </Button>
        </div>
      ) : null}

      {phase === "processing" ? (
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-player px-6 text-center">
          <IconHourglass size={32} className="text-accent" />
          <p className="text-base font-semibold">Video bài này đang được xử lý</p>
          <p className="max-w-sm text-sm opacity-80">Thường mất vài phút. Bạn có thể học bài khác rồi quay lại sau.</p>
        </div>
      ) : null}

      {phase === "no_video" ? (
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-player px-6 text-center">
          <IconVideo size={32} className="text-accent" />
          <p className="text-base font-semibold">Bài học này chưa có video</p>
        </div>
      ) : null}

      {phase === "blocked" ? (
        <div role="alert" className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-player px-6 text-center">
          <IconLock size={32} className="text-accent" />
          <p className="text-base font-semibold">{PLAYBACK_MESSAGES.not_owned}</p>
        </div>
      ) : null}

      {showControls ? (
        <>
          {!playing ? (
            <button
              type="button"
              onClick={toggle}
              aria-label="Phát video"
              className="absolute left-1/2 top-[calc((100%-5.75rem)/2)] flex size-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-surface text-primary shadow-overlay [--vv-focus:var(--vv-player-ink)] focus-ring"
            >
              <IconPlay size={26} className="translate-x-0.5" />
            </button>
          ) : null}
          <div className="pointer-events-none absolute inset-x-0 bottom-0 flex flex-col gap-1 bg-linear-to-t from-player to-transparent px-2 pb-1 pt-8 sm:px-3">
            <label className="sr-only" htmlFor="video-seek">
              Tua video
            </label>
            <input
              id="video-seek"
              type="range"
              min={0}
              max={Math.max(1, Math.floor(total))}
              step={1}
              value={Math.min(Math.floor(time), Math.max(1, Math.floor(total)))}
              onChange={(e) => {
                const video = videoRef.current;
                if (video) video.currentTime = Number(e.target.value);
                setTime(Number(e.target.value));
              }}
              aria-valuetext={`${formatClock(time)} trên ${formatClock(total)}`}
              className="focus-ring pointer-events-auto h-11 w-full cursor-pointer accent-accent [--vv-focus:var(--vv-player-ink)]"
            />
            <div className="pointer-events-auto flex items-center gap-1">
              <IconButton
                variant="on-dark"
                label={playing ? "Tạm dừng" : "Phát"}
                icon={playing ? <IconPause size={20} /> : <IconPlay size={20} />}
                onClick={toggle}
              />
              <IconButton
                variant="on-dark"
                label="Tắt tiếng"
                aria-pressed={muted}
                icon={<IconVolume size={20} className={muted ? "opacity-40" : undefined} />}
                onClick={() => {
                  if (videoRef.current) videoRef.current.muted = !videoRef.current.muted;
                }}
              />
              <span className="num ml-1 text-sm font-semibold">
                {formatClock(time)} / {formatClock(total)}
              </span>
              <label className="ml-auto flex items-center">
                <span className="sr-only">Tốc độ phát</span>
                <select
                  value={speed}
                  onChange={(e) => {
                    if (videoRef.current) videoRef.current.playbackRate = Number(e.target.value);
                  }}
                  className="focus-ring h-11 cursor-pointer rounded-control bg-transparent px-2 text-sm font-semibold text-player-ink [--vv-focus:var(--vv-player-ink)] hover:bg-white/15"
                >
                  {SPEEDS.map((s) => (
                    <option key={s} value={s} className="text-ink">
                      {s === 1 ? "1×" : `${s}×`}
                    </option>
                  ))}
                </select>
              </label>
              <IconButton variant="on-dark" label="Toàn màn hình" icon={<IconMaximize size={20} />} onClick={fullscreen} />
            </div>
          </div>
        </>
      ) : null}
    </section>
  );
}

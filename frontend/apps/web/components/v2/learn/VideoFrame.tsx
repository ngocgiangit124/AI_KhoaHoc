"use client";

import { useState } from "react";
import {
  Button,
  IconAlertTriangle,
  IconButton,
  IconHourglass,
  IconMaximize,
  IconPause,
  IconPlay,
  IconRotateCcw,
  IconVolume,
  Spinner,
  formatClock,
} from "@vitaminvui/ui/v2";

export type VideoState = "ready" | "loading" | "error" | "processing";

const SPEEDS = [0.75, 1, 1.25, 1.5, 2];

/**
 * Khung trình phát 16:9 (không bao giờ đổi kích thước theo trạng thái → không giật layout).
 * Bản thật: hls.js phát `PlaybackInfo.url`, iframe sandbox cho `kind=embed`; tự lấy link mới khi 403
 * mà không báo lỗi (US-006). Bản xem trước chỉ dựng giao diện điều khiển.
 * Trạng thái: đang tải (vòng xoay), lỗi (AC5, nút Thử lại), video đang xử lý (409 VIDEO_NOT_READY).
 */
export function VideoFrame({
  title,
  durationSeconds,
  positionSeconds = 0,
  state = "ready",
}: {
  title: string;
  durationSeconds: number;
  positionSeconds?: number;
  state?: VideoState;
}) {
  const [playing, setPlaying] = useState(false);
  const [speed, setSpeed] = useState(1);
  const [position, setPosition] = useState(positionSeconds);
  const pct = durationSeconds ? (position / durationSeconds) * 100 : 0;

  return (
    <section aria-label={`Video: ${title}`} className="relative aspect-video w-full overflow-hidden bg-player text-player-ink sm:rounded-card">
      {/* Khung hình minh hoạ thay cho video thật. */}
      <svg viewBox="0 0 320 180" className="absolute inset-0 size-full opacity-90" aria-hidden="true">
        <circle cx="130" cy="88" r="58" fill="none" stroke="currentColor" strokeOpacity="0.8" strokeWidth="1.5" />
        <polyline points="130,30 80,118 182,112 130,30" fill="none" stroke="currentColor" strokeOpacity="0.8" strokeWidth="1.5" />
        <path d="M121 44 Q130 51 139 44" fill="none" className="stroke-accent" strokeWidth="2.2" />
        <text x="214" y="70" fill="currentColor" fontSize="11" fontFamily="serif" fontStyle="italic">
          ∠BAC = ½ sđ BC
        </text>
      </svg>

      {state === "loading" ? (
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-player/70">
          <Spinner className="size-10" label="Đang tải video…" />
          <span className="text-sm">Đang tải video…</span>
        </div>
      ) : null}

      {state === "error" ? (
        <div role="alert" className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-player/90 px-6 text-center">
          <IconAlertTriangle size={32} className="text-accent" />
          <p className="text-base font-semibold">Không tải được video, vui lòng thử lại.</p>
          <Button variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />}>
            Thử lại
          </Button>
        </div>
      ) : null}

      {state === "processing" ? (
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-player/90 px-6 text-center">
          <IconHourglass size={32} className="text-accent" />
          <p className="text-base font-semibold">Video bài này đang được xử lý</p>
          <p className="max-w-sm text-sm opacity-80">Thường mất vài phút. Bạn có thể học bài khác rồi quay lại sau.</p>
        </div>
      ) : null}

      {state === "ready" ? (
        <>
          {!playing ? (
            <button
              type="button"
              onClick={() => setPlaying(true)}
              aria-label="Phát video"
              className="absolute left-1/2 top-1/2 flex size-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-surface text-primary shadow-overlay [--vv-focus:var(--vv-player-ink)] focus-ring"
            >
              <IconPlay size={26} className="translate-x-0.5" />
            </button>
          ) : null}
          <div className="absolute inset-x-0 bottom-0 flex flex-col gap-1 bg-linear-to-t from-player to-transparent px-2 pb-1 pt-8 sm:px-3">
            <label className="sr-only" htmlFor="video-seek">
              Tua video
            </label>
            <input
              id="video-seek"
              type="range"
              min={0}
              max={durationSeconds}
              value={position}
              onChange={(e) => setPosition(Number(e.target.value))}
              aria-valuetext={`${formatClock(position)} trên ${formatClock(durationSeconds)}`}
              className="focus-ring h-6 w-full cursor-pointer accent-accent [--vv-focus:var(--vv-player-ink)]"
              style={{ backgroundSize: `${pct}% 100%` }}
            />
            <div className="flex items-center gap-1">
              <IconButton
                variant="on-dark"
                label={playing ? "Tạm dừng" : "Phát"}
                icon={playing ? <IconPause size={20} /> : <IconPlay size={20} />}
                onClick={() => setPlaying((p) => !p)}
              />
              <IconButton variant="on-dark" label="Âm lượng" icon={<IconVolume size={20} />} className="hidden sm:inline-flex" />
              <span className="num ml-1 text-sm font-semibold">
                {formatClock(position)} / {formatClock(durationSeconds)}
              </span>
              <label className="ml-auto flex items-center">
                <span className="sr-only">Tốc độ phát</span>
                <select
                  value={speed}
                  onChange={(e) => setSpeed(Number(e.target.value))}
                  className="focus-ring h-11 cursor-pointer rounded-control bg-transparent px-2 text-sm font-semibold text-player-ink [--vv-focus:var(--vv-player-ink)] hover:bg-white/15"
                >
                  {SPEEDS.map((s) => (
                    <option key={s} value={s} className="text-ink">
                      {s === 1 ? "1×" : `${s}×`}
                    </option>
                  ))}
                </select>
              </label>
              <IconButton variant="on-dark" label="Toàn màn hình" icon={<IconMaximize size={20} />} />
            </div>
          </div>
        </>
      ) : null}
    </section>
  );
}

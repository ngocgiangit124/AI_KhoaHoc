"use client";

import { useEffect, useId, useRef, useState, type DragEvent } from "react";
import { Alert, Button, ConfirmDialog, IconUpload, ProgressBar, cx } from "@vitaminvui/ui/v2";
import { VideoStatusBadge } from "@/components/v2/CourseStatusBadge";
import { getLessonVideo } from "@/lib/curriculum/api";
import type { Lesson, LessonVideoInfo } from "@/lib/curriculum/types";
import { VIDEO_ACCEPT, VIDEO_HINT, formatBytes, formatEta, validateVideoFile } from "@/lib/curriculum/video";
import type { UploadManager } from "./useUploadManager";

/** `118 giây` → `1 phút 58 giây`. */
function duration(seconds: number): string {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m} phút ${s} giây` : `${s} giây`;
}

/**
 * Khối "Video bài học" (US-009 §2.3): chọn tệp (kiểm 1 GB/định dạng ở UI), tiến độ TUS, tạm dừng khi mất mạng,
 * trạng thái xử lý (đang xử lý/sẵn sàng/lỗi) lấy từ cây chương (được poll ở màn hình) + chi tiết `GET .../video`.
 */
export function VideoPanel({ courseId, lesson, manager }: { courseId: number; lesson: Lesson; manager: UploadManager }) {
  const inputId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const upload = manager.uploads[lesson.id];
  const [info, setInfo] = useState<LessonVideoInfo | null>(null);
  const [pickError, setPickError] = useState<string | null>(null);
  const [pending, setPending] = useState<File | null>(null);
  const [dragOver, setDragOver] = useState(false);

  // Chi tiết (tên tệp gốc, lý do lỗi) — hỏi lại mỗi khi trạng thái của bài đổi.
  useEffect(() => {
    if (!lesson.has_video_asset) return;
    const controller = new AbortController();
    getLessonVideo(courseId, lesson.id, controller.signal)
      .then(setInfo)
      .catch(() => {
        if (!controller.signal.aborted) setInfo(null);
      });
    return () => controller.abort();
  }, [courseId, lesson.id, lesson.has_video_asset, lesson.video_status]);

  const hasAsset = lesson.has_video_asset && lesson.video_source === "upload";
  const replacing = hasAsset || lesson.video_source === "external_link";
  const status = lesson.has_video_asset ? lesson.video_status : null;
  const busyLocal = upload?.phase === "starting" || upload?.phase === "uploading" || upload?.phase === "paused";
  const interrupted = !busyLocal && (status === "uploading" || status === "created");

  function choose(file: File | undefined) {
    if (!file) return;
    const err = validateVideoFile(file);
    setPickError(err);
    if (err) return;
    if (replacing) setPending(file);
    else manager.start(lesson.id, file);
  }
  function onDrop(e: DragEvent<HTMLLabelElement>) {
    e.preventDefault();
    setDragOver(false);
    choose(e.dataTransfer.files[0]);
  }

  const badgeLesson = { video_source: lesson.video_source, video_status: status, external_provider: lesson.external_provider };

  return (
    <div className="flex flex-col gap-3 rounded-card border border-line bg-paper p-4" data-testid="video-panel">
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-semibold text-ink">Video bài học</span>
        {upload && upload.phase !== "failed" ? (
          <VideoStatusBadge lesson={{ ...badgeLesson, video_source: "upload", video_status: "uploading", upload_percent: "percent" in upload ? upload.percent : 0 }} />
        ) : (
          <VideoStatusBadge lesson={badgeLesson} />
        )}
      </div>

      {upload?.phase === "starting" ? <p className="text-sm text-ink-soft">Đang chuẩn bị tải lên {upload.filename}…</p> : null}

      {upload?.phase === "uploading" ? (
        <>
          <ProgressBar
            value={upload.percent}
            label={`Đang tải lên ${upload.filename}`}
            valueText={[`${upload.percent}%`, formatEta(upload.etaSeconds)].filter(Boolean).join(" · ")}
          />
          <p className="text-sm text-ink-soft">Giữ trang này mở tới khi tải xong. Mất mạng sẽ tự tiếp tục từ chỗ dừng.</p>
        </>
      ) : null}

      {upload?.phase === "paused" ? (
        <>
          <ProgressBar value={upload.percent} label={`Đã tải ${upload.filename}`} valueText={`${upload.percent}% · đang dừng`} />
          <Alert tone="warning" title="Tải lên đang dừng">
            {upload.message}
          </Alert>
          <div>
            <Button size="sm" className="max-sm:h-11" onClick={() => manager.resume(lesson.id)}>
              Tải tiếp
            </Button>
          </div>
        </>
      ) : null}

      {busyLocal ? (
        <div>
          <Button variant="secondary" size="sm" className="max-sm:h-11" onClick={() => manager.cancel(lesson.id)}>
            Huỷ tải lên
          </Button>
        </div>
      ) : null}

      {upload?.phase === "failed" ? (
        <Alert tone="danger" title="Tải video lên thất bại" action={<Button size="sm" variant="secondary" className="max-sm:h-11" onClick={() => manager.dismiss(lesson.id)}>Đóng</Button>}>
          {upload.message}
        </Alert>
      ) : null}

      {!upload && status === "failed" ? (
        <Alert tone="danger" title="Video xử lý thất bại">
          {info?.error_message ?? "Vui lòng tải lại tệp khác."}
        </Alert>
      ) : null}
      {interrupted ? (
        <Alert tone="warning" title="Tải lên chưa hoàn tất">
          Lượt tải trước bị gián đoạn (đóng trang hoặc huỷ). Hãy chọn lại tệp để tải từ đầu.
        </Alert>
      ) : null}
      {!busyLocal && status === "processing" ? (
        <p className="text-sm text-ink-soft">Video đang được xử lý, có thể mất vài phút. Học sinh chưa xem được bài này.</p>
      ) : null}
      {!busyLocal && status === "ready" ? (
        <p className="num text-sm text-ink-soft">
          Đã sẵn sàng{lesson.duration_seconds ? ` · thời lượng ${duration(lesson.duration_seconds)}` : ""}
          {info?.original_filename ? ` · ${info.original_filename}` : ""}
        </p>
      ) : null}

      {!busyLocal ? (
        <>
          <label
            htmlFor={inputId}
            onDragOver={(e) => {
              e.preventDefault();
              setDragOver(true);
            }}
            onDragLeave={() => setDragOver(false)}
            onDrop={onDrop}
            className={cx(
              "flex min-h-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-control border-2 border-dashed bg-surface px-3 py-4 text-center hover:border-primary has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-focus",
              dragOver ? "border-primary bg-primary-soft" : "border-line-strong",
            )}
          >
            <IconUpload className="text-primary" />
            <span className="text-sm font-semibold text-primary">{status === null && lesson.video_source !== "external_link" ? "Chọn tệp video" : "Chọn tệp khác"}</span>
            <span className="text-xs text-ink-soft">{VIDEO_HINT} · kéo thả vào đây</span>
            <input
              id={inputId}
              ref={inputRef}
              type="file"
              accept={VIDEO_ACCEPT}
              data-testid="video-file-input"
              className="sr-only"
              onChange={(e) => {
                choose(e.target.files?.[0]);
                e.target.value = "";
              }}
            />
          </label>
          {pickError ? (
            <p role="alert" className="text-sm font-medium text-danger">
              {pickError}
            </p>
          ) : null}
        </>
      ) : null}

      {pending ? (
        <ConfirmDialog
          open
          title="Thay video hiện tại?"
          description={
            lesson.video_source === "external_link"
              ? `Bài này đang dùng link ngoài. Tải “${pending.name}” (${formatBytes(pending.size)}) sẽ bỏ link đó.`
              : `Tải “${pending.name}” (${formatBytes(pending.size)}) sẽ thay video hiện tại của bài. Học sinh chưa xem được bài cho tới khi video mới xử lý xong.`
          }
          confirmLabel="Thay video"
          onClose={() => setPending(null)}
          onConfirm={() => {
            const file = pending;
            setPending(null);
            manager.start(lesson.id, file);
          }}
        />
      ) : null}
    </div>
  );
}

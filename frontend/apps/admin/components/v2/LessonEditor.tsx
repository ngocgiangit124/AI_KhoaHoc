"use client";

import { useState } from "react";
import { Alert, Button, Checkbox, Field, IconUpload, ProgressBar, TextInput, cx } from "@vitaminvui/ui/v2";
import type { AdminLesson, VideoSource } from "@/lib/mock/v2/data";
import { VideoStatusBadge } from "./CourseStatusBadge";

/**
 * Form một bài học (US-009 §2.3, LessonRequest + T11 video-uploads).
 * - "Cho xem thử" đặt TRƯỚC nguồn video vì quyết định nguồn nào được dùng.
 * - "Dán link ngoài" chỉ chọn được khi bài cho xem thử; lý do hiện ngay dưới lựa chọn (không dùng tooltip).
 * - Video lỗi: hiện `error_message` (VIDEO_INVALID dùng thẳng message tiếng Việt từ API) + chọn tệp khác.
 */
export function LessonEditor({ lesson }: { lesson: AdminLesson }) {
  const [preview, setPreview] = useState(lesson.is_preview);
  const [source, setSource] = useState<VideoSource>(lesson.video_source === "none" ? "upload" : lesson.video_source);
  const externalAllowed = preview;
  const effective = !externalAllowed && source === "external_link" ? "upload" : source;

  return (
    <form className="flex flex-col gap-5" onSubmit={(e) => e.preventDefault()}>
      <Field label="Tên bài học" required>
        <TextInput size="sm" defaultValue={lesson.title} maxLength={255} />
      </Field>
      <Checkbox id={`preview-${lesson.id}`} label="Cho xem thử" description="Học sinh chưa mua vẫn xem được bài này." checked={preview} onChange={(e) => setPreview(e.target.checked)} />

      <fieldset className="flex flex-col gap-2">
        <legend className="mb-1 text-sm font-semibold text-ink">Nguồn video</legend>
        <div className="grid grid-cols-2 gap-2">
          {(
            [
              { v: "upload", label: "Tải video lên" },
              { v: "external_link", label: "Dán link YouTube/Vimeo" },
            ] as const
          ).map((o) => {
            const disabled = o.v === "external_link" && !externalAllowed;
            return (
              <label
                key={o.v}
                className={cx(
                  "flex min-h-11 items-center gap-2 rounded-control border px-3 text-sm font-semibold has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-focus",
                  disabled ? "cursor-not-allowed border-line bg-sunken text-ink-soft" : "cursor-pointer",
                  !disabled && effective === o.v ? "border-primary bg-primary-soft text-primary" : !disabled ? "border-line-strong text-ink hover:border-primary" : "",
                )}
              >
                <input type="radio" name={`src-${lesson.id}`} value={o.v} checked={effective === o.v} disabled={disabled} onChange={() => setSource(o.v)} className="accent-primary" />
                {o.label}
              </label>
            );
          })}
        </div>
        {!externalAllowed ? (
          <p className="text-sm text-ink-soft">Chỉ bài cho xem thử mới được dùng link ngoài. Bài trả phí phải tải video lên hệ thống.</p>
        ) : null}
      </fieldset>

      {effective === "external_link" ? (
        <Field label="Link video" required hint="YouTube hoặc Vimeo công khai (không nhận link Vimeo riêng tư).">
          <TextInput size="sm" type="url" defaultValue={lesson.external_video_id ? `https://www.youtube.com/watch?v=${lesson.external_video_id}` : ""} placeholder="https://www.youtube.com/watch?v=…" />
        </Field>
      ) : (
        <div className="flex flex-col gap-3 rounded-card border border-line bg-paper p-4">
          <div className="flex items-center justify-between gap-2">
            <span className="text-sm font-semibold text-ink">Video bài học</span>
            <VideoStatusBadge lesson={lesson} />
          </div>
          {lesson.video_status === "failed" ? (
            <Alert tone="danger" title="Video xử lý thất bại">
              {lesson.error_message ?? "Vui lòng tải lại file khác."}
            </Alert>
          ) : null}
          {lesson.video_status === "uploading" ? (
            <>
              <ProgressBar value={lesson.upload_percent ?? 0} label="Đang tải lên bai-9-goc-co-dinh.mp4" valueText={`${lesson.upload_percent ?? 0}% · còn khoảng 3 phút`} />
              <p className="text-sm text-ink-soft">Giữ trang này mở tới khi tải xong. Mất mạng sẽ tự tiếp tục từ chỗ dừng.</p>
              <div>
                <Button variant="secondary" size="sm">
                  Huỷ tải lên
                </Button>
              </div>
            </>
          ) : null}
          {lesson.video_status === "processing" ? <p className="text-sm text-ink-soft">Video đang được xử lý, có thể mất vài phút. Học sinh chưa xem được bài này.</p> : null}
          {lesson.video_status === "ready" ? <p className="num text-sm text-ink-soft">Đã sẵn sàng · thời lượng {Math.floor((lesson.duration_seconds ?? 0) / 60)} phút {(lesson.duration_seconds ?? 0) % 60} giây</p> : null}
          {lesson.video_status !== "uploading" ? (
            <label className="flex min-h-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-control border-2 border-dashed border-line-strong bg-surface px-3 py-4 text-center hover:border-primary has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-focus">
              <IconUpload className="text-primary" />
              <span className="text-sm font-semibold text-primary">{lesson.video_status === null ? "Chọn tệp video" : "Chọn tệp khác"}</span>
              <span className="text-xs text-ink-soft">MP4, MOV, MKV hoặc WebM · tối đa 2GB · kéo thả vào đây</span>
              <input type="file" accept=".mp4,.mov,.mkv,.webm,video/*" className="sr-only" />
            </label>
          ) : null}
        </div>
      )}

      <div className="flex justify-end gap-2 border-t border-line pt-4">
        <Button variant="secondary" size="sm">
          Huỷ
        </Button>
        <Button size="sm" type="submit" disabled={lesson.video_status === "uploading"}>
          Lưu bài học
        </Button>
      </div>
      {lesson.video_status === "uploading" ? <p className="-mt-3 text-right text-xs text-ink-soft">Lưu được khi tải video xong hoặc chọn nguồn khác.</p> : null}
    </form>
  );
}

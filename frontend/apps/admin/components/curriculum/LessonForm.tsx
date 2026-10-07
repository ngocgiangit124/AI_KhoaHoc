"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert, Button, Checkbox, Field, TextInput, cx } from "@vitaminvui/ui/v2";
import { updateLesson } from "@/lib/curriculum/api";
import { curriculumError, isGone, lessonFieldErrors } from "@/lib/curriculum/errors";
import type { Lesson, LessonPayload } from "@/lib/curriculum/types";
import { NAME_MAX } from "./NameDialog";
import { VideoPanel } from "./VideoPanel";
import type { UploadManager } from "./useUploadManager";

type Source = "upload" | "external_link";

/** API không trả lại URL người nhập: dựng lại link chuẩn từ nhà cung cấp + ID để hiện trong ô. */
export function externalUrlOf(lesson: Pick<Lesson, "external_provider" | "external_video_id">): string {
  if (!lesson.external_video_id) return "";
  return lesson.external_provider === "vimeo" ? `https://vimeo.com/${lesson.external_video_id}` : `https://www.youtube.com/watch?v=${lesson.external_video_id}`;
}

/**
 * Dữ liệu gửi `PUT .../lessons/{id}` (T09). Chỉ gửi `video_source` khi cần đổi: sang link ngoài, hoặc bỏ link ngoài để dùng tải lên
 * (`none`). `upload` do T11 gắn khi tải tệp nên không bao giờ gửi từ đây. `external_url` bỏ qua khi không sửa (server giữ ID cũ).
 */
export function buildLessonPayload(
  lesson: Pick<Lesson, "video_source" | "external_provider" | "external_video_id">,
  v: { title: string; preview: boolean; source: Source; url: string },
): LessonPayload {
  const payload: LessonPayload = { title: v.title.trim(), is_preview: v.preview };
  if (v.source === "external_link") {
    payload.video_source = "external_link";
    const unchanged = lesson.video_source === "external_link" && v.url.trim() === externalUrlOf(lesson);
    if (!unchanged) payload.external_url = v.url.trim();
  } else if (lesson.video_source === "external_link") {
    payload.video_source = "none";
  }
  return payload;
}

export interface LessonFormProps {
  courseId: number;
  lesson: Lesson;
  manager: UploadManager;
  onSaved: (lesson: Lesson) => void;
  /** Bài đã bị xoá nơi khác (404). */
  onGone: () => void;
  onCancel: () => void;
  onDirtyChange: (dirty: boolean) => void;
}

/**
 * Form một bài (US-009 §2.3). "Cho xem thử" đặt trước nguồn video: link ngoài chỉ chọn được khi bài cho xem thử.
 * Nút Lưu khoá khi đang tải video (đổi nguồn/lưu lúc tải dở dễ mất đồng bộ).
 */
export function LessonForm({ courseId, lesson, manager, onSaved, onGone, onCancel, onDirtyChange }: LessonFormProps) {
  const initialUrl = externalUrlOf(lesson);
  const [title, setTitle] = useState(lesson.title);
  const [preview, setPreview] = useState(lesson.is_preview);
  const [source, setSource] = useState<Source>(lesson.video_source === "external_link" ? "external_link" : "upload");
  const [url, setUrl] = useState(initialUrl);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);

  const upload = manager.uploads[lesson.id];
  const uploading = upload?.phase === "starting" || upload?.phase === "uploading" || upload?.phase === "paused";
  // Bắt đầu tải lên = chọn nguồn "tải lên" (server cũng bỏ link ngoài ngay khi nhận phiên tải).
  if (uploading && source !== "upload") setSource("upload");
  const externalAllowed = preview;
  // Đang tải lên: nguồn là "tải lên" dù radio đang chọn gì.
  const effective: Source = uploading || (!externalAllowed && source === "external_link") ? "upload" : source;

  const dirty = title !== lesson.title || preview !== lesson.is_preview || effective !== (lesson.video_source === "external_link" ? "external_link" : "upload") || (effective === "external_link" && url !== initialUrl);
  useEffect(() => {
    onDirtyChange(dirty);
  }, [dirty, onDirtyChange]);
  useEffect(() => () => onDirtyChange(false), [onDirtyChange]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (busy || uploading) return;
    const next: Record<string, string> = {};
    if (!title.trim()) next.title = "Tên bài học không được để trống.";
    if (effective === "external_link" && !url.trim()) next.external_url = "Hãy dán link YouTube hoặc Vimeo.";
    setErrors(next);
    setBanner(null);
    if (Object.keys(next).length > 0) return;
    setBusy(true);
    try {
      const saved = await updateLesson(courseId, lesson.chapter_id, lesson.id, buildLessonPayload(lesson, { title, preview, source: effective, url }));
      onSaved(saved);
    } catch (err) {
      if (isGone(err)) {
        onGone();
        return;
      }
      const fields = lessonFieldErrors(err);
      const known = { title: fields.title, external_url: fields.external_url ?? fields.video_source };
      const mapped: Record<string, string> = {};
      if (known.title) mapped.title = known.title;
      if (known.external_url) mapped.external_url = known.external_url;
      setErrors(mapped);
      setBanner(Object.keys(mapped).length > 0 ? null : curriculumError(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="flex flex-col gap-5" onSubmit={submit} noValidate>
      {banner ? <Alert tone="danger">{banner}</Alert> : null}
      <Field label="Tên bài học" required error={errors.title}>
        <TextInput size="sm" className="max-sm:h-11" value={title} maxLength={NAME_MAX} onChange={(e) => setTitle(e.target.value)} />
      </Field>
      <Checkbox
        id={`preview-${lesson.id}`}
        label="Cho xem thử"
        description="Học sinh chưa mua vẫn xem được bài này."
        checked={preview}
        onChange={(e) => setPreview(e.target.checked)}
      />

      <fieldset className="flex flex-col gap-2" disabled={uploading}>
        <legend className="mb-1 text-sm font-semibold text-ink">Nguồn video</legend>
        <div className="grid grid-cols-2 gap-2">
          {(
            [
              { v: "upload", label: "Tải video lên" },
              { v: "external_link", label: "Dán link YouTube/Vimeo" },
            ] as const
          ).map((o) => {
            const disabled = (o.v === "external_link" && !externalAllowed) || uploading;
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
        {!externalAllowed ? <p className="text-sm text-ink-soft">Chỉ bài cho xem thử mới được dùng link ngoài. Bài trả phí phải tải video lên hệ thống.</p> : null}
      </fieldset>

      {effective === "external_link" ? (
        <Field label="Link video" required error={errors.external_url} hint="YouTube hoặc Vimeo công khai (không nhận link Vimeo riêng tư).">
          <TextInput size="sm" className="max-sm:h-11" type="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://www.youtube.com/watch?v=…" />
        </Field>
      ) : (
        <VideoPanel courseId={courseId} lesson={lesson} manager={manager} />
      )}

      <div className="flex justify-end gap-2 border-t border-line pt-4">
        <Button variant="secondary" size="sm" className="max-sm:h-11" type="button" onClick={onCancel}>
          Huỷ
        </Button>
        <Button size="sm" className="max-sm:h-11" type="submit" loading={busy} loadingText="Đang lưu…" disabled={uploading}>
          Lưu bài học
        </Button>
      </div>
      {uploading ? <p className="-mt-3 text-right text-xs text-ink-soft">Lưu được khi tải video xong hoặc huỷ tải lên.</p> : null}
    </form>
  );
}

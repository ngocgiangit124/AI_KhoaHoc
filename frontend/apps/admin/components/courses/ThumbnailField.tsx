"use client";

import { useId, useRef, useState } from "react";
import { CourseCover, IconAlertCircle, IconImage } from "@vitaminvui/ui/v2";
import { checkImageDimensions, checkImageFile, THUMBNAIL_ACCEPT } from "@/lib/courses/image";

export interface ThumbnailFieldProps {
  /** Ảnh đang có trên server (khi sửa). */
  currentUrl?: string | null;
  /** Dữ liệu dựng bìa dự phòng khi chưa có ảnh/ảnh lỗi. */
  cover: { title: string; gradeLevel: number; subjectSlug?: string };
  file: File | null;
  onChange: (file: File | null) => void;
  /** Lỗi từ server/validate form (ưu tiên hơn lỗi chọn file cục bộ). */
  error?: string;
  required?: boolean;
  disabled?: boolean;
}

/** Xem trước bằng data: URL (CSP admin chỉ cho img-src 'self' data: STATIC_URL, không có blob:). */
function readDataUrl(file: Blob): Promise<string | null> {
  return new Promise((resolve) => {
    const reader = new FileReader();
    reader.onerror = () => resolve(null);
    reader.onload = () => resolve(typeof reader.result === "string" ? reader.result : null);
    reader.readAsDataURL(file);
  });
}

function readDimensions(url: string): Promise<{ width: number; height: number } | null> {
  return new Promise((resolve) => {
    const img = new Image();
    img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
    img.onerror = () => resolve(null);
    img.src = url;
  });
}

/** Thẻ "Ảnh bìa" (design v2): xem trước, kiểm định dạng thật (byte đầu)/2 MB/4000px ngay ở client. Server vẫn mã hoá lại thành WebP. */
export function ThumbnailField({ currentUrl, cover, file, onChange, error, required, disabled }: ThumbnailFieldProps) {
  const id = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [localError, setLocalError] = useState<string | null>(null);
  const [currentBroken, setCurrentBroken] = useState(false);

  async function onPick(picked: File | undefined) {
    if (!picked) return;
    setLocalError(null);
    const basic = await checkImageFile(picked);
    if (basic) return reject(basic);
    const url = await readDataUrl(picked);
    const dims = url ? await readDimensions(url) : null;
    if (!url || !dims) return reject("Không đọc được ảnh. Vui lòng chọn ảnh JPG/PNG/WebP khác.");
    const dimError = checkImageDimensions(dims.width, dims.height);
    if (dimError) return reject(dimError);
    setPreviewUrl(url);
    onChange(picked);
  }

  function reject(message: string) {
    setLocalError(message);
    if (inputRef.current) inputRef.current.value = "";
  }

  const shown = (file ? previewUrl : null) ?? (currentBroken ? null : (currentUrl ?? null));
  const message = localError ?? error;
  const hasImage = Boolean(shown);

  return (
    <section aria-labelledby={`${id}-title`} className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
      <h2 id={`${id}-title`} className="text-base font-semibold text-ink">
        Ảnh bìa
        {required ? (
          <>
            {" "}
            <span className="text-danger" aria-hidden="true">
              *
            </span>
            <span className="sr-only"> (bắt buộc)</span>
          </>
        ) : null}
      </h2>
      {hasImage ? (
        <div className="overflow-hidden rounded-control border border-line">
          <CourseCover
            title={cover.title || "mới"}
            gradeLevel={cover.gradeLevel}
            subjectSlug={cover.subjectSlug}
            size="card"
            image={
              // eslint-disable-next-line @next/next/no-img-element -- data: URL xem trước/ảnh tĩnh, không qua trình tối ưu ảnh
              <img
                src={shown!}
                alt={file ? "Xem trước ảnh bìa mới" : "Ảnh bìa hiện tại"}
                className="size-full object-cover"
                onError={() => !file && setCurrentBroken(true)}
              />
            }
          />
        </div>
      ) : (
        <div className="flex aspect-video flex-col items-center justify-center gap-1 rounded-control border border-dashed border-line-strong bg-sunken px-3 text-center text-sm text-ink-soft">
          <IconImage />
          {currentUrl ? "Không tải được ảnh hiện tại" : required ? "Chưa có ảnh bìa (bắt buộc khi tạo)" : "Chưa có ảnh bìa"}
        </div>
      )}
      <label
        className={`focus-ring flex min-h-11 items-center justify-center gap-2 rounded-control border border-dashed border-line-strong px-3 text-sm font-semibold text-primary has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-focus ${
          disabled ? "cursor-not-allowed opacity-60" : "cursor-pointer hover:bg-primary-soft"
        }`}
      >
        <IconImage size={18} />
        {currentUrl || file ? "Chọn ảnh khác" : "Chọn ảnh bìa"}
        <input
          ref={inputRef}
          type="file"
          name="thumbnail"
          accept={THUMBNAIL_ACCEPT}
          disabled={disabled}
          aria-invalid={message ? true : undefined}
          aria-describedby={`${id}-hint${message ? ` ${id}-error` : ""}`}
          onChange={(e) => void onPick(e.target.files?.[0])}
          className="sr-only"
        />
      </label>
      {file ? (
        <button
          type="button"
          className="focus-ring min-h-11 self-start rounded px-1 text-sm font-semibold text-primary underline disabled:opacity-60"
          disabled={disabled}
          onClick={() => {
            setLocalError(null);
            if (inputRef.current) inputRef.current.value = "";
            setPreviewUrl(null);
            onChange(null);
          }}
        >
          Bỏ ảnh vừa chọn
        </button>
      ) : null}
      <p id={`${id}-hint`} className="text-sm text-ink-soft">
        JPG, PNG hoặc WebP, tối đa 2 MB, không quá 4000×4000 px, nên dùng tỉ lệ 16:9. Không nhận SVG/GIF. Máy chủ nén lại thành WebP và bỏ thông tin EXIF khi lưu.
      </p>
      {message ? (
        <p id={`${id}-error`} role="alert" className="flex items-start gap-1.5 text-sm font-medium text-danger">
          <IconAlertCircle size={16} className="mt-0.5" />
          <span>{message}</span>
        </p>
      ) : null}
    </section>
  );
}

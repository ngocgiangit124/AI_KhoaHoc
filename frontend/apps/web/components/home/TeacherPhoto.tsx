"use client";

import Image from "next/image";
import { initials } from "@vitaminvui/ui/v2";
import { teacherPhotoAlt } from "@/lib/home/teachers";
import { useImageFailed } from "@/lib/useImageFailed";

/**
 * Ảnh giáo viên trong ô vuông của `TeacherCard` (ô đã `relative overflow-hidden`). Ảnh lỗi tải -> chữ cái đầu trên ô vở,
 * không để icon ảnh vỡ (US-020 AC17). `unoptimized`: ảnh đã được backend mã hoá lại WebP ≤ 800 px và phục vụ từ STATIC_URL.
 */
export function TeacherPhoto({ url, name }: { url: string; name: string }) {
  const { failed, ref, onError } = useImageFailed(url);
  if (failed) {
    return (
      <div aria-hidden="true" className="bg-oly flex size-full items-center justify-center bg-primary-soft">
        <span className="text-heading-lg font-extrabold text-primary">{initials(name)}</span>
      </div>
    );
  }
  return (
    <Image
      ref={ref}
      src={url}
      alt={teacherPhotoAlt(name)}
      fill
      unoptimized
      sizes="96px"
      onError={onError}
      className="object-cover"
    />
  );
}

"use client";

import Image from "next/image";
import { useImageFailed } from "@/lib/useImageFailed";

/**
 * Ảnh khóa học đặt vào `CourseCover` (khung 16:9 `relative overflow-hidden`). Chỉ gọi khi có `thumbnail_url`;
 * khóa chưa có ảnh dùng bìa dựng sẵn của `CourseCover` (design-system-v2 §9). Ảnh lỗi tải -> nền vở ô ly trơn thay cho
 * ảnh (không icon ảnh vỡ; `CourseCover` bỏ khối dựng sẵn khi đã nhận `image` nên không lộ lại được).
 * `unoptimized`: ảnh đã được backend xử lý lại (Intervention) và phục vụ từ STATIC_URL/CDN; tránh bộ tối ưu của
 * Next.js tự gọi nguồn ảnh (bị chặn IP nội bộ ở local/Docker).
 */
export function CourseImage({ url, priority = false, sizes }: { url: string; priority?: boolean; sizes?: string }) {
  const { failed, ref, onError } = useImageFailed(url);
  if (failed) return <div aria-hidden="true" className="bg-oly absolute inset-0 bg-primary-soft" />;
  return (
    <Image
      ref={ref}
      src={url}
      alt=""
      fill
      unoptimized
      priority={priority}
      sizes={sizes ?? "(min-width: 1280px) 384px, (min-width: 640px) 50vw, 100vw"}
      onError={onError}
      className="object-cover"
    />
  );
}

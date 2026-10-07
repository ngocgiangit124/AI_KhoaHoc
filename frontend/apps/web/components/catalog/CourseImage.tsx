import Image from "next/image";

/**
 * Ảnh khóa học đặt vào `CourseCover` (khung 16:9 `relative overflow-hidden`). Chỉ gọi khi có `thumbnail_url`;
 * khóa chưa có ảnh dùng bìa dựng sẵn của `CourseCover` (design-system-v2 §9).
 * `unoptimized`: ảnh đã được backend xử lý lại (Intervention) và phục vụ từ STATIC_URL/CDN; tránh bộ tối ưu của
 * Next.js tự gọi nguồn ảnh (bị chặn IP nội bộ ở local/Docker).
 */
export function CourseImage({ url, priority = false, sizes }: { url: string; priority?: boolean; sizes?: string }) {
  return (
    <Image
      src={url}
      alt=""
      fill
      unoptimized
      priority={priority}
      sizes={sizes ?? "(min-width: 1280px) 384px, (min-width: 640px) 50vw, 100vw"}
      className="object-cover"
    />
  );
}

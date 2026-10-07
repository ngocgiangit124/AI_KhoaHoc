import { env } from "@/env";
import type { CourseDetail } from "./schemas";

export function absoluteUrl(path: string): string {
  return new URL(path, env.NEXT_PUBLIC_SITE_URL).toString();
}

/** Mô tả SEO mặc định cho `/lop-{grade}` (MVP hard-code — US-002 §5, chờ content/PO duyệt câu chữ). */
export function gradeIntro(grade: number): string {
  return `Danh sách khóa học Toán lớp ${grade} trên VitaminVui: bài giảng video, bài tập trắc nghiệm và lộ trình học bám sát chương trình. Chọn chuyên đề để lọc nhanh khóa học phù hợp với bạn.`;
}

/** Cắt mô tả meta ở ranh giới từ, tối đa ~160 ký tự. */
export function truncateDescription(text: string, max = 160): string {
  const clean = text.replace(/\s+/g, " ").trim();
  if (clean.length <= max) return clean;
  const cut = clean.slice(0, max - 1);
  const lastSpace = cut.lastIndexOf(" ");
  return `${(lastSpace > max * 0.6 ? cut.slice(0, lastSpace) : cut).trimEnd()}…`;
}

/**
 * JSON-LD schema.org/Course cho trang chi tiết (SEO). `purchasable=false` (khóa có phí khi thanh toán tạm khoá):
 * khai `PreOrder` thay vì `InStock` để công cụ tìm kiếm không hiểu nhầm là mua được ngay.
 */
export function courseJsonLd(course: CourseDetail, purchasable = true): Record<string, unknown> {
  return {
    "@context": "https://schema.org",
    "@type": "Course",
    name: course.title,
    description: course.short_description ?? undefined,
    url: absoluteUrl(`/khoa-hoc/${course.slug}`),
    image: course.thumbnail_url ?? undefined,
    inLanguage: "vi",
    provider: { "@type": "Organization", name: "VitaminVui", url: absoluteUrl("/") },
    educationalLevel: `Lớp ${course.grade_level}`,
    instructor: course.teachers.map((t) => ({ "@type": "Person", name: t.name })),
    offers: {
      "@type": "Offer",
      price: course.price,
      priceCurrency: "VND",
      availability: purchasable || course.price === 0 ? "https://schema.org/InStock" : "https://schema.org/PreOrder",
      url: absoluteUrl(`/khoa-hoc/${course.slug}`),
    },
  };
}

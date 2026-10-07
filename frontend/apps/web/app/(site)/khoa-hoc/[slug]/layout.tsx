import { notFound } from "next/navigation";
import { CatalogBusy } from "@/components/catalog/CatalogBusy";
import { fetchCourse } from "@/lib/catalog/api";
import { isUpstreamBusy } from "@/lib/catalog/busy";

/**
 * Khóa không tồn tại/không published -> HTTP 404 thật. Kiểm ở layout (ngoài ranh giới
 * `loading.tsx`) vì `notFound()` trong page sau khi shell đã stream chỉ ra status 200.
 * `fetchCourse` bọc `cache()` + Data Cache nên page/generateMetadata không gọi API thêm.
 * API bận (429/5xx/mất mạng) -> "Hệ thống đang bận" (ADR-004 §2.8), không lộ lỗi 500 trần.
 */
export default async function CourseLayout({ children, params }: LayoutProps<"/khoa-hoc/[slug]">) {
  const { slug } = await params;
  try {
    if (!(await fetchCourse(slug))) notFound();
  } catch (err) {
    if (isUpstreamBusy(err)) return <CatalogBusy href={`/khoa-hoc/${encodeURIComponent(slug)}`} />;
    throw err;
  }
  return children;
}

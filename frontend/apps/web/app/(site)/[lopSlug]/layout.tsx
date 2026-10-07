import { notFound } from "next/navigation";
import { parseGradeSegment } from "@/lib/catalog/query";

/**
 * Kiểm `lop-{grade}` ở layout (nằm NGOÀI ranh giới `loading.tsx`) để `notFound()` trả đúng
 * HTTP 404. Nếu chỉ gọi trong page, shell đã stream với 200 kèm skeleton (không phải 404 thật
 * cho bot/SEO). Grade ngoài 6–12 -> API sẽ trả 422, nên FE chặn trước.
 */
export default async function GradeLayout({ children, params }: LayoutProps<"/[lopSlug]">) {
  if (parseGradeSegment((await params).lopSlug) === null) notFound();
  return children;
}

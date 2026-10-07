import { fetchAllPublishedCourses } from "@/lib/catalog/api";
import { courseEntry, renderSitemap, staticEntries } from "@/lib/catalog/sitemap";

/**
 * Không prerender lúc build (build có thể chạy khi backend không tới được -> sitemap thiếu khóa bị cache): render mỗi request,
 * dữ liệu qua `publicFetch` có Data Cache (`revalidate: 3600`, tag `catalog` — xem `fetchAllPublishedCourses`), CDN/Nginx cache
 * theo `Cache-Control`.
 */
export const dynamic = "force-dynamic";

export async function GET() {
  const entries = staticEntries();
  try {
    const courses = await fetchAllPublishedCourses();
    for (const c of courses) entries.push(courseEntry(c.slug, c.published_at));
  } catch (err) {
    // API lỗi/429: KHÔNG trả sitemap thiếu khóa (sẽ bị cache 1 giờ và bot đọc nhầm). 503 + Retry-After + no-store để bot thử lại.
    console.error("[sitemap] không lấy được danh sách khóa học:", err instanceof Error ? err.name : "lỗi lạ");
    return new Response("Sitemap tạm thời chưa sẵn sàng.", {
      status: 503,
      headers: { "Retry-After": "300", "Cache-Control": "no-store", "Content-Type": "text/plain; charset=utf-8" },
    });
  }
  return new Response(renderSitemap(entries), {
    headers: {
      "Content-Type": "application/xml; charset=utf-8",
      "Cache-Control": "public, s-maxage=3600, stale-while-revalidate=600",
    },
  });
}

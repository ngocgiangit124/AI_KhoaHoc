import "server-only";
import { isIP } from "node:net";
import { headers } from "next/headers";
import { cache } from "react";
import { ApiError } from "@vitaminvui/api-client";
import { publicFetchServer, type PublicFetchServerOptions } from "@/lib/api.server";
import { parsePublicConfig, type PublicConfig } from "@/lib/types/config";
import {
  courseDetailSchema,
  courseListSchema,
  subjectListSchema,
  type CourseDetail,
  type CourseList,
  type Subject,
} from "./schemas";
import { isValidCourseSlug, toApiQueryString, type CatalogQuery } from "./query";

/** Data Cache 60 giây, khớp `Cache-Control: public, max-age=60` của Laravel (ADR-004 §2.5/§2.7). */
const CATALOG_CACHE: PublicFetchServerOptions = { revalidate: 60, tags: ["catalog"] };

/**
 * IP khách thật cho `X-Client-IP`. Nginx production ghi ĐÈ `X-Forwarded-For` bằng `$remote_addr` (không nối chuỗi do
 * khách gửi — infra/production/nginx/conf.d/vitaminvui.conf) nên giá trị đầu tiên là tin được; dev không có header -> null.
 */
async function clientIp(): Promise<string | null> {
  const h = await headers();
  const forwarded = h.get("x-forwarded-for")?.split(",")[0]?.trim() ?? h.get("x-real-ip")?.trim();
  return forwarded && isIP(forwarded) !== 0 ? forwarded : null;
}

/** `/config/public` (Data Cache 60 s): trang danh mục/chi tiết cần `paid_checkout_enabled` để ẩn lối mua. */
export const fetchPublicConfig = cache(async (): Promise<PublicConfig> => {
  const raw = await publicFetchServer<unknown>("/api/v1/config/public", { revalidate: 60, tags: ["config"] });
  return parsePublicConfig(raw);
});

export async function fetchSubjects(): Promise<Subject[]> {
  const raw = await publicFetchServer<unknown>("/api/v1/subjects", CATALOG_CACHE);
  return subjectListSchema.parse(raw).data;
}

export async function fetchCourses(query: CatalogQuery): Promise<CourseList> {
  const qs = toApiQueryString(query);
  // Tìm kiếm tự do (`q`) có vô số tổ hợp -> dễ bị cào dồn vào một bucket: tính theo IP khách (xem lib/api.server.ts
  // vì sao KHÔNG gắn IP cho mọi request). Các truy vấn còn lại dùng chung cache theo URL.
  const raw = await publicFetchServer<unknown>(`/api/v1/courses${qs ? `?${qs}` : ""}`, {
    ...CATALOG_CACHE,
    clientIp: query.q ? await clientIp() : null,
  });
  return courseListSchema.parse(raw);
}

/**
 * Chi tiết khóa học; slug sai định dạng hoặc API 404 -> `null` (trang gọi `notFound()`).
 * Bọc `cache()` để `generateMetadata` và trang dùng chung 1 lần gọi trong cùng request.
 */
export const fetchCourse = cache(async (slug: string): Promise<CourseDetail | null> => {
  if (!isValidCourseSlug(slug)) return null;
  try {
    const raw = await publicFetchServer<unknown>(`/api/v1/courses/${encodeURIComponent(slug)}`, CATALOG_CACHE);
    return courseDetailSchema.parse(raw);
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) return null;
    throw err;
  }
});

const SITEMAP_MAX_PAGES = 100;

/** Duyệt hết các trang `/courses` (25/trang) cho sitemap; dừng ở trang cuối hoặc trần an toàn. */
export async function fetchAllPublishedCourses(): Promise<{ slug: string; published_at: string | null }[]> {
  const out: { slug: string; published_at: string | null }[] = [];
  for (let page = 1; page <= SITEMAP_MAX_PAGES; page++) {
    const raw = await publicFetchServer<unknown>(`/api/v1/courses${page > 1 ? `?page=${page}` : ""}`, {
      revalidate: 3600,
      tags: ["catalog"],
    });
    const list = courseListSchema.parse(raw);
    for (const c of list.data) out.push({ slug: c.slug, published_at: c.published_at });
    if (page >= list.meta.last_page) break;
  }
  return out;
}

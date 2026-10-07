import "server-only";
import { publicFetchServer } from "@/lib/api.server";
import { fetchCourses, fetchPublicConfig } from "@/lib/catalog/api";
import type { CourseListItem } from "@/lib/catalog/schemas";
import { homeTeachersSchema, type HomeTeacherRow } from "./schemas";
import { FEATURED_COURSES_MAX } from "./teachers";

/** Khu giáo viên: Data Cache 60 giây công khai, tag `teachers` (api-contract §2.9: `Cache-Control: public, max-age=60`). */
export async function fetchHomeTeachers(): Promise<HomeTeacherRow[]> {
  const raw = await publicFetchServer<unknown>("/api/v1/home/teachers", { revalidate: 60, tags: ["teachers"] });
  return homeTeachersSchema.parse(raw).data;
}

/**
 * Khóa nổi bật = 4 khóa đầu của `GET /courses?sort=featured` (US-019 BR3). Dùng lại `fetchCourses` nên chung Data Cache
 * (URL, tag `catalog`) với trang danh mục `?sort=featured` và cùng quy tắc header nội bộ ADR-004 §2.8.
 */
export async function fetchFeaturedCourses(): Promise<CourseListItem[]> {
  const list = await fetchCourses({ grade: null, subjectIds: [], teacherId: null, q: "", sort: "featured", page: 1 });
  return list.data.slice(0, FEATURED_COURSES_MAX);
}

/** `paid_checkout_enabled`; lỗi cấu hình -> coi là tắt (thận trọng: thẻ có phí ghi "Sắp mở bán", không có lối mua). */
export async function fetchPaidCheckoutEnabled(): Promise<boolean> {
  try {
    return (await fetchPublicConfig()).paid_checkout_enabled;
  } catch {
    return false;
  }
}

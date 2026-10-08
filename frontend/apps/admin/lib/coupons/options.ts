import { listCourses } from "@/lib/courses/api";
import { listSubjects } from "@/lib/subjects/api";
import type { PickedCourse } from "./form";

export interface SubjectChoice {
  id: number;
  name: string;
  hidden: boolean;
}

const MAX_PAGES = 20;

/** Mọi chuyên đề kể cả đang ẩn (API cho chọn chuyên đề ẩn vào phạm vi mã). */
export async function listAllSubjects(signal?: AbortSignal): Promise<SubjectChoice[]> {
  const out: SubjectChoice[] = [];
  for (let page = 1; page <= MAX_PAGES; page++) {
    const res = await listSubjects({ q: "", status: "", page, perPage: 50 }, { includeStatus: true, signal });
    out.push(...res.data.map((s) => ({ id: s.id, name: s.name, hidden: s.status === "hidden" })));
    if (page >= res.meta.last_page) break;
  }
  return out;
}

export interface CourseChoice extends PickedCourse {
  status: "draft" | "published" | "unpublished";
}

/** Tìm khóa theo tên (trang đầu, 25 kết quả). */
export async function searchCourses(q: string, signal?: AbortSignal): Promise<CourseChoice[]> {
  const res = await listCourses({ q, status: "", gradeLevel: null, subjectId: null, teacherId: null, page: 1, perPage: 25 }, { isStaff: true, signal });
  return res.data.map((c) => ({ id: c.id, title: c.title, status: c.status }));
}

/** Giá khóa rẻ nhất đang bán (xuất bản, giá > 0) để báo sớm mã "giảm hết" (S18). Tối đa 4 trang × 50; null nếu chưa có khóa. */
export async function cheapestPublishedPrice(signal?: AbortSignal): Promise<number | null> {
  const hit = cheapestCache;
  if (hit && Date.now() - hit.at < CHEAPEST_TTL_MS) return hit.value;
  let min: number | null = null;
  try {
    min = await scanCheapest(signal);
  } catch (err) {
    // Hủy do rời trang: không ghi cache. Lỗi khác (kể cả 429): nhớ "không biết" trong TTL để không quét lại liên tục; server vẫn chốt S18.
    if (!signal?.aborted) cheapestCache = { at: Date.now(), value: null };
    throw err;
  }
  cheapestCache = { at: Date.now(), value: min };
  return min;
}

const CHEAPEST_TTL_MS = 60_000;
let cheapestCache: { at: number; value: number | null } | null = null;
/** Chỉ cho test: xoá cache quét giá. */
export function resetCheapestCache() {
  cheapestCache = null;
}

async function scanCheapest(signal?: AbortSignal): Promise<number | null> {
  let min: number | null = null;
  for (let page = 1; page <= 4; page++) {
    const res = await listCourses({ q: "", status: "published", gradeLevel: null, subjectId: null, teacherId: null, page, perPage: 50 }, { isStaff: true, signal });
    for (const c of res.data) if (c.price > 0 && (min === null || c.price < min)) min = c.price;
    if (page >= res.meta.last_page) break;
  }
  return min;
}

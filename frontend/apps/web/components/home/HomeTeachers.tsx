import { TeacherSection } from "@/components/v2/home/TeacherSection";
import type { HomeTeacherRow } from "@/lib/home/schemas";
import { toHomeTeachers } from "@/lib/home/teachers";
import { routes } from "@/lib/routes";
import { TeacherPhoto } from "./TeacherPhoto";

/**
 * "Thầy cô giảng dạy" của trang chủ thật (US-020 FW9). Nhận hàng API (hoặc `null` khi API lỗi/quá chậm) — rỗng hay lỗi
 * đều KHÔNG render gì: không tiêu đề, không khung trống, không khoảng trắng (BR10, AC15). Bố cục/độ rộng thẻ theo
 * `TeacherSection` của designer (1 người → thẻ rộng vừa; 1 → 2 → 3 cột).
 */
export function HomeTeachers({ rows }: { rows: HomeTeacherRow[] | null }) {
  if (!rows || rows.length === 0) return null;
  return (
    <TeacherSection
      teachers={toHomeTeachers(rows)}
      hrefFor={(id) => `${routes.catalog}?teacher_id=${id}`}
      renderImage={(t) => (t.avatar_url ? <TeacherPhoto url={t.avatar_url} name={t.name} /> : undefined)}
    />
  );
}

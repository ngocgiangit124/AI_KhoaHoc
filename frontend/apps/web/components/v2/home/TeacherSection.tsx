import type { ReactNode } from "react";
import { TeacherCard, type HomeTeacher } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

/**
 * "Thầy cô giảng dạy" (US-019 BR7 + US-020 BR1, BR10). Ngay sau "Khóa học nổi bật".
 * - Không có ai (hoặc API lỗi/chưa có) → không render gì: không tiêu đề, không khung trống.
 * - 1 người: một thẻ rộng vừa phải, không lưới trống.
 * - Nhiều người: 1 cột (mobile) → 2 cột (≥ 768px) → 3 cột (≥ 1024px); tối đa 6. Không carousel, không cuộn ngang.
 */
export function TeacherSection({
  teachers,
  hrefFor = (id) => routes.catalogQuery({ teacher_id: id }),
  renderImage,
}: {
  teachers: HomeTeacher[];
  /** Đích "Xem N khóa học"; mặc định là danh mục xem trước `/v2`. Trang thật (FW9) truyền `/khoa-hoc?teacher_id=`. */
  hrefFor?: (teacherId: number) => string;
  /** Ảnh thật của giáo viên (FW9); không truyền / trả `undefined` → chữ cái đầu trên ô vở. */
  renderImage?: (teacher: HomeTeacher) => ReactNode;
}) {
  if (teachers.length === 0) return null;
  const list = teachers.slice(0, 6);
  return (
    <section aria-labelledby="giao-vien-title" className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
      <h2 id="giao-vien-title" className="text-heading font-extrabold tracking-heading text-ink md:text-heading-lg">
        Thầy cô giảng dạy
      </h2>
      <p className="mt-1 text-base text-ink-soft">Những thầy cô đang có khóa học trên VitaminVui.</p>
      {list.length === 1 ? (
        <div className="mt-6 max-w-2xl">
          <TeacherCard teacher={list[0] as HomeTeacher} coursesHref={hrefFor((list[0] as HomeTeacher).id)} image={renderImage?.(list[0] as HomeTeacher)} />
        </div>
      ) : (
        <ul className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {list.map((t) => (
            <li key={t.id} className="min-w-0">
              <TeacherCard teacher={t} coursesHref={hrefFor(t.id)} image={renderImage?.(t)} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

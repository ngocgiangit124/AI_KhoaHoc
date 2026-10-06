import { TeacherCard, type HomeTeacher } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

/**
 * "Thầy cô giảng dạy" (US-019 BR7 + US-020 BR1, BR10). Ngay sau "Khóa học nổi bật".
 * - Không có ai (hoặc API lỗi/chưa có) → không render gì: không tiêu đề, không khung trống.
 * - 1 người: một thẻ rộng vừa phải, không lưới trống.
 * - Nhiều người: 1 cột (mobile) → 2 cột (≥ 768px) → 3 cột (≥ 1024px); tối đa 6. Không carousel, không cuộn ngang.
 */
export function TeacherSection({ teachers }: { teachers: HomeTeacher[] }) {
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
          <TeacherCard teacher={list[0] as HomeTeacher} coursesHref={routes.catalogQuery({ teacher_id: (list[0] as HomeTeacher).id })} />
        </div>
      ) : (
        <ul className="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {list.map((t) => (
            <li key={t.id}>
              {/* TODO(dev): có avatar_url → image={<Image src={t.avatar_url} alt={`Ảnh thầy/cô ${t.name}`} fill sizes="96px" className="object-cover" />}; ảnh lỗi → bỏ image để hiện chữ cái đầu. */}
              <TeacherCard teacher={t} coursesHref={routes.catalogQuery({ teacher_id: t.id })} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

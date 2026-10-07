import Image from "next/image";
import { Avatar } from "@vitaminvui/ui/v2";
import type { CourseDetail } from "@/lib/catalog/schemas";

/** Tất cả giáo viên phụ trách (AC6): ảnh (hoặc chữ cái đầu khi `avatar_url` null) + tên + mô tả ngắn (ẩn khi `bio` null). */
export function TeacherList({ teachers }: { teachers: CourseDetail["teachers"] }) {
  if (teachers.length === 0) return null;
  return (
    <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      {teachers.map((t) => (
        <li key={t.id} className="flex min-w-0 gap-3">
          {t.avatar_url ? (
            <Image src={t.avatar_url} alt={`Ảnh thầy/cô ${t.name}`} width={56} height={56} unoptimized className="size-14 shrink-0 rounded-full object-cover" />
          ) : (
            <Avatar name={t.name} size="lg" />
          )}
          <div className="min-w-0">
            <p className="font-semibold text-ink">{t.name}</p>
            {t.bio ? <p className="whitespace-pre-line text-sm text-ink-soft">{t.bio}</p> : null}
          </div>
        </li>
      ))}
    </ul>
  );
}

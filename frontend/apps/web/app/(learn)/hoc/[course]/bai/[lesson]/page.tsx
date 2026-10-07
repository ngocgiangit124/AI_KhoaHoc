import { notFound } from "next/navigation";
import { LessonScreen } from "@/components/learn/LessonScreen";

/** Id trong URL là số nguyên dương; sai định dạng → 404 thật (không gọi API). */
function parseId(raw: string): number | null {
  return /^[1-9]\d{0,9}$/.test(raw) ? Number(raw) : null;
}

export default async function LessonPage({ params }: PageProps<"/hoc/[course]/bai/[lesson]">) {
  const { course, lesson } = await params;
  const courseId = parseId(course);
  const lessonId = parseId(lesson);
  if (courseId === null || lessonId === null) notFound();
  return <LessonScreen key={`${courseId}-${lessonId}`} courseId={courseId} lessonId={lessonId} />;
}

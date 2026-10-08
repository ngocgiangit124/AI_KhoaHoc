import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { CourseProgressScreen } from "@/components/my/CourseProgressScreen";
import { parseCourseId } from "@/lib/my/errors";

export const metadata: Metadata = { title: "Tiến độ khóa học — VitaminVui", robots: { index: false, follow: false } };

export const dynamic = "force-dynamic";

export default async function CourseProgressPage({ params }: PageProps<"/tai-khoan/khoa-hoc-cua-toi/[course]">) {
  const { course } = await params;
  const id = parseCourseId(course);
  if (id === null) notFound();
  return <CourseProgressScreen courseId={id} />;
}

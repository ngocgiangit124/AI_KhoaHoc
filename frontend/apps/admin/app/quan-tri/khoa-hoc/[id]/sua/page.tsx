import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { CourseEditScreen } from "@/components/courses/CourseEditScreen";

export const metadata: Metadata = { title: "Sửa khóa học — VitaminVui Quản trị" };

export default async function EditCoursePage({ params }: PageProps<"/quan-tri/khoa-hoc/[id]/sua">) {
  const { id } = await params;
  if (!/^[1-9]\d{0,9}$/.test(id)) notFound();
  return <CourseEditScreen id={Number(id)} />;
}

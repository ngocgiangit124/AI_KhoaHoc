import type { Metadata } from "next";
import { CourseCreateScreen } from "@/components/courses/CourseCreateScreen";

export const metadata: Metadata = { title: "Tạo khóa học — VitaminVui Quản trị" };

export default function CreateCoursePage() {
  return <CourseCreateScreen />;
}

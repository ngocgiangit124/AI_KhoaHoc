import type { Metadata } from "next";
import { Suspense } from "react";
import { Skeleton } from "@vitaminvui/ui/v2";
import { CoursesScreen } from "@/components/courses/CoursesScreen";

export const metadata: Metadata = { title: "Khóa học — VitaminVui Quản trị" };

export default function CoursesPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full" />}>
      <CoursesScreen />
    </Suspense>
  );
}

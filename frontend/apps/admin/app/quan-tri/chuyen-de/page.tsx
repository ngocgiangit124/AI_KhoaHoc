import type { Metadata } from "next";
import { Suspense } from "react";
import { Skeleton } from "@vitaminvui/ui/v2";
import { SubjectsScreen } from "@/components/subjects/SubjectsScreen";

export const metadata: Metadata = { title: "Chuyên đề — VitaminVui Quản trị" };

export default function SubjectsPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full" />}>
      <SubjectsScreen />
    </Suspense>
  );
}

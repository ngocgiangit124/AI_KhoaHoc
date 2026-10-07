import type { Metadata } from "next";
import { Suspense } from "react";
import { Skeleton } from "@vitaminvui/ui/v2";
import { RequireRole } from "@/components/shell/RequireRole";
import { HomepageTeachersScreen } from "@/components/teacher-profiles/HomepageTeachersScreen";

export const metadata: Metadata = { title: "Giáo viên trang chủ — VitaminVui Quản trị" };

export default function HomepageTeachersPage() {
  return (
    <RequireRole roles={["admin", "quan_ly_trang"]}>
      <Suspense fallback={<Skeleton className="h-64 w-full" />}>
        <HomepageTeachersScreen />
      </Suspense>
    </RequireRole>
  );
}

import type { Metadata } from "next";
import { Suspense } from "react";
import { Skeleton } from "@vitaminvui/ui/v2";
import { StaffScreen } from "@/components/staff/StaffScreen";

export const metadata: Metadata = { title: "Tài khoản staff — VitaminVui Quản trị" };

export default function StaffPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full" />}>
      <StaffScreen />
    </Suspense>
  );
}

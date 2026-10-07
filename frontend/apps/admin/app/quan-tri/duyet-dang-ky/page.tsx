import type { Metadata } from "next";
import { Suspense } from "react";
import { Skeleton } from "@vitaminvui/ui/v2";
import { EnrollmentRequestsScreen } from "@/components/enrollment-requests/EnrollmentRequestsScreen";

export const metadata: Metadata = { title: "Duyệt đăng ký — VitaminVui Quản trị" };

/** Cả ba vai trò staff vào được (giáo viên chỉ thấy khóa mình phụ trách — do API lọc). */
export default function EnrollmentRequestsPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full" />}>
      <EnrollmentRequestsScreen />
    </Suspense>
  );
}

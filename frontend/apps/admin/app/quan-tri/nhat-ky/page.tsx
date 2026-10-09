import type { Metadata } from "next";
import { Suspense } from "react";
import { Skeleton } from "@vitaminvui/ui/v2";
import { AuditLogScreen } from "@/components/audit/AuditLogScreen";

export const metadata: Metadata = { title: "Nhật ký thao tác — VitaminVui Quản trị" };

export default function AuditLogPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full" />}>
      <AuditLogScreen />
    </Suspense>
  );
}

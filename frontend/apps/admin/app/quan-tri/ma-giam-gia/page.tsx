import type { Metadata } from "next";
import { Suspense } from "react";
import { Skeleton } from "@vitaminvui/ui/v2";
import { CouponsScreen } from "@/components/coupons/CouponsScreen";

export const metadata: Metadata = { title: "Mã giảm giá — VitaminVui Quản trị" };

export default function CouponsPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full" />}>
      <CouponsScreen />
    </Suspense>
  );
}

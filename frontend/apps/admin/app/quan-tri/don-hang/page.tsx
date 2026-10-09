import type { Metadata } from "next";
import { Suspense } from "react";
import { Skeleton } from "@vitaminvui/ui/v2";
import { OrdersScreen } from "@/components/orders/OrdersScreen";

export const metadata: Metadata = { title: "Đơn hàng — VitaminVui Quản trị" };

export default function OrdersPage() {
  return (
    <Suspense fallback={<Skeleton className="h-64 w-full" />}>
      <OrdersScreen />
    </Suspense>
  );
}

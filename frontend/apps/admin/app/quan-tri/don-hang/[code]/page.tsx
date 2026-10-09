import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { OrderDetailScreen } from "@/components/orders/OrderDetailScreen";

export const metadata: Metadata = { title: "Chi tiết đơn hàng — VitaminVui Quản trị" };

export default async function OrderDetailPage({ params }: PageProps<"/quan-tri/don-hang/[code]">) {
  const { code } = await params;
  if (!/^[A-Za-z0-9]{6,40}$/.test(code)) notFound();
  return <OrderDetailScreen code={code} />;
}

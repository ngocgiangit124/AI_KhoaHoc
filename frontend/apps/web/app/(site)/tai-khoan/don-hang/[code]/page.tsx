import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { OrderDetailScreen } from "@/components/orders/OrderDetailScreen";
import { parseOrderCode } from "@/lib/orders/errors";

export const metadata: Metadata = { title: "Chi tiết đơn hàng — VitaminVui", robots: { index: false, follow: false } };

export const dynamic = "force-dynamic";

export default async function MyOrderPage({ params }: PageProps<"/tai-khoan/don-hang/[code]">) {
  const code = parseOrderCode((await params).code);
  if (!code) notFound();
  return <OrderDetailScreen code={code} />;
}

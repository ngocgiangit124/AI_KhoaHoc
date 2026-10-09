import type { Metadata } from "next";
import { OrdersScreen } from "@/components/orders/OrdersScreen";
import { parsePage } from "@/lib/my/errors";

export const metadata: Metadata = { title: "Đơn hàng của tôi — VitaminVui", robots: { index: false, follow: false } };

export const dynamic = "force-dynamic";

export default async function MyOrdersPage({ searchParams }: PageProps<"/tai-khoan/don-hang">) {
  const sp = await searchParams;
  return <OrdersScreen page={parsePage(sp.trang)} />;
}

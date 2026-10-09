import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { OrderSentScreen } from "@/components/orders/OrderSentScreen";
import { parseOrderCode } from "@/lib/orders/errors";

export const metadata: Metadata = { title: "Đơn đã gửi — VitaminVui", robots: { index: false, follow: false } };

export const dynamic = "force-dynamic";

/** Đích của link trong thư "Đã nhận đơn". `?dung-lai=1`: server dùng lại đơn đang chờ cùng nội dung (không tạo đơn mới). */
export default async function OrderSentPage({ params, searchParams }: PageProps<"/thanh-toan/da-gui/[code]">) {
  const code = parseOrderCode((await params).code);
  if (!code) notFound();
  const sp = await searchParams;
  return <OrderSentScreen code={code} reused={sp["dung-lai"] === "1"} />;
}

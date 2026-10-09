import type { Metadata } from "next";
import { CartScreen } from "@/components/orders/CartScreen";

export const metadata: Metadata = { title: "Giỏ hàng — VitaminVui", robots: { index: false, follow: false } };

export const dynamic = "force-dynamic";

export default function CartPage() {
  return <CartScreen />;
}

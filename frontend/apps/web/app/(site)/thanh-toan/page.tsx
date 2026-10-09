import type { Metadata } from "next";
import { CheckoutScreen } from "@/components/orders/CheckoutScreen";

export const metadata: Metadata = { title: "Thanh toán — VitaminVui", robots: { index: false, follow: false } };

export const dynamic = "force-dynamic";

export default function CheckoutPage() {
  return <CheckoutScreen />;
}

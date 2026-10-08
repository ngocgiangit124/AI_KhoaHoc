import type { Metadata } from "next";
import { CouponCreateScreen } from "@/components/coupons/CouponCreateScreen";

export const metadata: Metadata = { title: "Tạo mã giảm giá — VitaminVui Quản trị" };

export default function CreateCouponPage() {
  return <CouponCreateScreen />;
}

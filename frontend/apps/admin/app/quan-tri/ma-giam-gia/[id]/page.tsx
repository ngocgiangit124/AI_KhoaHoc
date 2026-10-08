import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { CouponEditScreen } from "@/components/coupons/CouponEditScreen";

export const metadata: Metadata = { title: "Sửa mã giảm giá — VitaminVui Quản trị" };

export default async function EditCouponPage({ params }: PageProps<"/quan-tri/ma-giam-gia/[id]">) {
  const { id } = await params;
  if (!/^[1-9]\d{0,9}$/.test(id)) notFound();
  return <CouponEditScreen id={Number(id)} />;
}

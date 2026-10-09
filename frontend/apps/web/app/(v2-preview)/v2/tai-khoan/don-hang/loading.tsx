import { OrdersSkeleton } from "@/components/v2/orders/OrdersSkeleton";

/** Đang tải "Đơn hàng của tôi" (route thật dùng cùng khung). */
export default function Loading() {
  return (
    <div className="mx-auto max-w-3xl px-4 pb-14 pt-6 sm:px-6">
      <div className="h-5 w-40" />
      <h1 className="mt-2 text-title font-extrabold tracking-heading text-ink md:text-title-lg">Đơn hàng của tôi</h1>
      <div className="mt-6">
        <OrdersSkeleton />
      </div>
    </div>
  );
}

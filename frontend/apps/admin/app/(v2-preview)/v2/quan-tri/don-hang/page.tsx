import { ButtonLink, EmptyState, IconReceipt } from "@vitaminvui/ui/v2";
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";

export const dynamic = "force-dynamic";

/** Đơn hàng thuộc V2 (FA8/FA9). Menu đã khoá; trang này chỉ để mở thẳng URL không bị 404. */
export default async function OrdersPlaceholder({ searchParams }: PageProps<"/v2/quan-tri/don-hang">) {
  const sp = await searchParams;
  const role = roleFrom(sp["vai-tro"]);
  return (
    <AdminPreviewShell role={role} current="orders" basePath="/v2/quan-tri/don-hang">
      <EmptyState
        headingLevel="h1"
        icon={<IconReceipt size={32} />}
        title="Đơn hàng sẽ có ở V2"
        description="Thanh toán trực tuyến đang tạm khoá nên chưa có đơn hàng để quản lý. Màn danh sách đơn, chi tiết, hoàn tiền và xuất file được thiết kế khi bật thanh toán."
        action={<ButtonLink href={`/v2/quan-tri/khoa-hoc${role === "admin" ? "" : `?vai-tro=${role}`}`}>Về danh sách khóa học</ButtonLink>}
      />
    </AdminPreviewShell>
  );
}

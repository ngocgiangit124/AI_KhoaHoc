import { LoadingRegion, Skeleton } from "@vitaminvui/ui/v2";

/** Đang tải chi tiết đơn: khung giữ chỗ đúng bố cục (tiêu đề, khối trạng thái, danh sách khóa). */
export default function Loading() {
  return (
    <LoadingRegion label="Đang tải đơn hàng…" className="mx-auto flex max-w-3xl flex-col gap-5 px-4 pb-14 pt-6 sm:px-6">
      <div className="flex flex-col gap-2">
        <Skeleton className="h-5 w-56" />
        <Skeleton className="h-9 w-72 max-w-full" />
        <Skeleton className="h-7 w-48" />
      </div>
      <Skeleton className="h-56 w-full rounded-sheet" />
      <Skeleton className="h-64 w-full rounded-sheet" />
    </LoadingRegion>
  );
}

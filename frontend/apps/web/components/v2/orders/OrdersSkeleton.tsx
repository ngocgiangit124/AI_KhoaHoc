import { LoadingRegion, Skeleton } from "@vitaminvui/ui/v2";

/** Khung giữ chỗ danh sách "Đơn hàng của tôi": đúng chiều cao dòng thật để không giật layout. */
export function OrdersSkeleton({ rows = 4 }: { rows?: number }) {
  return (
    <LoadingRegion label="Đang tải đơn hàng…" className="flex flex-col gap-3">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex gap-3 rounded-sheet border border-line bg-surface p-4 sm:p-5">
          <div className="flex flex-1 flex-col gap-2">
            <Skeleton className="h-6 w-64 max-w-full" />
            <Skeleton className="h-5 w-4/5" />
            <Skeleton className="h-4 w-48" />
          </div>
          <Skeleton className="h-6 w-20" />
        </div>
      ))}
    </LoadingRegion>
  );
}

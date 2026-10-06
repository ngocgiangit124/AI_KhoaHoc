import { LoadingRegion, Skeleton } from "@vitaminvui/ui/v2";

/** Khung đang tải đúng kích thước CourseCard (ảnh 16:9 + 5 dòng). */
export function CourseGridSkeleton({ count = 6, className = "grid gap-4 sm:grid-cols-2 xl:grid-cols-3" }: { count?: number; className?: string }) {
  return (
    <LoadingRegion label="Đang tải danh sách khóa học…" className={className}>
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="overflow-hidden rounded-card border border-line bg-surface">
          <Skeleton className="aspect-video w-full rounded-none" />
          <div className="flex flex-col gap-2 p-4">
            <Skeleton className="h-4 w-24" />
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-5 w-3/4" />
            <Skeleton className="h-4 w-full" />
            <div className="mt-3 flex justify-between border-t border-line pt-3">
              <Skeleton className="h-4 w-24" />
              <Skeleton className="h-6 w-20" />
            </div>
          </div>
        </div>
      ))}
    </LoadingRegion>
  );
}

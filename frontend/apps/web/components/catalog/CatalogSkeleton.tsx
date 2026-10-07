import { LoadingRegion, Skeleton } from "@vitaminvui/ui/v2";

/** Lưới thẻ khung đúng kích thước `CourseCard` (ảnh 16:9 + 5 dòng) — US-002 §3. */
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

/** Đang tải danh mục: giữ đúng bố cục (breadcrumb, tiêu đề, ô tìm, chip lớp, lưới thẻ). */
export function CatalogSkeleton() {
  return (
    <div className="mx-auto w-full max-w-6xl px-4 pb-14 pt-6 sm:px-6">
      <Skeleton className="h-4 w-40" />
      <Skeleton className="mt-4 h-9 w-72" />
      <Skeleton className="mt-6 h-11 w-full max-w-xl" />
      <div className="mt-4 flex gap-2">
        {Array.from({ length: 6 }).map((_, i) => (
          <Skeleton key={i} className="h-11 w-20 rounded-full" />
        ))}
      </div>
      <div className="mt-10">
        <CourseGridSkeleton />
      </div>
    </div>
  );
}

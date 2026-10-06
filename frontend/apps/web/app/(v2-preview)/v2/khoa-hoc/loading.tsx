import { Skeleton } from "@vitaminvui/ui/v2";
import { CourseGridSkeleton } from "@/components/v2/catalog/CatalogSkeleton";

/** Đang tải danh mục: giữ đúng bố cục (tiêu đề, ô tìm, chip lớp, lưới thẻ). */
export default function CatalogLoading() {
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

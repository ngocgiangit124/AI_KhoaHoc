import { LoadingRegion, Skeleton } from "@vitaminvui/ui/v2";

/** Đang tải chi tiết khóa: giữ đúng bố cục (breadcrumb, tiêu đề, cột chính, thẻ hành động). */
export default function Loading() {
  return (
    <LoadingRegion label="Đang tải khóa học…" className="mx-auto w-full max-w-6xl px-4 pb-16 pt-6 sm:px-6">
      <Skeleton className="h-4 w-56" />
      <div className="mt-6 grid gap-8 lg:grid-cols-[1fr_360px]">
        <div className="flex flex-col gap-4">
          <Skeleton className="aspect-video w-full lg:hidden" />
          <Skeleton className="h-6 w-40" />
          <Skeleton className="h-10 w-3/4" />
          <Skeleton className="h-5 w-full max-w-2xl" />
          <Skeleton className="mt-4 h-40 w-full" />
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
        </div>
        <Skeleton className="hidden h-96 lg:block" />
      </div>
    </LoadingRegion>
  );
}

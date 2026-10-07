import { LoadingRegion, Skeleton } from "@vitaminvui/ui/v2";

export default function Loading() {
  return (
    <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-10">
      <LoadingRegion className="flex flex-col gap-4">
        <Skeleton className="h-8 w-72" />
        <Skeleton className="h-48 w-full" />
      </LoadingRegion>
    </main>
  );
}

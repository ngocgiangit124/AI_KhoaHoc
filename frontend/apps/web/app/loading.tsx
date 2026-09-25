import { Skeleton } from "@vitaminvui/ui";

export default function Loading() {
  return (
    <main className="mx-auto max-w-5xl px-4 py-10">
      <Skeleton variant="text" className="h-8 w-72" />
      <Skeleton variant="text" className="mt-2 h-4 w-56" />
      <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
        <Skeleton variant="card" count={4} className="!h-16" />
      </div>
    </main>
  );
}

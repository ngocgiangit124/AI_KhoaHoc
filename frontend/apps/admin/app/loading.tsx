import { Skeleton } from "@vitaminvui/ui";

export default function Loading() {
  return (
    <main className="mx-auto max-w-5xl px-4 py-10">
      <Skeleton variant="text" className="h-8 w-72" />
      <Skeleton variant="card" className="mt-6" />
    </main>
  );
}

export type SkeletonVariant = "text" | "card" | "table-row";

export interface SkeletonProps {
  variant?: SkeletonVariant;
  className?: string;
  /** Số dòng lặp lại (chỉ áp dụng "text"/"table-row"). */
  count?: number;
}

const VARIANT_CLASSES: Record<SkeletonVariant, string> = {
  text: "h-4 w-full rounded",
  card: "h-40 w-full rounded-xl",
  "table-row": "h-10 w-full rounded",
};

/** Khung xám nhấp nháy khi đang tải, đúng layout thật (design-system.md §5.1, §6). */
export function Skeleton({ variant = "text", className = "", count = 1 }: SkeletonProps) {
  return (
    <div className="space-y-2" aria-hidden="true">
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className={`animate-pulse bg-gray-200 ${VARIANT_CLASSES[variant]} ${className}`} />
      ))}
    </div>
  );
}

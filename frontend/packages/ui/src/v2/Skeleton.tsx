import { cx } from "./cx";

export interface SkeletonProps {
  className?: string;
}

/**
 * Khối giữ chỗ khi đang tải: đúng kích thước nội dung thật để không giật layout.
 * Nhấp nháy chỉ khi người dùng không tắt chuyển động.
 */
export function Skeleton({ className }: SkeletonProps) {
  return <div aria-hidden="true" className={cx("rounded-control bg-sunken motion-safe:animate-pulse", className)} />;
}

/** Vùng đang tải: một chữ "Đang tải…" cho trình đọc màn hình + các khối giữ chỗ. */
export function LoadingRegion({ label = "Đang tải…", children, className }: { label?: string; children: React.ReactNode; className?: string }) {
  return (
    <div role="status" aria-live="polite" className={className}>
      <span className="sr-only">{label}</span>
      {children}
    </div>
  );
}

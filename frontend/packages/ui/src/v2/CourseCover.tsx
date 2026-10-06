import type { ReactNode } from "react";
import { cx } from "./cx";

export interface CourseCoverProps {
  title: string;
  gradeLevel: number;
  /** Chuyên đề đầu tiên quyết định ký hiệu và màu nền khi chưa có ảnh. */
  subjectSlug?: string;
  /** Ảnh thật (app truyền `next/image` với `fill`). Có ảnh thì thay khối dựng sẵn. */
  image?: ReactNode;
  /** Nhãn góc phải, ví dụ <Badge tone="free">Miễn phí</Badge>. */
  corner?: ReactNode;
  size?: "card" | "hero" | "thumb";
  className?: string;
}

type Motif = { symbol: string; tint: string; ink: string };

/** Ký hiệu + màu theo chuyên đề (bảng ở design-system-v2 §8). Khớp theo từ khoá trong slug. */
export function coverMotif(slug = ""): Motif {
  if (/hinh|tam-giac|duong-tron|vector/.test(slug)) return { symbol: "△", tint: "bg-accent-soft", ink: "text-accent-ink" };
  if (/giai-tich|ham-so|dao-ham|tich-phan/.test(slug)) return { symbol: "∫", tint: "bg-info-soft", ink: "text-info" };
  if (/xac-suat|thong-ke|to-hop/.test(slug)) return { symbol: "σ", tint: "bg-success-soft", ink: "text-success" };
  if (/on-thi|luyen-de|de-thi/.test(slug)) return { symbol: "Σ", tint: "bg-warning-soft", ink: "text-warning" };
  if (/dai-so|phuong-trinh|bat-phuong|so-hoc|can/.test(slug)) return { symbol: "x²", tint: "bg-primary-soft", ink: "text-primary" };
  return { symbol: "π", tint: "bg-primary-soft", ink: "text-primary" };
}

const SYMBOL_SIZE = { card: "text-6xl", hero: "text-8xl", thumb: "text-2xl" } as const;

/**
 * Bìa khóa học 16:9. Ảnh admin tải lên luôn ưu tiên; khối dựng sẵn (lưới ô ly + ký hiệu Toán) là
 * ảnh thay thế khi khóa cũ chưa có ảnh hoặc ảnh lỗi. Ký hiệu là trang trí (aria-hidden).
 */
export function CourseCover({ title, gradeLevel, subjectSlug, image, corner, size = "card", className }: CourseCoverProps) {
  const motif = coverMotif(subjectSlug);
  return (
    <div className={cx("relative aspect-video w-full overflow-hidden", size === "thumb" ? "rounded-control" : "", className)}>
      {image ? (
        image
      ) : (
        <div className={cx("bg-oly absolute inset-0 flex items-center justify-center", motif.tint)} aria-hidden="true">
          <span className={cx("font-extrabold tracking-heading", SYMBOL_SIZE[size], motif.ink)}>{motif.symbol}</span>
        </div>
      )}
      {size !== "thumb" ? (
        <span className="absolute left-3 top-3 rounded-full bg-surface px-2.5 py-1 text-sm font-semibold text-ink shadow-raised">
          Lớp {gradeLevel}
        </span>
      ) : null}
      {corner ? <span className="absolute right-3 top-3">{corner}</span> : null}
      <span className="sr-only">Ảnh bìa khóa {title}</span>
    </div>
  );
}

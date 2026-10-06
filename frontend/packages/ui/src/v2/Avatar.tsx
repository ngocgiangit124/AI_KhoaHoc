import { cx } from "./cx";

export interface AvatarProps {
  name: string;
  size?: "sm" | "md" | "lg";
  className?: string;
}

const SIZES = { sm: "size-8 text-xs", md: "size-10 text-sm", lg: "size-14 text-lg" } as const;

/** Chữ cái đầu của 2 từ cuối (tên người Việt: "Nguyễn Thu Hà" → "TH"). Ảnh thật để dev thêm khi có `avatar_url`. */
export function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const pick = parts.length >= 2 ? parts.slice(-2) : parts;
  return pick.map((p) => p[0]?.toUpperCase() ?? "").join("");
}

export function Avatar({ name, size = "md", className }: AvatarProps) {
  return (
    <span
      aria-hidden="true"
      className={cx("inline-flex shrink-0 items-center justify-center rounded-full bg-primary-soft font-extrabold text-primary", SIZES[size], className)}
    >
      {initials(name)}
    </span>
  );
}

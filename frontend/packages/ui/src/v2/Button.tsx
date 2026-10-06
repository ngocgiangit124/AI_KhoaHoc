import type { ButtonHTMLAttributes, ReactNode, Ref } from "react";
import { cx } from "./cx";
import { UiLink, type UiLinkProps } from "./Link";
import { Spinner } from "./Spinner";

export type ButtonVariant = "primary" | "secondary" | "ghost" | "danger" | "soft";
export type ButtonSize = "sm" | "md" | "lg";

interface CommonProps {
  variant?: ButtonVariant;
  size?: ButtonSize;
  /** Icon trước chữ (SVG từ ./icons). */
  leadingIcon?: ReactNode;
  trailingIcon?: ReactNode;
  /** Chiếm hết chiều ngang (nút chính trên mobile). */
  block?: boolean;
}

export interface ButtonProps extends CommonProps, ButtonHTMLAttributes<HTMLButtonElement> {
  loading?: boolean;
  /** Chữ thay thế khi đang xử lý, ví dụ "Đang nộp bài…". Mặc định giữ chữ cũ. */
  loadingText?: string;
  ref?: Ref<HTMLButtonElement>;
}

export interface ButtonLinkProps extends CommonProps, UiLinkProps {}

const BASE =
  "focus-ring inline-flex select-none items-center justify-center gap-2 whitespace-nowrap rounded-control font-semibold " +
  "transition-colors duration-150 disabled:cursor-not-allowed aria-disabled:cursor-not-allowed";

const VARIANTS: Record<ButtonVariant, string> = {
  primary:
    "bg-primary text-on-primary hover:bg-primary-hover disabled:bg-line disabled:text-ink-soft aria-disabled:bg-line aria-disabled:text-ink-soft",
  secondary:
    "border border-line-strong bg-surface text-ink hover:border-primary hover:text-primary disabled:border-line disabled:text-ink-soft disabled:hover:text-ink-soft",
  soft: "bg-primary-soft text-primary hover:bg-primary hover:text-on-primary disabled:bg-sunken disabled:text-ink-soft",
  ghost: "text-primary hover:bg-primary-soft disabled:text-ink-soft disabled:hover:bg-transparent",
  danger: "bg-danger text-on-status hover:bg-danger-hover disabled:bg-line disabled:text-ink-soft",
};

/** Cao 36 / 44 / 52px. Trên mobile dùng md trở lên để đủ vùng chạm 44px. */
const SIZES: Record<ButtonSize, string> = {
  sm: "h-9 px-3 text-sm",
  md: "h-11 px-4 text-base",
  lg: "h-13 px-6 text-base",
};

export function buttonClasses({ variant = "primary", size = "md", block = false }: CommonProps = {}): string {
  return cx(BASE, VARIANTS[variant], SIZES[size], block && "w-full");
}

/**
 * Nút v2. Disabled: nền `line` + chữ `ink-soft` (không dùng opacity để chữ vẫn đọc được).
 * Đang xử lý: `aria-busy`, khoá nút, vòng xoay thay icon trước.
 */
export function Button({
  variant,
  size,
  block,
  loading = false,
  loadingText,
  leadingIcon,
  trailingIcon,
  disabled,
  className,
  children,
  type = "button",
  ...rest
}: ButtonProps) {
  return (
    <button
      type={type}
      {...rest}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cx(buttonClasses({ variant, size, block }), className)}
    >
      {loading ? <Spinner className="size-4" label={null} /> : leadingIcon}
      <span>{loading && loadingText ? loadingText : children}</span>
      {loading ? null : trailingIcon}
    </button>
  );
}

/** Liên kết trông như nút (điều hướng, không phải hành động). */
export function ButtonLink({ variant, size, block, leadingIcon, trailingIcon, className, children, ...rest }: ButtonLinkProps) {
  return (
    <UiLink {...rest} className={cx(buttonClasses({ variant, size, block }), className)}>
      {leadingIcon}
      <span>{children}</span>
      {trailingIcon}
    </UiLink>
  );
}

export interface IconButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, "children"> {
  /** Bắt buộc: tên đọc cho trình đọc màn hình. */
  label: string;
  icon: ReactNode;
  variant?: "ghost" | "secondary" | "on-dark";
  size?: "sm" | "md";
}

/** Nút chỉ có icon: luôn có `aria-label`, vùng chạm 44px ở size md. */
export function IconButton({ label, icon, variant = "ghost", size = "md", className, type = "button", ...rest }: IconButtonProps) {
  return (
    <button
      type={type}
      aria-label={label}
      title={label}
      {...rest}
      className={cx(
        "focus-ring inline-flex shrink-0 items-center justify-center rounded-control transition-colors duration-150 disabled:cursor-not-allowed disabled:text-ink-soft",
        size === "md" ? "size-11" : "size-9",
        variant === "ghost" && "text-ink hover:bg-primary-soft hover:text-primary",
        variant === "secondary" && "border border-line-strong bg-surface text-ink hover:border-primary hover:text-primary",
        variant === "on-dark" && "text-player-ink [--vv-focus:var(--vv-player-ink)] hover:bg-white/15",
        className,
      )}
    >
      {icon}
    </button>
  );
}

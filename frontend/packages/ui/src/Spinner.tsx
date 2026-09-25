export interface SpinnerProps {
  /** Tailwind size class, ví dụ "h-5 w-5". Mặc định vừa cho nút. */
  className?: string;
  label?: string;
}

/** Icon xoay dùng trong `<Button loading>` và các khối "Đang tải". */
export function Spinner({ className = "h-4 w-4", label = "Đang tải…" }: SpinnerProps) {
  return (
    <svg
      className={`animate-spin text-current ${className}`}
      viewBox="0 0 24 24"
      fill="none"
      role="status"
      aria-label={label}
    >
      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path
        className="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"
      />
    </svg>
  );
}

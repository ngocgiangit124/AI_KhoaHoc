import type { ReactNode } from "react";
import { Logo } from "@vitaminvui/ui/v2";

/**
 * Khung trang xác thực quản trị (đăng nhập, MFA, đổi mật khẩu lần đầu): logo + thẻ viền `line` + tiêu đề h1.
 * Không có menu (chưa xác thực xong). Mật độ giống khu quản trị, không dùng lưới ô ly.
 */
export function AuthCard({ title, description, children }: { title: string; description?: ReactNode; children: ReactNode }) {
  return (
    <main className="flex min-h-full flex-1 flex-col items-center justify-center gap-6 px-4 py-10">
      <Logo tagline="Quản trị" />
      <div className="w-full max-w-md rounded-sheet border border-line bg-surface p-6 sm:p-8">
        <h1 className="text-heading-lg font-extrabold tracking-heading text-ink">{title}</h1>
        {description ? <div className="mt-2 text-base leading-relaxed text-ink-soft">{description}</div> : null}
        <div className="mt-6">{children}</div>
      </div>
    </main>
  );
}

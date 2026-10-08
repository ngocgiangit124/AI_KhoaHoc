import type { ReactNode } from "react";
import { AccountBanner } from "@/components/auth/AccountBanner";
import { PolicyAcceptanceBanner } from "@/components/privacy/PolicyAcceptanceBanner";
import { AuthProvider } from "@/lib/auth/AuthProvider";
import { ShellFooter } from "./ShellFooter";
import { ShellHeader } from "./ShellHeader";

export interface SiteShellProps {
  children: ReactNode;
  /**
   * `site`: header đầy đủ + banner trạng thái tài khoản + footer; gọi `/auth/me` một lần (AuthProvider).
   * `minimal`: đăng nhập/đăng ký/OTP — header chỉ có logo, không footer, không tự gọi `/auth/me`
   * (trang nào cần thì tự bọc `AuthProvider`).
   */
  variant?: "site" | "minimal";
}

/**
 * Khung trang học sinh thật (design-system-v2 §12.1), dựng từ `StudentShell` của bản xem trước: header dính ·
 * nội dung · footer. Đặt trong `layout.tsx` của nhóm route để khung (và `/auth/me`) không dựng lại mỗi lần chuyển trang.
 * Không có tiền tố `"use client"`: an toàn để dùng cả trong `error.tsx`/`not-found.tsx`.
 */
export function SiteShell({ children, variant = "site" }: SiteShellProps) {
  const minimal = variant === "minimal";
  const body = (
    <>
      <a
        href="#noi-dung"
        className="sr-only z-50 rounded-control bg-primary px-4 py-2 font-semibold text-on-primary focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
      >
        Bỏ qua tới nội dung
      </a>
      <ShellHeader minimal={minimal} />
      {minimal ? null : (
        <div className="mx-auto w-full max-w-6xl px-4 pt-4 empty:hidden sm:px-6">
          <AccountBanner />
          <PolicyAcceptanceBanner />
        </div>
      )}
      {/* Nền vở ô ly nhạt cho mọi trang khách (design-system-v2 §3.1); form/đoạn dài nằm trên `Sheet`. `minimal`: cột flex để khung đăng nhập cao hết màn. */}
      <main id="noi-dung" className={minimal ? "bg-oly-page flex flex-1 flex-col" : "bg-oly-page flex-1"}>
        {children}
      </main>
      {minimal ? null : <ShellFooter />}
    </>
  );
  return minimal ? body : <AuthProvider>{body}</AuthProvider>;
}

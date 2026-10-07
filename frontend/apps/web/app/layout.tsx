import type { Metadata } from "next";
import { Be_Vietnam_Pro, Mali } from "next/font/google";
import { ForcedLogoutOverlay } from "@vitaminvui/ui";
import { AppProviders } from "@/components/providers/AppProviders";
import { env } from "@/env";
import "./globals.css";

/** Font design system v2 (§5.1, §15): Be Vietnam Pro cho chữ thường, Mali cho nét chữ viết tay (poster/nhãn). */
const beVietnam = Be_Vietnam_Pro({
  subsets: ["latin", "vietnamese"],
  weight: ["400", "500", "600", "800"],
  variable: "--font-be-vietnam",
  display: "swap",
});

const mali = Mali({
  subsets: ["latin", "vietnamese"],
  weight: ["500"],
  variable: "--font-mali",
  display: "swap",
});

export const metadata: Metadata = {
  metadataBase: new URL(env.NEXT_PUBLIC_SITE_URL),
  title: "VitaminVui — Học Toán lớp 6–12",
  description: "Nền tảng khóa học Toán trực tuyến cho học sinh lớp 6 đến lớp 12.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    // `theme-v2` + `data-theme="light"`: token v2 áp cho cả trang (tokens.css). Giao diện tối chờ PO quyết (§13).
    <html
      lang="vi"
      data-theme="light"
      className={`theme-v2 ${beVietnam.variable} ${mali.variable} h-full antialiased`}
    >
      <body className="flex min-h-full flex-col bg-paper font-sans text-base text-ink">
        <AppProviders>
          {children}
          {/* Gắn 1 lần ở layout gốc — lắng sự kiện forced-logout/login-required (US-014). */}
          <ForcedLogoutOverlay loginHref="/dang-nhap" />
        </AppProviders>
      </body>
    </html>
  );
}

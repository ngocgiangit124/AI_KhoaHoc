import type { Metadata } from "next";
import { Be_Vietnam_Pro, Geist_Mono } from "next/font/google";
import { AppProviders } from "@/components/shell/AppProviders";
import { SessionWatcher } from "@/components/shell/SessionWatcher";
import "./globals.css";

/** Design system v2: Be Vietnam Pro có subset `vietnamese` (dấu chồng ặ, ổ, ữ rõ). Không dùng weight 700 (không tải). */
const beVietnam = Be_Vietnam_Pro({
  subsets: ["latin", "vietnamese"],
  weight: ["400", "500", "600", "800"],
  variable: "--font-be-vietnam",
  display: "swap",
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "VitaminVui — Quản trị",
  description: "Khu vực quản trị VitaminVui (admin, quản lý trang, giáo viên).",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="vi"
      data-theme="light"
      className={`theme-v2 ${beVietnam.variable} ${geistMono.variable} h-full font-sans antialiased`}
    >
      <body className="flex min-h-full flex-col bg-paper text-ink">
        <AppProviders>
          {children}
          {/* Gắn 1 lần: idle/phiên hết hạn/khoá tài khoản (US-016 §3). */}
          <SessionWatcher />
        </AppProviders>
      </body>
    </html>
  );
}

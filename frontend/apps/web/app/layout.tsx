import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import { ForcedLogoutOverlay, ToastProvider } from "@vitaminvui/ui";
import { SiteHeader } from "@/components/SiteHeader";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "VitaminVui — Học Toán lớp 6–12",
  description: "Nền tảng khóa học Toán trực tuyến cho học sinh lớp 6 đến lớp 12.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="vi"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="flex min-h-full flex-col">
        <ToastProvider>
          <SiteHeader />
          {children}
          {/* Gắn 1 lần ở layout gốc — lắng sự kiện forced-logout/login-required (US-014). */}
          <ForcedLogoutOverlay loginHref="/dang-nhap" />
        </ToastProvider>
      </body>
    </html>
  );
}

import type { Metadata } from "next";
import { Be_Vietnam_Pro } from "next/font/google";
import { V2Providers } from "@/components/v2/V2Providers";

/** Bản xem trước quản trị v2: cùng token với web học sinh, mật độ cao hơn; chỉ giao diện sáng. */
const beVietnam = Be_Vietnam_Pro({
  subsets: ["latin", "vietnamese"],
  weight: ["400", "500", "600", "800"],
  variable: "--font-be-vietnam",
  display: "swap",
});

export const metadata: Metadata = {
  title: { default: "Xem trước v2 — Quản trị VitaminVui", template: "%s · Xem trước v2 Quản trị" },
  robots: { index: false, follow: false },
};

export default function V2AdminLayout({ children }: LayoutProps<"/v2">) {
  return (
    <div data-theme="light" className={`theme-v2 ${beVietnam.variable} flex min-h-full flex-1 flex-col bg-paper font-sans text-ink antialiased`}>
      <V2Providers>{children}</V2Providers>
    </div>
  );
}

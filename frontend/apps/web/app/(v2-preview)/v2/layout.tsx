import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Be_Vietnam_Pro, Mali } from "next/font/google";
import { V2Providers } from "@/components/v2/V2Providers";

/**
 * Bản xem trước design system v2 (PO duyệt). Nhóm route riêng, không đụng route thật.
 * Font chỉ tải ở nhánh này; class `theme-v2` bật token v2 (packages/ui/src/v2/tokens.css).
 */
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
  title: { default: "Xem trước v2 — VitaminVui", template: "%s · Xem trước v2 VitaminVui" },
  robots: { index: false, follow: false },
};

export default function V2Layout({ children }: LayoutProps<"/v2">) {
  // Production: bản xem trước KHÔNG được mở ra công khai (trừ khi bật rõ ràng bằng V2_PREVIEW=1, ví dụ trên staging).
  if (process.env.NODE_ENV === "production" && process.env.V2_PREVIEW !== "1") notFound();
  return (
    <div
      data-theme="light"
      className={`theme-v2 ${beVietnam.variable} ${mali.variable} flex min-h-full flex-1 flex-col bg-paper font-sans text-base text-ink antialiased`}
    >
      <V2Providers>{children}</V2Providers>
    </div>
  );
}

import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { connection } from "next/server";
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

/**
 * Bản xem trước chỉ dành cho dev/PO: ở production trả 404 trừ khi đặt biến server `V2_PREVIEW=1` (xem frontend/README.md).
 * `connection()` buộc layout (và các trang `/v2`) render theo từng request để biến môi trường được đọc lúc chạy, không bị
 * "đóng băng" ở lúc build.
 */
export default async function V2AdminLayout({ children }: LayoutProps<"/v2">) {
  await connection();
  if (process.env.NODE_ENV === "production" && process.env.V2_PREVIEW !== "1") notFound();
  return (
    <div data-theme="light" className={`theme-v2 ${beVietnam.variable} flex min-h-full flex-1 flex-col bg-paper font-sans text-ink antialiased`}>
      <V2Providers>{children}</V2Providers>
    </div>
  );
}

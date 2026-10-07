import type { Metadata } from "next";
import type { ReactNode } from "react";
import { AuthProvider } from "@/lib/auth/AuthProvider";

/** Học viên đang học: nội dung theo người dùng, không để công cụ tìm kiếm lập chỉ mục. */
export const metadata: Metadata = { robots: { index: false, follow: false } };

/**
 * Màn học yên tĩnh (design-system-v2 §12.3): KHÔNG header site, footer, bottom-nav, KHÔNG nền ô ly. `AuthProvider` cho avatar
 * (và `/auth/me` dùng chung); quyền xem bài do API quyết định, trang chỉ hiển thị kết quả.
 */
export default function LearnLayout({ children }: { children: ReactNode }) {
  return (
    <AuthProvider>
      <div className="flex min-h-dvh flex-col">{children}</div>
    </AuthProvider>
  );
}

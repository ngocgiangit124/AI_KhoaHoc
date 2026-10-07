import type { ReactNode } from "react";
import { SiteShell } from "@/components/shell/SiteShell";

/** Trang công khai/học sinh: trang chủ, danh mục, lớp, chi tiết khóa học. Khung (header + `/auth/me`) giữ nguyên khi chuyển trang. */
export default function SiteLayout({ children }: { children: ReactNode }) {
  return <SiteShell>{children}</SiteShell>;
}

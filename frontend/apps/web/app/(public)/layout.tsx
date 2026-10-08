import type { ReactNode } from "react";
import { SiteShell } from "@/components/shell/SiteShell";

/** Trang công khai không cần đăng nhập (link trong thư phụ huynh): khung tối giản, KHÔNG gọi `/auth/me`. */
export default function PublicLayout({ children }: { children: ReactNode }) {
  return <SiteShell variant="minimal">{children}</SiteShell>;
}

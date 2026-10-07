import type { ReactNode } from "react";
import { SiteShell } from "@/components/shell/SiteShell";

/** Đăng nhập, đăng ký, xác thực OTP: khung tối giản (header chỉ có logo, không footer). */
export default function AuthLayout({ children }: { children: ReactNode }) {
  return <SiteShell variant="minimal">{children}</SiteShell>;
}

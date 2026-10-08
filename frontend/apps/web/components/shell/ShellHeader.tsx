"use client";

import { usePathname } from "next/navigation";
import { IconBookOpen, IconLayers, SiteHeader } from "@vitaminvui/ui/v2";
import { LogoutButton } from "@/components/auth/LogoutButton";
import { useOptionalAuth } from "@/lib/auth/AuthProvider";
import { isCatalogPath, routes } from "@/lib/routes";

/**
 * Header học sinh THẬT dựng từ `SiteHeader` v2 (hình thức giữ nguyên bản xem trước). Trạng thái đăng
 * nhập lấy từ `/auth/me` qua `AuthProvider` (một lần cho cả cây):
 * - đang hỏi: giữ chỗ (không nhấp nháy nút Đăng nhập);
 * - đã đăng nhập: tên + Đăng xuất;
 * - khách, hoặc `/auth/me` lỗi mạng: nút Đăng nhập/Đăng ký (mất mạng không bao giờ coi là mất phiên — chỉ ảnh hưởng hiển thị).
 * `minimal` (đăng nhập/đăng ký/OTP): chỉ logo, không ô tìm kiếm, không cần `AuthProvider`.
 * Mục "Khóa học của tôi" (FW6) chỉ hiện khi đã đăng nhập. Chưa có giỏ hàng (FW3 dựng sau).
 */
export function ShellHeader({ minimal = false, searchDefault }: { minimal?: boolean; searchDefault?: string }) {
  const pathname = usePathname();
  const auth = useOptionalAuth();
  const state = auth?.state;

  const user = state?.status === "user" ? state.user : null;
  const nav = [
    { href: routes.catalog, label: "Khóa học", current: isCatalogPath(pathname), icon: <IconBookOpen /> },
    ...(user
      ? [{ href: routes.myCourses, label: "Khóa học của tôi", current: pathname === routes.myCourses || pathname.startsWith(`${routes.myCourses}/`), icon: <IconLayers /> }]
      : []),
  ];

  return (
    <SiteHeader
      homeHref={routes.home}
      nav={nav}
      viewer={user ? { name: user.name || "Học sinh", accountHref: routes.account } : null}
      viewerActions={user ? <LogoutButton /> : undefined}
      authPending={state?.status === "loading"}
      loginHref={routes.login}
      registerHref={routes.register}
      searchAction={routes.catalog}
      searchDefault={searchDefault}
      minimal={minimal}
    />
  );
}

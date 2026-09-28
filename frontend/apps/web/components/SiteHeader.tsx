"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { Button } from "@vitaminvui/ui";
import { logout } from "@/lib/auth/logout";
import { useCurrentUser } from "@/lib/auth/useCurrentUser";

/**
 * Header tối thiểu cho app web (bản đầy đủ theo design-system.md §4 — menu danh mục, giỏ
 * hàng, bottom nav — thuộc FW2+). Ở FW1 phần 1 chỉ cần đủ để thoả AC3 (US-001: "tên hiển
 * thị xuất hiện ở header" sau khi đăng nhập) và có chỗ bấm "Đăng xuất".
 */
export function SiteHeader() {
  const router = useRouter();
  const { user, isLoading } = useCurrentUser();
  const [isLoggingOut, setIsLoggingOut] = useState(false);

  async function handleLogout() {
    setIsLoggingOut(true);
    try {
      await logout();
      // `logout()` đã phát AUTH_CHANGED_EVENT (useCurrentUser tự làm mới); điều hướng về
      // trang chủ + làm mới Server Component (nếu trang hiện tại có dữ liệu theo phiên).
      router.push("/");
      router.refresh();
    } finally {
      setIsLoggingOut(false);
    }
  }

  return (
    <header className="border-b border-gray-200 bg-white">
      <div className="mx-auto flex h-16 max-w-5xl items-center justify-between px-4">
        <Link href="/" className="flex items-center gap-2 font-bold text-indigo-600">
          <span className="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-white">
            V
          </span>
          VitaminVui
        </Link>

        {isLoading ? (
          <div className="h-9 w-24 animate-pulse rounded-lg bg-gray-100" aria-hidden="true" />
        ) : user ? (
          <div className="flex items-center gap-3">
            <span className="text-sm font-medium text-gray-700">Xin chào, {user.name}</span>
            <Button variant="outline" size="sm" loading={isLoggingOut} onClick={handleLogout}>
              Đăng xuất
            </Button>
          </div>
        ) : (
          <div className="flex items-center gap-2">
            <Link href="/dang-nhap" className="text-sm font-medium text-gray-700 hover:text-indigo-600">
              Đăng nhập
            </Link>
            <Link
              href="/dang-ky"
              className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
            >
              Đăng ký
            </Link>
          </div>
        )}
      </div>
    </header>
  );
}

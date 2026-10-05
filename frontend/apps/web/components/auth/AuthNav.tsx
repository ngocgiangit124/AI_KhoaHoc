"use client";

import Link from "next/link";
import { useAuth } from "@/lib/auth/AuthProvider";
import { LogoutButton } from "./LogoutButton";

/** Khối tài khoản ở đầu trang: khách → Đăng nhập/Đăng ký; học sinh → tên + Đăng xuất. */
export function AuthNav() {
  const { state, refresh } = useAuth();

  if (state.status === "loading") {
    return <div className="h-9 w-40" aria-hidden="true" />;
  }

  if (state.status === "error") {
    return (
      <button
        type="button"
        onClick={() => void refresh()}
        className="inline-flex min-h-11 items-center px-2 text-sm font-medium text-indigo-700 hover:underline"
      >
        Không tải được tài khoản. Thử lại
      </button>
    );
  }

  if (state.status === "user") {
    return (
      <div className="flex items-center gap-3">
        {state.user.name ? <span className="text-sm text-gray-800">Xin chào, {state.user.name}</span> : null}
        <LogoutButton />
      </div>
    );
  }

  return (
    <nav aria-label="Tài khoản" className="flex items-center gap-3 text-sm">
      <Link href="/dang-nhap" className="inline-flex min-h-11 items-center px-2 font-medium text-indigo-700 hover:underline">
        Đăng nhập
      </Link>
      <Link
        href="/dang-ky"
        className="inline-flex h-11 items-center rounded-lg bg-indigo-600 px-3 font-semibold text-white hover:bg-indigo-700"
      >
        Đăng ký
      </Link>
    </nav>
  );
}

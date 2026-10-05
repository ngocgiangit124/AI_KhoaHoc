"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { fetchCurrentUser, type AuthUser } from "@/lib/auth/api";
import { LogoutButton } from "./LogoutButton";

type State = { status: "loading" } | { status: "guest" } | { status: "user"; user: AuthUser };

/** Khối tài khoản ở đầu trang: khách → Đăng nhập/Đăng ký; học sinh → tên + Đăng xuất. */
export function AuthNav() {
  const [state, setState] = useState<State>({ status: "loading" });

  useEffect(() => {
    const controller = new AbortController();
    fetchCurrentUser(controller.signal).then((user) => {
      if (!controller.signal.aborted) setState(user ? { status: "user", user } : { status: "guest" });
    });
    return () => controller.abort();
  }, []);

  if (state.status === "loading") {
    return <div className="h-9 w-40" aria-hidden="true" />;
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

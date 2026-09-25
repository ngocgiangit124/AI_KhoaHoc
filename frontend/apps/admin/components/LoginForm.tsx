"use client";

import { useEffect, useState } from "react";
import { Alert, Button, Spinner } from "@vitaminvui/ui";
import { publicFetch } from "@/lib/api";

type CsrfStatus = "loading" | "ready" | "error";

/**
 * Form đăng nhập quản trị (US-016). FE0 chỉ cần lấy CSRF token trên admin-api và hiển thị
 * form — LUỒNG SUBMIT (login → có thể MFA → buộc đổi mật khẩu) thuộc FA1, chưa nối ở đây.
 */
export function LoginForm() {
  const [csrfStatus, setCsrfStatus] = useState<CsrfStatus>("loading");

  useEffect(() => {
    let cancelled = false;

    publicFetch<{ token: string }>("/api/v1/csrf-token")
      .then(() => {
        if (!cancelled) setCsrfStatus("ready");
      })
      .catch(() => {
        if (!cancelled) setCsrfStatus("error");
      });

    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <form className="space-y-4" onSubmit={(e) => e.preventDefault()}>
      {csrfStatus === "error" ? (
        <Alert variant="danger" title="Không kết nối được máy chủ">
          Không lấy được token bảo mật từ admin-api. Kiểm tra backend (T01) và CORS
          (Origin phải là NEXT_PUBLIC_ADMIN_URL).
        </Alert>
      ) : null}

      <label className="block text-sm">
        <span className="mb-1 block font-medium text-gray-900">
          Email hoặc số điện thoại <span className="text-rose-600">*</span>
        </span>
        <input
          type="text"
          name="login"
          autoComplete="username"
          required
          className="h-11 w-full rounded-lg border border-gray-300 px-3 text-base focus:border-indigo-600 focus:outline-none"
        />
      </label>

      <label className="block text-sm">
        <span className="mb-1 block font-medium text-gray-900">
          Mật khẩu <span className="text-rose-600">*</span>
        </span>
        <input
          type="password"
          name="password"
          autoComplete="current-password"
          required
          className="h-11 w-full rounded-lg border border-gray-300 px-3 text-base focus:border-indigo-600 focus:outline-none"
        />
      </label>

      <Button type="submit" className="w-full" disabled={csrfStatus !== "ready"}>
        {csrfStatus === "loading" ? <Spinner /> : null}
        Đăng nhập
      </Button>
      <p className="text-center text-xs text-gray-500">
        Luồng đăng nhập (kể cả MFA, buộc đổi mật khẩu lần đầu) sẽ được nối ở FA1, sau khi
        T28 xong.
      </p>
    </form>
  );
}

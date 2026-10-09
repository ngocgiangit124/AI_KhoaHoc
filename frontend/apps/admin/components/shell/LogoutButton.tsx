"use client";

import { useState } from "react";
import { Button, IconLogOut, useToast } from "@vitaminvui/ui/v2";
import { logoutStaff } from "@/lib/auth/api";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";

export function LogoutButton() {
  const toast = useToast();
  const [pending, setPending] = useState(false);

  async function onClick() {
    setPending(true);
    try {
      await logoutStaff();
      // Điều hướng cứng: /dang-nhap cần CSP Turnstile của chính nó (GL-A2).
      // eslint-disable-next-line @next/next/no-location-assign-relative-destination -- cố ý: cần tải lại tài liệu để nhận CSP Turnstile
      window.location.assign("/dang-nhap");
    } catch (err) {
      toast.show({ tone: "danger", title: err instanceof Error && err.message ? err.message : UNKNOWN_ERROR_MESSAGE });
      setPending(false);
    }
  }

  return (
    <Button variant="ghost" size="sm" className="justify-start max-sm:h-11 text-ink hover:bg-sunken hover:text-ink" leadingIcon={<IconLogOut size={18} />} loading={pending} loadingText="Đang đăng xuất…" onClick={onClick}>
      Đăng xuất
    </Button>
  );
}

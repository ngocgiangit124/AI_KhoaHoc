"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Button, IconLogOut, useToast } from "@vitaminvui/ui/v2";
import { logoutStaff } from "@/lib/auth/api";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";

export function LogoutButton() {
  const router = useRouter();
  const toast = useToast();
  const [pending, setPending] = useState(false);

  async function onClick() {
    setPending(true);
    try {
      await logoutStaff();
      router.replace("/dang-nhap");
      router.refresh();
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

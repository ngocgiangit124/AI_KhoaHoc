"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Button, useToast } from "@vitaminvui/ui";
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
      toast.show("danger", err instanceof Error && err.message ? err.message : UNKNOWN_ERROR_MESSAGE);
      setPending(false);
    }
  }

  return (
    <Button variant="outline" size="sm" loading={pending} onClick={onClick}>
      Đăng xuất
    </Button>
  );
}

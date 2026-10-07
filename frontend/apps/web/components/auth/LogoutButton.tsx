"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Button, useToast } from "@vitaminvui/ui/v2";
import { logoutStudent } from "@/lib/auth/api";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";

/** Đăng xuất: POST /auth/logout rồi về /dang-nhap. Lỗi → giữ nguyên trang, báo bằng toast. */
export function LogoutButton({ block = false }: { block?: boolean }) {
  const router = useRouter();
  const toast = useToast();
  const [pending, setPending] = useState(false);

  async function onClick() {
    setPending(true);
    try {
      await logoutStudent();
      router.replace("/dang-nhap");
      router.refresh();
    } catch (err) {
      toast.show({
        tone: "danger",
        title: "Không đăng xuất được",
        description: err instanceof Error && err.message ? err.message : UNKNOWN_ERROR_MESSAGE,
      });
      setPending(false);
    }
  }

  return (
    <Button variant="secondary" size="md" block={block} loading={pending} loadingText="Đang đăng xuất…" onClick={onClick}>
      Đăng xuất
    </Button>
  );
}

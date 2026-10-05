"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Alert, Button } from "@vitaminvui/ui";
import { logoutStudent } from "@/lib/auth/api";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";

/** Đăng xuất: POST /auth/logout rồi về /dang-nhap. Lỗi → giữ nguyên trang, báo lỗi. */
export function LogoutButton() {
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onClick() {
    setPending(true);
    setError(null);
    try {
      await logoutStudent();
      router.replace("/dang-nhap");
      router.refresh();
    } catch (err) {
      setError(err instanceof Error && err.message ? err.message : UNKNOWN_ERROR_MESSAGE);
      setPending(false);
    }
  }

  return (
    <div>
      <Button variant="outline" size="md" loading={pending} onClick={onClick}>
        Đăng xuất
      </Button>
      {error ? (
        <Alert variant="danger" className="mt-2">
          {error}
        </Alert>
      ) : null}
    </div>
  );
}

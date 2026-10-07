"use client";

import { useEffect } from "react";
import { Alert, Button } from "@vitaminvui/ui/v2";

export default function Error({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <main className="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center gap-4 px-4 py-16">
      <Alert tone="danger" title="Đã có lỗi xảy ra" action={<Button size="sm" variant="secondary" onClick={reset}>Thử lại</Button>}>
        Không thể tải trang. Vui lòng thử lại sau.
      </Alert>
    </main>
  );
}

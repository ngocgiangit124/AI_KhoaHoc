"use client";

import { useEffect } from "react";
import { Alert, Button } from "@vitaminvui/ui";

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
    <main className="mx-auto max-w-xl px-4 py-16">
      <Alert variant="danger" title="Đã có lỗi xảy ra">
        Không thể tải trang. Vui lòng thử lại sau.
      </Alert>
      <Button className="mt-4" onClick={reset}>
        Thử lại
      </Button>
    </main>
  );
}

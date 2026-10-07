"use client";

import { useEffect } from "react";
import { Alert, Button } from "@vitaminvui/ui/v2";
import { SiteShell } from "@/components/shell/SiteShell";

/** Lỗi ngoài dự kiến (5xx, API lệch contract): khung tối giản + nút thử lại. */
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
    <SiteShell variant="minimal">
      <div className="mx-auto w-full max-w-xl px-4 py-12">
        <Alert
          tone="danger"
          title="Đã có lỗi xảy ra"
          action={
            <Button variant="secondary" onClick={reset}>
              Thử lại
            </Button>
          }
        >
          Không thể tải trang. Vui lòng thử lại sau.
        </Alert>
      </div>
    </SiteShell>
  );
}

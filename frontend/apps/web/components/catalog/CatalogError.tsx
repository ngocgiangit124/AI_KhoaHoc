"use client";

import { useEffect } from "react";
import { Alert, Button, IconRotateCcw } from "@vitaminvui/ui/v2";

/** Lỗi tải danh mục/chi tiết (US-002 §3): Alert danger + nút "Tải lại"; khung trang (header/footer) vẫn còn. */
export function CatalogError({ error, reset, what = "danh sách khóa học" }: { error: Error & { digest?: string }; reset: () => void; what?: string }) {
  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <div className="mx-auto w-full max-w-xl px-4 py-12">
      <Alert
        tone="danger"
        title={`Không tải được ${what}`}
        action={
          <Button variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />} onClick={reset}>
            Tải lại
          </Button>
        }
      >
        Kiểm tra kết nối mạng rồi thử lại. Nếu vẫn lỗi, hãy quay lại sau ít phút.
      </Alert>
    </div>
  );
}

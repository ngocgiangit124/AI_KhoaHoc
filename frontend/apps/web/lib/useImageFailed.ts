"use client";

import { useCallback, useState } from "react";

/**
 * Phát hiện ảnh tải lỗi (404, hỏng) để thay bằng phương án dự phòng. `onError` của React có thể xảy ra TRƯỚC khi
 * hydrate (ảnh SSR đã lỗi từ đầu) nên ref callback kiểm thêm `complete && naturalWidth === 0` khi phần tử vừa gắn.
 */
export function useImageFailed(src?: string) {
  const [failed, setFailed] = useState(false);
  const [prevSrc, setPrevSrc] = useState(src);
  // `src` đổi (thẻ tái dùng cho ảnh khác) -> bỏ trạng thái lỗi cũ.
  if (prevSrc !== src) {
    setPrevSrc(src);
    setFailed(false);
  }
  const ref = useCallback((img: HTMLImageElement | null) => {
    if (img && img.complete && img.naturalWidth === 0) setFailed(true);
  }, []);
  const onError = useCallback(() => setFailed(true), []);
  return { failed, ref, onError };
}

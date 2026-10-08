"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState, type MutableRefObject, type ReactNode, type RefObject } from "react";
import { ConfirmDialog } from "@vitaminvui/ui/v2";

export interface UnsavedGuardOptions {
  /** Liên kết nằm trong phần tử này đã có cơ chế hỏi riêng: hook bỏ qua. */
  ignoreInside?: RefObject<HTMLElement | null>;
  /** Chặn cả nút Back/Forward của trình duyệt (đẩy 1 mục lịch sử đệm khi bắt đầu có thay đổi). */
  guardHistory?: boolean;
  description: string;
}

/** Liên kết nội bộ cần hỏi: bỏ `#`, `_blank`, `download`, khác origin, trùng trang hiện tại. */
export function internalHref(a: HTMLAnchorElement, current: Location = window.location): string | null {
  const raw = a.getAttribute("href");
  if (!raw || raw.startsWith("#") || (a.target && a.target !== "_self") || a.hasAttribute("download")) return null;
  const url = new URL(a.href, current.href);
  if (url.origin !== current.origin) return null;
  if (url.pathname === current.pathname && url.search === current.search) return null;
  return url.pathname + url.search + url.hash;
}

/**
 * Hỏi xác nhận trước khi rời trang khi còn thay đổi chưa lưu: bắt click liên kết ở cấp document (sidebar, header, breadcrumb...),
 * nút Back của trình duyệt (tuỳ chọn) và `beforeunload`. `setDirty(true/false)` do form gọi; `dialog` phải được render.
 * Quyền/dữ liệu thật không phụ thuộc vào đây; chỉ để người dùng không mất bài đang soạn.
 */
export function useUnsavedChangesGuard({ ignoreInside, guardHistory = false, description }: UnsavedGuardOptions): {
  dirtyRef: MutableRefObject<boolean>;
  setDirty: (dirty: boolean) => void;
  /** Gọi sau khi form tự `router.replace` sang URL khác (replace thay mất mục đệm): lần có thay đổi kế tiếp sẽ đẩy mục đệm mới. */
  rearm: () => void;
  dialog: ReactNode;
} {
  const router = useRouter();
  const dirtyRef = useRef(false);
  const sentinel = useRef(false);
  const [pending, setPending] = useState<{ kind: "href"; href: string } | { kind: "back" } | null>(null);

  const setDirty = useCallback(
    (dirty: boolean) => {
      if (dirty && !dirtyRef.current && guardHistory && !sentinel.current) {
        window.history.pushState(null, "", window.location.href);
        sentinel.current = true;
      }
      dirtyRef.current = dirty;
    },
    [dirtyRef, guardHistory],
  );

  const rearm = useCallback(() => {
    sentinel.current = false;
  }, []);

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (!dirtyRef.current || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
      const a = (e.target as Element | null)?.closest?.("a");
      if (!a || (ignoreInside?.current && ignoreInside.current.contains(a))) return;
      const href = internalHref(a);
      if (!href) return;
      e.preventDefault();
      e.stopPropagation();
      setPending({ kind: "href", href });
    };
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      if (dirtyRef.current) e.preventDefault();
    };
    const onPop = () => {
      if (!guardHistory || !sentinel.current) return;
      sentinel.current = false;
      if (dirtyRef.current) setPending({ kind: "back" });
    };
    document.addEventListener("click", onClick, true);
    window.addEventListener("beforeunload", onBeforeUnload);
    window.addEventListener("popstate", onPop);
    return () => {
      document.removeEventListener("click", onClick, true);
      window.removeEventListener("beforeunload", onBeforeUnload);
      window.removeEventListener("popstate", onPop);
    };
  }, [dirtyRef, ignoreInside, guardHistory]);

  const dialog = pending ? (
    <ConfirmDialog
      open
      title="Còn thay đổi chưa lưu"
      description={description}
      confirmLabel="Bỏ thay đổi"
      cancelLabel="Ở lại để lưu"
      onClose={() => {
        // Ở lại: nếu vừa bấm Back thì đặt lại mục đệm để Back lần sau vẫn được chặn.
        if (pending.kind === "back" && dirtyRef.current && guardHistory) {
          window.history.pushState(null, "", window.location.href);
          sentinel.current = true;
        }
        setPending(null);
      }}
      onConfirm={() => {
        const p = pending;
        dirtyRef.current = false;
        setPending(null);
        if (p.kind === "href") router.push(p.href);
        else window.history.back();
      }}
    />
  ) : null;

  return { dirtyRef, setDirty, rearm, dialog };
}

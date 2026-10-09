"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useToast } from "@vitaminvui/ui/v2";
import { useOptionalAuth, type AuthContextValue } from "@/lib/auth/AuthProvider";
import { fetchCardOwnership } from "@/lib/catalog/cardOwnership";
import { resolveCta, viewerFromOwnership, type CardOwnership, type CtaModel } from "@/lib/catalog/cta";
import { addCartItem } from "@/lib/orders/api";
import { mapAddToCartError } from "@/lib/orders/errors";

interface CardCartValue {
  /** Hành động của một thẻ khóa CÓ PHÍ (dùng chung `resolveCta` với trang chi tiết). */
  modelFor: (course: { id: number; isFree: boolean }) => CtaModel;
  addToCart: (courseId: number, title: string) => void;
  busyId: number | null;
  paidCheckoutEnabled: boolean;
}

const CardCartContext = createContext<CardCartValue | null>(null);

type Status = "idle" | "loading" | "ready" | "error";

/**
 * Bọc MỘT lần quanh lưới thẻ khóa (danh mục, trang chủ): tải tập khóa trong giỏ/đã sở hữu bằng 2 lời gọi cho cả trang rồi mọi thẻ
 * suy ra nút từ đó. Chỉ gọi khi thanh toán đang mở và đã đăng nhập; ngoài `<AuthProvider>` hoặc lỗi -> không thẻ nào hiện nút.
 */
export function CardCartProvider({ paidCheckoutEnabled, children }: { paidCheckoutEnabled: boolean; children: ReactNode }) {
  const auth = useOptionalAuth();
  // Ngoài <AuthProvider> (khung trang tối giản/test): không có ngữ cảnh -> thẻ không hiện nút.
  if (!auth) return <>{children}</>;
  return (
    <CardCartInner auth={auth} paidCheckoutEnabled={paidCheckoutEnabled}>
      {children}
    </CardCartInner>
  );
}

function CardCartInner({ auth, paidCheckoutEnabled, children }: { auth: AuthContextValue; paidCheckoutEnabled: boolean; children: ReactNode }) {
  const toast = useToast();
  const authStatus = auth.state.status;
  const isUser = authStatus === "user";
  const [own, setOwn] = useState<CardOwnership | null>(null);
  const [status, setStatus] = useState<Status>("idle");
  const [busyId, setBusyId] = useState<number | null>(null);
  const lock = useRef(false);

  useEffect(() => {
    if (!isUser || !paidCheckoutEnabled) return;
    const controller = new AbortController();
    // eslint-disable-next-line react-hooks/set-state-in-effect -- đồng bộ với API: bắt đầu tải khi có phiên.
    setStatus("loading");
    fetchCardOwnership(controller.signal)
      .then((o) => {
        if (controller.signal.aborted) return;
        setOwn(o);
        setStatus("ready");
      })
      .catch(() => {
        if (!controller.signal.aborted) setStatus("error");
      });
    return () => controller.abort();
  }, [isUser, paidCheckoutEnabled]);

  const refreshHeader = auth.refresh;
  const addToCart = useCallback(
    (courseId: number, title: string) => {
      if (lock.current) return;
      lock.current = true;
      setBusyId(courseId);
      const mark = (key: "cartIds" | "ownedIds") =>
        setOwn((prev) => (prev ? { ...prev, [key]: new Set([...prev[key], courseId]) } : prev));
      addCartItem(courseId)
        .then(() => {
          mark("cartIds");
          toast.show({ tone: "success", title: `Đã thêm ${title} vào giỏ` });
          void refreshHeader(); // `cart_count` ở header
        })
        .catch((err: unknown) => {
          const outcome = mapAddToCartError(err);
          if (outcome.type === "in_cart") mark("cartIds");
          else if (outcome.type === "owned") mark("ownedIds");
          else toast.show({ tone: "danger", title: outcome.message });
        })
        .finally(() => {
          lock.current = false;
          setBusyId(null);
        });
    },
    [toast, refreshHeader],
  );

  const value = useMemo<CardCartValue>(
    () => ({
      paidCheckoutEnabled,
      busyId,
      addToCart,
      modelFor: ({ id, isFree }) =>
        resolveCta({
          auth: authStatus,
          viewerStatus: status === "idle" && isUser ? "loading" : status,
          viewer: own ? viewerFromOwnership(id, own) : null,
          isFree,
          courseId: id,
          enrolling: false,
          addingToCart: busyId === id,
          paidCheckoutEnabled,
        }),
    }),
    [paidCheckoutEnabled, busyId, addToCart, authStatus, status, isUser, own],
  );

  return <CardCartContext.Provider value={value}>{children}</CardCartContext.Provider>;
}

export function useCardCart(): CardCartValue | null {
  return useContext(CardCartContext);
}

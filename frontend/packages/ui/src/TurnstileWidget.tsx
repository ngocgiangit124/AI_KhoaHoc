"use client";

import { useEffect, useRef } from "react";

interface TurnstileApi {
  render: (
    container: HTMLElement,
    options: {
      sitekey: string;
      callback: (token: string) => void;
      "expired-callback": () => void;
      "error-callback": () => void;
      language?: string;
      appearance?: "always" | "execute" | "interaction-only";
    },
  ) => string;
  remove: (widgetId: string) => void;
}

declare global {
  interface Window {
    turnstile?: TurnstileApi;
  }
}

export const TURNSTILE_SCRIPT_SRC = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";

let scriptPromise: Promise<TurnstileApi> | null = null;

/** Nạp script chính thức của Cloudflare đúng 1 lần (không thêm package). */
function loadTurnstile(): Promise<TurnstileApi> {
  if (window.turnstile) return Promise.resolve(window.turnstile);
  if (scriptPromise) return scriptPromise;

  scriptPromise = new Promise<TurnstileApi>((resolve, reject) => {
    const script = document.createElement("script");
    script.src = TURNSTILE_SCRIPT_SRC;
    script.async = true;
    script.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error("turnstile missing")));
    script.onerror = () => {
      scriptPromise = null; // cho phép thử lại lần sau
      reject(new Error("turnstile script failed"));
    };
    document.head.appendChild(script);
  });
  return scriptPromise;
}

export interface TurnstileWidgetProps {
  siteKey: string;
  /** `token` = chuỗi dùng 1 lần; `null` khi hết hạn/lỗi/chưa xác minh. */
  onToken: (token: string | null) => void;
  /** Gọi khi widget lỗi (script không tải được, hoặc Cloudflare báo lỗi). */
  onError?: () => void;
  className?: string;
  /**
   * `interaction-only`: chế độ ẩn — widget chỉ hiện khi Cloudflare buộc người dùng tương tác (dùng cho "Gửi lại mã" ở
   * bước 2 đặt lại mật khẩu, design-system-v2 §12.8). Mặc định `always` (hành vi cũ).
   */
  appearance?: "always" | "interaction-only";
}

/**
 * Cloudflare Turnstile (explicit render). Token dùng 1 lần: muốn xác minh lại, đổi `key`
 * của component này ở nơi dùng để mount lại widget mới.
 */
export function TurnstileWidget({ siteKey, onToken, onError, className = "", appearance = "always" }: TurnstileWidgetProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const onTokenRef = useRef(onToken);
  const onErrorRef = useRef(onError);

  useEffect(() => {
    onTokenRef.current = onToken;
    onErrorRef.current = onError;
  });

  useEffect(() => {
    let cancelled = false;
    let widgetId: string | null = null;
    let api: TurnstileApi | null = null;

    loadTurnstile()
      .then((turnstile) => {
        if (cancelled || !containerRef.current) return;
        api = turnstile;
        widgetId = turnstile.render(containerRef.current, {
          sitekey: siteKey,
          language: "vi",
          ...(appearance === "interaction-only" ? { appearance } : {}),
          callback: (token) => onTokenRef.current(token),
          "expired-callback": () => onTokenRef.current(null),
          "error-callback": () => {
            onTokenRef.current(null);
            onErrorRef.current?.();
          },
        });
      })
      .catch(() => {
        if (!cancelled) {
          onTokenRef.current(null);
          onErrorRef.current?.();
        }
      });

    return () => {
      cancelled = true;
      if (api && widgetId !== null) api.remove(widgetId);
    };
  }, [siteKey, appearance]);

  return <div ref={containerRef} className={className} data-testid="turnstile-widget" />;
}

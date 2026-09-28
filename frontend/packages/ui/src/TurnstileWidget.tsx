"use client";

import { useEffect, useId, useRef } from "react";

const SCRIPT_SRC = "https://challenges.cloudflare.com/turnstile/v0/api.js";

interface TurnstileRenderOptions {
  sitekey: string;
  action?: string;
  callback?: (token: string) => void;
  "expired-callback"?: () => void;
  "error-callback"?: () => void;
}

interface TurnstileApi {
  render: (container: HTMLElement, options: TurnstileRenderOptions) => string;
  remove: (widgetId: string) => void;
  reset: (widgetId: string) => void;
}

declare global {
  interface Window {
    turnstile?: TurnstileApi;
  }
}

let scriptLoadingPromise: Promise<void> | null = null;

/**
 * Nạp script chính thức Cloudflare Turnstile đúng 1 lần cho cả trang (nhiều widget dùng
 * chung 1 thẻ `<script>`). Gắn `nonce` (đọc từ header `x-nonce` do `proxy.ts` sinh —
 * ADR-004 §2.6) lên thẻ script để tương thích CSP kể cả ở trình duyệt không hỗ trợ
 * `strict-dynamic`.
 */
function loadTurnstileScript(nonce?: string | null): Promise<void> {
  if (typeof window === "undefined") {
    return Promise.reject(new Error("loadTurnstileScript chỉ chạy được ở trình duyệt"));
  }
  if (window.turnstile) {
    return Promise.resolve();
  }
  if (scriptLoadingPromise) {
    return scriptLoadingPromise;
  }

  scriptLoadingPromise = new Promise<void>((resolve, reject) => {
    const script = document.createElement("script");
    script.src = SCRIPT_SRC;
    script.async = true;
    script.defer = true;
    if (nonce) {
      script.nonce = nonce;
      script.setAttribute("nonce", nonce);
    }
    script.addEventListener("load", () => resolve());
    script.addEventListener("error", () => {
      scriptLoadingPromise = null;
      reject(new Error("Không tải được script Cloudflare Turnstile"));
    });
    document.head.appendChild(script);
  });

  return scriptLoadingPromise;
}

export interface TurnstileWidgetProps {
  /**
   * `config/public.captcha_site_key` (api-contract §2.1). Component KHÔNG tự quyết định
   * có bật captcha hay không — nơi gọi phải chỉ render `<TurnstileWidget>` khi
   * `captcha_site_key !== null` (local chưa cấu hình Turnstile thì không render).
   */
  siteKey: string;
  /** Nonce CSP của trang hiện tại — đọc bằng `(await headers()).get('x-nonce')` ở Server Component. */
  nonce?: string | null;
  onVerify: (token: string) => void;
  onExpire?: () => void;
  onError?: () => void;
  action?: string;
  className?: string;
}

/** Widget captcha Cloudflare Turnstile — script chính thức, không cần package (design-system.md §5.1). */
export function TurnstileWidget({
  siteKey,
  nonce,
  onVerify,
  onExpire,
  onError,
  action,
  className,
}: TurnstileWidgetProps) {
  const reactId = useId().replace(/[^a-zA-Z0-9_-]/g, "");
  const containerRef = useRef<HTMLDivElement>(null);
  const widgetIdRef = useRef<string | null>(null);
  // Ref cho callback để effect không cần chạy lại mỗi lần onVerify/onExpire/onError đổi
  // identity (component cha thường truyền hàm inline).
  const onVerifyRef = useRef(onVerify);
  const onExpireRef = useRef(onExpire);
  const onErrorRef = useRef(onError);
  onVerifyRef.current = onVerify;
  onExpireRef.current = onExpire;
  onErrorRef.current = onError;

  useEffect(() => {
    if (!siteKey || !containerRef.current) return;
    let cancelled = false;

    loadTurnstileScript(nonce)
      .then(() => {
        if (cancelled || !containerRef.current || !window.turnstile) return;
        widgetIdRef.current = window.turnstile.render(containerRef.current, {
          sitekey: siteKey,
          action,
          callback: (token) => onVerifyRef.current(token),
          "expired-callback": () => onExpireRef.current?.(),
          "error-callback": () => onErrorRef.current?.(),
        });
      })
      .catch(() => {
        onErrorRef.current?.();
      });

    return () => {
      cancelled = true;
      if (widgetIdRef.current && window.turnstile) {
        window.turnstile.remove(widgetIdRef.current);
      }
      widgetIdRef.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- chỉ tạo lại widget khi siteKey/nonce/action đổi, không phải mỗi lần callback đổi identity
  }, [siteKey, nonce, action]);

  if (!siteKey) return null;

  return <div ref={containerRef} id={`turnstile-${reactId}`} className={className} />;
}

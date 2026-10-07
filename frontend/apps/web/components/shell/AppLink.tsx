"use client";

import Link from "next/link";
import type { UiLinkProps } from "@vitaminvui/ui/v2";
import { isCaptchaHref } from "@/lib/routes";

/**
 * Liên kết nội bộ của web: `next/link`, TRỪ khi đích là route có Turnstile (`CAPTCHA_PATHS`) — khi đó là `<a href>` thường
 * để trình duyệt tải lại tài liệu và nhận đúng CSP của route đó (xem `lib/routes.ts`).
 */
export function AppLink({ href, ...rest }: UiLinkProps) {
  if (isCaptchaHref(href)) return <a href={href} {...rest} />;
  return <Link href={href} {...rest} />;
}

import { NextResponse, type NextRequest } from "next/server";
import { env } from "@/env";

import { isBlockedPreview } from "@/lib/previewGate";

/**
 * Proxy sinh nonce CSP + header bảo mật cho app admin (ADR-004 §2.5–2.6). Khác app web:
 * `frame-src 'none'` (không nhúng video ngoài ở khu quản trị).
 * Cũng chặn bản xem trước `/v2/...` ở production (xem `lib/previewGate.ts`): rewrite sang route không tồn tại để có 404 thật
 * (layout `notFound()` đơn lẻ trả 200 vì đã stream), vẫn đi qua đường đặt header bảo mật bên dưới.
 */
export function proxy(request: NextRequest) {
  const nonce = Buffer.from(crypto.randomUUID()).toString("base64");
  const isDev = process.env.NODE_ENV === "development";

  const cspHeader = `
    default-src 'self';
    script-src 'self' 'nonce-${nonce}' 'strict-dynamic'${isDev ? " 'unsafe-eval'" : ""};
    style-src 'self' 'unsafe-inline';
    img-src 'self' data: ${env.NEXT_PUBLIC_STATIC_URL};
    font-src 'self';
    connect-src 'self' ${env.NEXT_PUBLIC_ADMIN_API_URL} ${env.NEXT_PUBLIC_VIDEO_UPLOAD_URL};
    media-src 'self' blob:;
    frame-src 'none';
    object-src 'none';
    base-uri 'none';
    frame-ancestors 'none';
    form-action 'self';
  `
    .replace(/\s{2,}/g, " ")
    .trim();

  const requestHeaders = new Headers(request.headers);
  requestHeaders.set("x-nonce", nonce);
  requestHeaders.set("Content-Security-Policy", cspHeader);

  const response = isBlockedPreview(request.nextUrl.pathname)
    ? NextResponse.rewrite(new URL("/khong-ton-tai", request.url), { request: { headers: requestHeaders } })
    : NextResponse.next({ request: { headers: requestHeaders } });

  response.headers.set("Content-Security-Policy", cspHeader);
  response.headers.set("X-Content-Type-Options", "nosniff");
  response.headers.set("Referrer-Policy", "strict-origin-when-cross-origin");
  response.headers.set("Permissions-Policy", "camera=(), microphone=(), geolocation=()");
  if (!isDev) {
    response.headers.set("Strict-Transport-Security", "max-age=63072000; includeSubDomains; preload");
  }

  return response;
}

export const config = {
  matcher: [
    // Cổng /v2: KHÔNG có `missing` để request mang header prefetch cũng chạy proxy (không vượt được gate).
    { source: "/v2" },
    { source: "/v2/:path*" },
    {
      // ADR-004 §2.7: chỉ loại trừ route không phải HTML (không cần nonce) —
      // _next/static, _next/image, favicon.ico, robots.txt, sitemap.xml, file tĩnh trong public/.
      source:
        "/((?!_next/static|_next/image|favicon\\.ico|robots\\.txt|sitemap\\.xml|.*\\.(?:svg|png|jpg|jpeg|gif|webp|ico|css|js|map|txt|xml|woff|woff2|ttf)$).*)",
      missing: [
        { type: "header", key: "next-router-prefetch" },
        { type: "header", key: "purpose", value: "prefetch" },
      ],
    },
  ],
};

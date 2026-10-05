import { NextResponse, type NextRequest } from "next/server";
import { env } from "@/env";

/** Route có Turnstile — được thêm Cloudflare vào connect-src/frame-src (ADR-004 §2.6). */
const CAPTCHA_PATHS = new Set(["/dang-ky"]);

/**
 * Proxy (đổi tên từ middleware ở Next.js 16 — xem node_modules/next/dist/docs/01-app/
 * 03-api-reference/03-file-conventions/proxy.md) sinh nonce CSP + header bảo mật cho
 * MỌI trang app web (ADR-004 §2.5–2.6). Vì CSP dùng nonce, Next.js buộc các trang render
 * động (không static/ISR toàn trang) — publicFetch phía trong vẫn tận dụng được Next Data
 * Cache qua `revalidate` (xem `lib/api.server.ts`).
 */
export function proxy(request: NextRequest) {
  const nonce = Buffer.from(crypto.randomUUID()).toString("base64");
  const isDev = process.env.NODE_ENV === "development";

  // Turnstile chỉ ở route có captcha (ADR-004 §2.6); script-src không cần host Cloudflare nhờ strict-dynamic.
  const captchaSrc = CAPTCHA_PATHS.has(request.nextUrl.pathname) ? "https://challenges.cloudflare.com " : "";

  const videoHosts = env.NEXT_PUBLIC_VIDEO_HOSTS.join(" ");

  const cspHeader = `
    default-src 'self';
    script-src 'self' 'nonce-${nonce}' 'strict-dynamic'${isDev ? " 'unsafe-eval'" : ""};
    style-src 'self' 'unsafe-inline';
    img-src 'self' data: ${env.NEXT_PUBLIC_STATIC_URL};
    font-src 'self';
    connect-src 'self' ${env.NEXT_PUBLIC_API_URL} ${captchaSrc}${videoHosts};
    media-src 'self' blob: ${videoHosts};
    frame-src https://www.youtube-nocookie.com https://player.vimeo.com ${captchaSrc.trim()};
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

  const response = NextResponse.next({ request: { headers: requestHeaders } });

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

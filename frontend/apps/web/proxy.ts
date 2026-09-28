import { NextResponse, type NextRequest } from "next/server";
import { env } from "@/env";

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

  const videoHosts = env.NEXT_PUBLIC_VIDEO_HOSTS.join(" ");

  // Cloudflare Turnstile (FW1 — US-001 đăng ký, US-015 quên mật khẩu): widget tự chèn
  // <script src> + mở iframe thử thách + gọi XHR về chính host này. Cần thêm
  // "challenges.cloudflare.com" ở cả 3 directive theo khuyến nghị CSP chính thức của
  // Cloudflare. `script-src` có 'strict-dynamic' nên script do TurnstileWidget tự chèn
  // (bằng document.createElement từ code đã được nonce tin cậy) đã được trình duyệt hỗ
  // trợ strict-dynamic tự tin cậy qua lan truyền; khai báo thêm host ở đây chỉ để phòng
  // hờ trình duyệt cũ không hỗ trợ strict-dynamic.
  const cspHeader = `
    default-src 'self';
    script-src 'self' 'nonce-${nonce}' 'strict-dynamic' https://challenges.cloudflare.com${isDev ? " 'unsafe-eval'" : ""};
    style-src 'self' 'unsafe-inline';
    img-src 'self' data: ${env.NEXT_PUBLIC_STATIC_URL};
    font-src 'self';
    connect-src 'self' ${env.NEXT_PUBLIC_API_URL} ${videoHosts} https://challenges.cloudflare.com;
    media-src 'self' blob: ${videoHosts};
    frame-src https://www.youtube-nocookie.com https://player.vimeo.com https://challenges.cloudflare.com;
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

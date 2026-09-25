import type { NextConfig } from "next";

// Xem giải thích ở apps/web/next.config.ts — đọc trực tiếp process.env để tránh phụ
// thuộc thứ tự load env của Next.js khi require next.config.ts.
const staticUrl = process.env.NEXT_PUBLIC_STATIC_URL ?? "http://localhost:8080";
const staticUrlParsed = new URL(staticUrl);

const nextConfig: NextConfig = {
  poweredByHeader: false,
  transpilePackages: ["@vitaminvui/api-client", "@vitaminvui/ui"],
  images: {
    remotePatterns: [
      {
        protocol: staticUrlParsed.protocol.replace(":", "") as "http" | "https",
        hostname: staticUrlParsed.hostname,
        port: staticUrlParsed.port || undefined,
      },
    ],
  },
};

export default nextConfig;

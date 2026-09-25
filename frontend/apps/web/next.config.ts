import type { NextConfig } from "next";

// Đọc trực tiếp process.env (không qua "@/env") vì next.config.ts được Next.js require
// trước khi một số bước load .env nội bộ chạy xong — tránh phụ thuộc thứ tự import.
// "@/env.ts" vẫn là nguồn validate chính thức dùng trong code ứng dụng (app/, lib/).
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

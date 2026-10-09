import path from "node:path";
import type { NextConfig } from "next";

// Đọc trực tiếp process.env (không qua "@/env") vì next.config.ts được Next.js require
// trước khi một số bước load .env nội bộ chạy xong — tránh phụ thuộc thứ tự import.
// "@/env.ts" vẫn là nguồn validate chính thức dùng trong code ứng dụng (app/, lib/).
const staticUrl = process.env.NEXT_PUBLIC_STATIC_URL ?? "http://localhost:8080";
const staticUrlParsed = new URL(staticUrl);

const nextConfig: NextConfig = {
  // T35-2 (ADR-008 §8.9): đóng gói Docker. `next dev` không bị ảnh hưởng. Gốc truy vết = frontend/ để gom
  // packages/* của workspace (pnpm) vào .next/standalone/apps/<app>/server.js.
  // `next build` dùng tsconfig riêng loại file test/e2e khỏi typecheck (vài test import chéo apps/); `tsc`/vitest vẫn dùng tsconfig.json.
  typescript: { tsconfigPath: "tsconfig.build.json" },
  output: "standalone",
  outputFileTracingRoot: path.join(__dirname, "../.."),
  // Cho phép e2e/build kiểm tra vào thư mục riêng (NEXT_DIST_DIR=.next-e2e-fw4) mà không đụng `.next` dùng chung.
  distDir: /^\.next[\w-]*$/.test(process.env.NEXT_DIST_DIR ?? "") ? (process.env.NEXT_DIST_DIR as string) : ".next",
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

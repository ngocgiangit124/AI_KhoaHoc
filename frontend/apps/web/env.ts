import { z } from "zod";

/**
 * Biến môi trường công khai (`NEXT_PUBLIC_*`) — an toàn dùng ở cả Server và Client
 * Component (tasks.md FE0). KHÔNG đặt secret ở đây.
 *
 * `next.config.ts` import file này để validate ngay lúc `next build`/`next dev` khởi động.
 */
const publicEnvSchema = z.object({
  NEXT_PUBLIC_API_URL: z.string().url(),
  NEXT_PUBLIC_SITE_URL: z.string().url(),
  NEXT_PUBLIC_STATIC_URL: z.string().url(),
  /** Danh sách host video, phân tách bởi dấu phẩy (dùng cho CSP connect-src/media-src). */
  NEXT_PUBLIC_VIDEO_HOSTS: z
    .string()
    .default("")
    .transform((value) => value.split(",").map((v) => v.trim()).filter(Boolean)),
  NEXT_PUBLIC_TURNSTILE_SITE_KEY: z.string().default(""),
  /** Danh sách host MoMo được phép mở `pay_url` (S23), phân tách bởi dấu phẩy. */
  NEXT_PUBLIC_MOMO_HOSTS: z
    .string()
    .default("test-payment.momo.vn")
    .transform((value) => value.split(",").map((v) => v.trim()).filter(Boolean)),
});

function parsePublicEnv() {
  const result = publicEnvSchema.safeParse({
    NEXT_PUBLIC_API_URL: process.env.NEXT_PUBLIC_API_URL,
    NEXT_PUBLIC_SITE_URL: process.env.NEXT_PUBLIC_SITE_URL,
    NEXT_PUBLIC_STATIC_URL: process.env.NEXT_PUBLIC_STATIC_URL,
    NEXT_PUBLIC_VIDEO_HOSTS: process.env.NEXT_PUBLIC_VIDEO_HOSTS,
    NEXT_PUBLIC_TURNSTILE_SITE_KEY: process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY,
    NEXT_PUBLIC_MOMO_HOSTS: process.env.NEXT_PUBLIC_MOMO_HOSTS,
  });

  if (!result.success) {
    throw new Error(
      `Biến môi trường NEXT_PUBLIC_* không hợp lệ (apps/web):\n${result.error.issues
        .map((issue) => `  - ${issue.path.join(".")}: ${issue.message}`)
        .join("\n")}\nXem apps/web/.env.example.`,
    );
  }

  return result.data;
}

export const env = parsePublicEnv();

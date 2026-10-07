import "server-only";
import { z } from "zod";
import { env as publicEnv } from "./env";

/**
 * Biến môi trường CHỈ dùng ở server (SSR trang công khai — ADR-004 §2.1). `server-only`
 * chặn cứng việc import nhầm vào bundle Client Component.
 */
const serverEnvSchema = z.object({
  /**
   * Địa chỉ Laravel khi SSR gọi (ADR-004 §2.8). Production: `http://<IP_NOI_BO_NGINX>:8081` (IP trần — listener nội bộ của
   * Nginx tự ép `Host` đúng nên Laravel định tuyến được). Local: `http://api.localhost:8000` (tên miền, kèm `extra_hosts`
   * trong Docker) vì Nginx local định tuyến theo `Host` và `fetch` của Node không cho ghi đè `Host`.
   */
  API_INTERNAL_URL: z.string().url().default(publicEnv.NEXT_PUBLIC_API_URL),
  /**
   * Bí mật SSR (T26/T31): khớp `INTERNAL_API_TOKEN` của Laravel để throttle `catalog` không gộp mọi người dùng
   * vào một IP. Chỉ ở server, KHÔNG bao giờ đưa xuống trình duyệt. Trống = không gửi header (dev).
   */
  INTERNAL_API_TOKEN: z
    .string()
    .optional()
    .transform((value) => (value && value.trim() !== "" ? value.trim() : undefined))
    .pipe(z.string().min(32, "INTERNAL_API_TOKEN phải dài ít nhất 32 ký tự (khớp backend/config/internal.php)").optional()),
});

function parseServerEnv() {
  const result = serverEnvSchema.safeParse({
    API_INTERNAL_URL: process.env.API_INTERNAL_URL,
    INTERNAL_API_TOKEN: process.env.INTERNAL_API_TOKEN,
  });

  if (!result.success) {
    throw new Error(
      `Biến môi trường server không hợp lệ (apps/web):\n${result.error.issues
        .map((issue) => `  - ${issue.path.join(".")}: ${issue.message}`)
        .join("\n")}\nXem apps/web/.env.example.`,
    );
  }

  return result.data;
}

export const serverEnv = parseServerEnv();

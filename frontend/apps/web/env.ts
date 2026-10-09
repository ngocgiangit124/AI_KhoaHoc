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
    .transform((value) => value.split(",").map((v) => v.trim()).filter(Boolean))
    // Mỗi phần tử đi thẳng vào CSP: chỉ nhận origin (`[http(s)://]host[:port]`), không `;`/khoảng trắng/đường dẫn (chèn directive).
    .pipe(z.array(z.string().regex(/^(https?:\/\/)?[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/i, "phải là origin dạng https://host[:port]"))),
  NEXT_PUBLIC_TURNSTILE_SITE_KEY: z.string().default(""),
  /** Origin app admin, chỉ cho liên kết ở trang xem trước /v2 (T35-2). Rỗng = liên kết tương đối. Không nhúng host local vào image. */
  NEXT_PUBLIC_ADMIN_URL: z.string().default(""),
  /**
   * Danh sách host MoMo được phép mở `pay_url` (S23), phân tách bởi dấu phẩy. Mặc định RỖNG (MoMo tắt, readiness D5/E2):
   * production/staging không nhúng `test-payment.momo.vn`; local đặt rõ trong `.env.example`.
   */
  NEXT_PUBLIC_MOMO_HOSTS: z
    .string()
    .default("")
    .transform((value) => value.split(",").map((v) => v.trim()).filter(Boolean)),
});

function parsePublicEnv() {
  const result = publicEnvSchema.safeParse({
    NEXT_PUBLIC_API_URL: process.env.NEXT_PUBLIC_API_URL,
    NEXT_PUBLIC_SITE_URL: process.env.NEXT_PUBLIC_SITE_URL,
    NEXT_PUBLIC_STATIC_URL: process.env.NEXT_PUBLIC_STATIC_URL,
    NEXT_PUBLIC_VIDEO_HOSTS: process.env.NEXT_PUBLIC_VIDEO_HOSTS,
    NEXT_PUBLIC_TURNSTILE_SITE_KEY: process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY,
    NEXT_PUBLIC_ADMIN_URL: process.env.NEXT_PUBLIC_ADMIN_URL,
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

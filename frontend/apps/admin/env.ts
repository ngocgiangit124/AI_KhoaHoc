import { z } from "zod";

/**
 * Biến môi trường công khai (`NEXT_PUBLIC_*`) của app admin (tasks.md FE0). Admin không
 * SSR dữ liệu cá nhân (ADR-004 §2.5) nên chưa cần biến server-only riêng ở FE0; nếu sau
 * này có SSR cần gọi admin-api nội bộ, thêm `env.server.ts` với `import 'server-only'`
 * (theo mẫu `apps/web/src/env.server.ts`).
 */
const publicEnvSchema = z.object({
  NEXT_PUBLIC_ADMIN_API_URL: z.string().url(),
  NEXT_PUBLIC_ADMIN_URL: z.string().url(),
  NEXT_PUBLIC_STATIC_URL: z.string().url(),
  NEXT_PUBLIC_VIDEO_UPLOAD_URL: z.string().url(),
});

function parsePublicEnv() {
  const result = publicEnvSchema.safeParse({
    NEXT_PUBLIC_ADMIN_API_URL: process.env.NEXT_PUBLIC_ADMIN_API_URL,
    NEXT_PUBLIC_ADMIN_URL: process.env.NEXT_PUBLIC_ADMIN_URL,
    NEXT_PUBLIC_STATIC_URL: process.env.NEXT_PUBLIC_STATIC_URL,
    NEXT_PUBLIC_VIDEO_UPLOAD_URL: process.env.NEXT_PUBLIC_VIDEO_UPLOAD_URL,
  });

  if (!result.success) {
    throw new Error(
      `Biến môi trường NEXT_PUBLIC_* không hợp lệ (apps/admin):\n${result.error.issues
        .map((issue) => `  - ${issue.path.join(".")}: ${issue.message}`)
        .join("\n")}\nXem apps/admin/.env.example.`,
    );
  }

  return result.data;
}

export const env = parsePublicEnv();

import "server-only";
import { z } from "zod";
import { env as publicEnv } from "./env";

/**
 * Biến môi trường CHỈ dùng ở server (SSR trang công khai — ADR-004 §2.1). `server-only`
 * chặn cứng việc import nhầm vào bundle Client Component.
 */
const serverEnvSchema = z.object({
  API_INTERNAL_URL: z.string().url().default(publicEnv.NEXT_PUBLIC_API_URL),
});

function parseServerEnv() {
  const result = serverEnvSchema.safeParse({
    API_INTERNAL_URL: process.env.API_INTERNAL_URL,
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

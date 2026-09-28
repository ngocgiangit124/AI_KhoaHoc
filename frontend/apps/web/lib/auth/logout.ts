import { authFetch } from "@/lib/api";
import { notifyAuthChanged } from "./useCurrentUser";

/** `POST /auth/logout` (api-contract §2.2) — chỉ cần `auth:sanctum`, trả 204. */
export async function logout(): Promise<void> {
  await authFetch<void>("/api/v1/auth/logout", { method: "POST" });
  notifyAuthChanged();
}

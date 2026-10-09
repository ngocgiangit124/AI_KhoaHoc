import { z } from "zod";
import { authFetch } from "@/lib/api";
import { cartSchema } from "@/lib/orders/schemas";
import type { CardOwnership } from "./cta";

const courseRefSchema = z.object({ course: z.object({ id: z.number() }) });
const myCoursesSchema = z.object({
  data: z.array(courseRefSchema),
  pending: z.array(courseRefSchema).default([]),
});

/**
 * Hai lời gọi cho CẢ trang (không theo từng thẻ): `GET /cart` (khóa trong giỏ) và `GET /me/courses` (khóa đã sở hữu + chờ duyệt;
 * tối đa 30 khóa đầu — khóa sở hữu nằm ngoài đó sẽ được server chặn bằng 409 `ALREADY_OWNED` và thẻ tự chuyển trạng thái).
 */
export async function fetchCardOwnership(signal?: AbortSignal): Promise<CardOwnership> {
  const [cartRaw, mineRaw] = await Promise.all([
    authFetch<unknown>("/api/v1/cart", { signal }),
    authFetch<unknown>("/api/v1/me/courses?per_page=30", { signal }),
  ]);
  const cart = cartSchema.parse(cartRaw);
  const mine = myCoursesSchema.parse(mineRaw);
  return {
    cartIds: new Set(cart.items.map((i) => i.course_id)),
    ownedIds: new Set(mine.data.map((c) => c.course.id)),
    pendingIds: new Set(mine.pending.map((c) => c.course.id)),
  };
}

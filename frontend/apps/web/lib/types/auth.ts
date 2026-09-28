import { z } from "zod";

/**
 * `POST /auth/register` (201) và `POST /auth/login` (200) trả "user" phẳng (api-contract
 * §1.4, §2.2). `GET /auth/me` trả thêm `is_verified`, `parent_consent_status`,
 * `cart_count`, thông tin phụ huynh đã che.
 *
 * GIẢ ĐỊNH (ghi rõ cho laravel-dev đối chiếu khi T03 xong — api-contract chưa liệt kê chi
 * tiết field trả về của user resource): chỉ khai báo chặt các field UI thật sự dùng ở FW1
 * phần 1 (`name`, `is_verified`, `parent_consent_status`); dùng `.passthrough()` để không
 * vỡ khi backend trả thêm/khác field (v1 chỉ được thêm trường — api-contract §1.1).
 */
export const authUserSchema = z
  .object({
    id: z.union([z.string(), z.number()]).optional(),
    name: z.string(),
    email: z.string().nullable().optional(),
    phone: z.string().nullable().optional(),
    role: z.string().optional(),
    grade_level: z.number().nullable().optional(),
    is_verified: z.boolean().optional(),
    parent_consent_status: z.enum(["not_required", "pending", "granted", "revoked"]).optional(),
    cart_count: z.number().optional(),
  })
  .passthrough();

export type AuthUser = z.infer<typeof authUserSchema>;

/**
 * Validate response user lúc runtime (không chỉ tin type TypeScript tĩnh — cùng quy ước
 * với `lib/types/config.ts`). Backend trả sai hình dạng → lỗi tiếng Việt rõ ràng thay vì
 * crash trắng trang hoặc hiển thị `undefined`.
 */
export function parseAuthUser(data: unknown): AuthUser {
  const result = authUserSchema.safeParse(data);
  if (!result.success) {
    if (process.env.NODE_ENV !== "production") {
      console.error("[auth] response user không khớp schema:", result.error.flatten());
    }
    throw new Error("Không đọc được thông tin tài khoản từ máy chủ.");
  }
  return result.data;
}

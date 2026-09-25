import "server-only";
import { publicFetch as publicFetchBase, type PublicFetchOptions } from "@vitaminvui/api-client";
import { serverEnv } from "@/env.server";

/**
 * `publicFetch` chạy ở SERVER (Server Component SSR/ISR trang công khai — danh mục, chi
 * tiết khóa học, `/config/public`) — gọi thẳng Laravel qua `API_INTERNAL_URL`, được phép
 * `revalidate`/`tags`. KHÔNG bao giờ dùng cho endpoint cần đăng nhập (S16): trang cần đăng
 * nhập phải đặt `export const dynamic = 'force-dynamic'` và lấy dữ liệu ở Client Component
 * bằng `authFetch` của `lib/api.ts`.
 */
export function publicFetchServer<T>(path: string, options?: PublicFetchOptions): Promise<T> {
  return publicFetchBase<T>(serverEnv.API_INTERNAL_URL, path, options);
}

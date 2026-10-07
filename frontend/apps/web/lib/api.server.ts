import "server-only";
import { publicFetch as publicFetchBase, type PublicFetchOptions } from "@vitaminvui/api-client";
import { serverEnv } from "@/env.server";

/**
 * `publicFetch` chạy ở SERVER (Server Component SSR/ISR trang công khai — danh mục, chi
 * tiết khóa học, `/config/public`) — gọi thẳng Laravel qua `API_INTERNAL_URL`, được phép
 * `revalidate`/`tags`. KHÔNG bao giờ dùng cho endpoint cần đăng nhập (S16): trang cần đăng
 * nhập phải đặt `export const dynamic = 'force-dynamic'` và lấy dữ liệu ở Client Component
 * bằng `authFetch` của `lib/api.ts`.
 *
 * Mọi request gắn `X-Internal-Token` (nếu có `INTERNAL_API_TOKEN`): Laravel tính throttle `catalog` theo nhóm
 * SSR thay vì gộp với người dùng thật. `clientIp` (tuỳ chọn) gắn thêm `X-Client-IP` để Laravel tính theo IP
 * khách thật — CHỈ dùng khi cần cô lập một khách (vd. tìm kiếm tự do `q`): Next đưa HEADER vào khoá Data Cache,
 * nên gắn IP vào mọi request sẽ tách cache theo từng IP (mất tác dụng cache và phình đĩa).
 */
export interface PublicFetchServerOptions extends Omit<PublicFetchOptions, "headers"> {
  headers?: Record<string, string>;
  clientIp?: string | null;
}

export function publicFetchServer<T>(path: string, options: PublicFetchServerOptions = {}): Promise<T> {
  const { clientIp, headers, ...rest } = options;
  const internal: Record<string, string> = {};
  if (serverEnv.INTERNAL_API_TOKEN) {
    internal["X-Internal-Token"] = serverEnv.INTERNAL_API_TOKEN;
    if (clientIp) internal["X-Client-IP"] = clientIp;
  }
  return publicFetchBase<T>(serverEnv.API_INTERNAL_URL, path, {
    ...rest,
    headers: { ...headers, ...internal },
  });
}

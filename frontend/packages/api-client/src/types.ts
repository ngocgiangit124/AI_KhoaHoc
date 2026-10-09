/**
 * Kiểu dữ liệu chung khớp với envelope API Laravel (api-contract.md §1.4, §1.5, §1.7).
 */

/** Envelope lỗi chuẩn — mọi lỗi 4xx/5xx từ backend. */
export interface ApiErrorBody {
  message: string;
  code?: string;
  errors?: Record<string, unknown>;
  request_id?: string;
  /** GL-A2: mọi 422 của đăng nhập — lần gửi sau có cần `captcha_token` không (thiếu = false). */
  captcha_required?: boolean;
}

/** Phân trang length-aware (danh mục, đơn của tôi, danh sách quản trị nhỏ). */
export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
  links: {
    next: string | null;
    prev: string | null;
  };
}

/** Phân trang cursor (chỉ danh sách đơn quản trị — DBA #9). */
export interface CursorPaginatedResponse<T> {
  data: T[];
  meta: {
    per_page: number;
    next_cursor: string | null;
    prev_cursor: string | null;
    total: number;
  };
}

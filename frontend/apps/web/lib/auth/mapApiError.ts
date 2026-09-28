import type { FieldValues, Path, UseFormSetError } from "react-hook-form";
import { ApiError } from "@vitaminvui/api-client";

export interface ApplyApiErrorResult {
  /** `null` khi mọi lỗi đã gắn được vào field — không cần banner chung. */
  bannerMessage: string | null;
  bannerVariant: "danger" | "warning";
}

/**
 * Ánh xạ `ApiError` (envelope lỗi api-contract §1.7) vào form `react-hook-form`:
 * - 422 `errors.{field}` khớp field đã biết của form → `setError(field, ...)` (ví dụ
 *   "Email đã được sử dụng" hiển thị ngay dưới field email).
 * - Field lạ (server thêm sau này) hoặc không có `errors` (lỗi chung — vd sai mật khẩu,
 *   `ACCOUNT_LOCKED`, `WRONG_PORTAL`, `CAPTCHA_FAILED`) → gộp vào banner.
 * - 429 `TOO_MANY_ATTEMPTS` hiển thị banner màu cảnh báo (warning), còn lại màu lỗi (danger).
 */
export function applyApiErrorToForm<TFieldValues extends FieldValues>(
  err: ApiError,
  setError: UseFormSetError<TFieldValues>,
  knownFields: readonly string[],
): ApplyApiErrorResult {
  const bannerVariant: "danger" | "warning" = err.status === 429 ? "warning" : "danger";
  const errors = err.errors;

  if (!errors || Object.keys(errors).length === 0) {
    return { bannerMessage: err.message, bannerVariant };
  }

  const leftoverMessages: string[] = [];
  for (const [field, messages] of Object.entries(errors)) {
    const message = messages?.[0];
    if (!message) continue;
    if (knownFields.includes(field)) {
      setError(field as Path<TFieldValues>, { type: "server", message });
    } else {
      leftoverMessages.push(message);
    }
  }

  return {
    bannerMessage: leftoverMessages.length > 0 ? leftoverMessages.join(" ") : null,
    bannerVariant,
  };
}

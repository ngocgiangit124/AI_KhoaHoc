"use client";

import type { UseFormRegister } from "react-hook-form";
import type { RegisterFormValues } from "@/lib/auth/schemas";

export interface ConsentCheckboxGroupProps {
  register: UseFormRegister<RegisterFormValues>;
  /** Phiên bản chính sách đang hiển thị — khớp bản ghi `consents` ở server (US-017). */
  policyVersion: string;
  error?: string;
  disabled?: boolean;
}

const LINK_CLASSES = "font-medium text-indigo-700 underline hover:text-indigo-800";

/**
 * 2 checkbox đồng ý TÁCH RIÊNG, không tick sẵn (US-017 BR1). Link mở tab mới.
 * Trang đích `/dieu-khoan`, `/chinh-sach-du-lieu` chưa có trong tasks — xem báo cáo FW1.
 */
export function ConsentCheckboxGroup({ register, policyVersion, error, disabled }: ConsentCheckboxGroupProps) {
  return (
    <fieldset className="space-y-3" aria-describedby={error ? "consent-error" : undefined}>
      <legend className="sr-only">Đồng ý điều khoản và chính sách</legend>

      <label className="flex items-start gap-3 text-sm text-gray-900">
        <input
          type="checkbox"
          className="mt-0.5 h-5 w-5 shrink-0 rounded border-gray-400 text-indigo-600 focus:ring-indigo-600"
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          {...register("accept_terms")}
        />
        <span>
          Tôi đã đọc và đồng ý với{" "}
          <a href="/dieu-khoan" target="_blank" rel="noopener noreferrer" className={LINK_CLASSES}>
            Điều khoản sử dụng
          </a>
        </span>
      </label>

      <label className="flex items-start gap-3 text-sm text-gray-900">
        <input
          type="checkbox"
          className="mt-0.5 h-5 w-5 shrink-0 rounded border-gray-400 text-indigo-600 focus:ring-indigo-600"
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          {...register("accept_privacy")}
        />
        <span>
          Tôi đã đọc và đồng ý với{" "}
          <a href="/chinh-sach-du-lieu" target="_blank" rel="noopener noreferrer" className={LINK_CLASSES}>
            Chính sách xử lý dữ liệu cá nhân
          </a>
        </span>
      </label>

      <p className="text-xs text-gray-600">Phiên bản chính sách: {policyVersion}</p>
      {error ? (
        <p id="consent-error" className="text-sm text-rose-600">
          {error}
        </p>
      ) : null}
    </fieldset>
  );
}

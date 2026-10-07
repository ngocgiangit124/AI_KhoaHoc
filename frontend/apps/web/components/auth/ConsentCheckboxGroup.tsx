"use client";

import Link from "next/link";
import type { UseFormRegister } from "react-hook-form";
import { Checkbox, cx } from "@vitaminvui/ui/v2";
import type { RegisterFormValues } from "@/lib/auth/schemas";
import { routes } from "@/lib/routes";

export interface ConsentCheckboxGroupProps {
  register: UseFormRegister<RegisterFormValues>;
  /** Phiên bản chính sách đang hiển thị — khớp bản ghi `consents` ở server (US-017). */
  policyVersion: string;
  error?: string;
  disabled?: boolean;
}

const LINK = "font-semibold text-primary underline underline-offset-2";

/**
 * 2 checkbox đồng ý TÁCH RIÊNG, không tick sẵn (US-017 BR1). Liên kết mở tab mới tới `/dieu-khoan`, `/chinh-sach-du-lieu`
 * (hiện là trang giữ chỗ chờ pháp chế — xem route `(site)/dieu-khoan`).
 */
export function ConsentCheckboxGroup({ register, policyVersion, error, disabled }: ConsentCheckboxGroupProps) {
  return (
    <fieldset
      id="reg-consent"
      aria-describedby={error ? "consent-error" : undefined}
      className={cx("flex flex-col rounded-card", error && "border border-danger/40 px-3 py-1")}
    >
      <legend className="sr-only">Đồng ý điều khoản và chính sách</legend>
      <Checkbox
        id="reg-accept-terms"
        disabled={disabled}
        aria-invalid={error ? true : undefined}
        label={
          <>
            Tôi đã đọc và đồng ý với{" "}
            <Link href={routes.terms} target="_blank" rel="noopener noreferrer" className={LINK}>
              Điều khoản sử dụng
            </Link>
          </>
        }
        {...register("accept_terms")}
      />
      <Checkbox
        id="reg-accept-privacy"
        disabled={disabled}
        aria-invalid={error ? true : undefined}
        label={
          <>
            Tôi đã đọc và đồng ý với{" "}
            <Link href={routes.privacy} target="_blank" rel="noopener noreferrer" className={LINK}>
              Chính sách xử lý dữ liệu cá nhân
            </Link>
          </>
        }
        {...register("accept_privacy")}
      />
      {error ? (
        <p id="consent-error" className="pb-2 text-sm font-medium text-danger">
          {error}
        </p>
      ) : null}
      <p className="pb-1 text-sm text-ink-soft">Phiên bản chính sách: {policyVersion}</p>
    </fieldset>
  );
}

import type { Metadata } from "next";
import { env } from "@/env";
import { ForgotPasswordForm } from "@/components/auth/ForgotPasswordForm";
import { CatalogBusy } from "@/components/catalog/CatalogBusy";
import { AuthFrame } from "@/components/v2/auth/AuthFrame";
import { fetchPublicConfig } from "@/lib/catalog/api";
import { isUpstreamBusy } from "@/lib/catalog/busy";
import { routes } from "@/lib/routes";

export const metadata: Metadata = { title: "Quên mật khẩu — VitaminVui" };

// CSP nonce (proxy.ts) buộc render động — ADR-004 §2.7.
export const dynamic = "force-dynamic";

/** Quên mật khẩu, bước 1 (US-015 §2.1). Bước 2 (mã + mật khẩu mới): `/quen-mat-khau/dat-lai`. */
export default async function ForgotPasswordPage() {
  let config;
  try {
    config = await fetchPublicConfig();
  } catch (err) {
    if (isUpstreamBusy(err)) return <CatalogBusy href={routes.forgotPassword} />;
    throw err;
  }

  return (
    <AuthFrame
      title="Quên mật khẩu"
      subtitle="Nhập email hoặc số điện thoại đã đăng ký. Chúng tôi sẽ gửi mã xác nhận tới email đã xác thực của bạn."
    >
      <ForgotPasswordForm captchaSiteKey={config.captcha_site_key || env.NEXT_PUBLIC_TURNSTILE_SITE_KEY || null} />
    </AuthFrame>
  );
}

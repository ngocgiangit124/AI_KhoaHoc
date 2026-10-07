import type { Metadata } from "next";
import { env } from "@/env";
import { ResetPasswordForm } from "@/components/auth/ResetPasswordForm";
import { CatalogBusy } from "@/components/catalog/CatalogBusy";
import { AuthFrame } from "@/components/v2/auth/AuthFrame";
import { fetchPublicConfig } from "@/lib/catalog/api";
import { isUpstreamBusy } from "@/lib/catalog/busy";
import { routes } from "@/lib/routes";

export const metadata: Metadata = { title: "Đặt lại mật khẩu — VitaminVui" };

export const dynamic = "force-dynamic";

/** Quên mật khẩu, bước 2 (US-015 §2.2): mã OTP + mật khẩu mới. Khách (chưa đăng nhập). */
export default async function ResetPasswordPage() {
  let config;
  try {
    config = await fetchPublicConfig();
  } catch (err) {
    if (isUpstreamBusy(err)) return <CatalogBusy href={routes.resetPassword} />;
    throw err;
  }

  return (
    <AuthFrame
      title="Đặt lại mật khẩu"
      subtitle="Nếu thông tin tồn tại, chúng tôi đã gửi mã gồm 6 chữ số đến email của bạn."
      aside="Đặt mật khẩu mới, nhớ ghi vào chỗ an toàn nhé!"
    >
      <ResetPasswordForm
        resendCooldownSeconds={config.otp.resend_cooldown_seconds}
        captchaSiteKey={config.captcha_site_key || env.NEXT_PUBLIC_TURNSTILE_SITE_KEY || null}
      />
    </AuthFrame>
  );
}

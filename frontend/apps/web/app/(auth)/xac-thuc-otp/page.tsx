import type { Metadata } from "next";
import { OtpVerifyForm } from "@/components/auth/OtpVerifyForm";
import { AuthProvider } from "@/lib/auth/AuthProvider";
import { CatalogBusy } from "@/components/catalog/CatalogBusy";
import { AuthFrame } from "@/components/v2/auth/AuthFrame";
import { fetchPublicConfig } from "@/lib/catalog/api";
import { isUpstreamBusy } from "@/lib/catalog/busy";

export const metadata: Metadata = { title: "Xác thực tài khoản — VitaminVui" };

export const dynamic = "force-dynamic";

export default async function VerifyOtpPage() {
  let config;
  try {
    config = await fetchPublicConfig();
  } catch (err) {
    if (isUpstreamBusy(err)) return <CatalogBusy href="/xac-thuc-otp" />;
    throw err;
  }

  return (
    <AuthFrame title="Xác thực tài khoản" aside="Một bước nữa thôi! Xác thực email để đăng ký khóa học.">
      <AuthProvider>
        <OtpVerifyForm ttlMinutes={config.otp.ttl_minutes} resendCooldownSeconds={config.otp.resend_cooldown_seconds} />
      </AuthProvider>
    </AuthFrame>
  );
}

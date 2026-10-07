import type { Metadata } from "next";
import { OtpVerifyForm } from "@/components/auth/OtpVerifyForm";
import { AuthProvider } from "@/lib/auth/AuthProvider";
import { CatalogBusy } from "@/components/catalog/CatalogBusy";
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
    <div className="mx-auto w-full max-w-[480px] px-4 py-8">
      <div className="rounded-card border border-line bg-surface p-6">
        <h1 className="mb-4 text-title font-extrabold tracking-heading text-ink">Xác thực tài khoản</h1>
        <AuthProvider>
          <OtpVerifyForm
            ttlMinutes={config.otp.ttl_minutes}
            resendCooldownSeconds={config.otp.resend_cooldown_seconds}
          />
        </AuthProvider>
      </div>
    </div>
  );
}

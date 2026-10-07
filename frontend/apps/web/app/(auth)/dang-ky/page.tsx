import type { Metadata } from "next";
import { env } from "@/env";
import { RegisterForm } from "@/components/auth/RegisterForm";
import { CatalogBusy } from "@/components/catalog/CatalogBusy";
import { AuthFrame } from "@/components/v2/auth/AuthFrame";
import { fetchPublicConfig } from "@/lib/catalog/api";
import { isUpstreamBusy } from "@/lib/catalog/busy";

export const metadata: Metadata = { title: "Tạo tài khoản học sinh — VitaminVui" };

// CSP nonce (proxy.ts) buộc render động — ADR-004 §2.7.
export const dynamic = "force-dynamic";

export default async function RegisterPage() {
  let config;
  try {
    config = await fetchPublicConfig();
  } catch (err) {
    if (isUpstreamBusy(err)) return <CatalogBusy href="/dang-ky" />;
    throw err;
  }

  return (
    <AuthFrame
      title="Tạo tài khoản học sinh"
      subtitle="Miễn phí, mất khoảng 2 phút."
      aside="Bắt đầu từ bài dễ nhất. Ai cũng học được Toán!"
    >
      <RegisterForm
        grades={config.grades}
        parentConsentAge={config.parent_consent_age}
        referralEnabled={config.referral_code_enabled}
        policyVersion={config.policy_version}
        captchaSiteKey={config.captcha_site_key || env.NEXT_PUBLIC_TURNSTILE_SITE_KEY || null}
      />
    </AuthFrame>
  );
}

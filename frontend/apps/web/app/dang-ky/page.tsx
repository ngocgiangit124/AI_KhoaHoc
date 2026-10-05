import type { Metadata } from "next";
import { env } from "@/env";
import { RegisterForm } from "@/components/auth/RegisterForm";
import { publicFetchServer } from "@/lib/api.server";
import { parsePublicConfig } from "@/lib/types/config";

export const metadata: Metadata = { title: "Tạo tài khoản học sinh — VitaminVui" };

// CSP nonce (proxy.ts) buộc render động — ADR-004 §2.7.
export const dynamic = "force-dynamic";

export default async function RegisterPage() {
  const config = parsePublicConfig(
    await publicFetchServer<unknown>("/api/v1/config/public", { revalidate: 60 }),
  );

  return (
    <main className="mx-auto w-full max-w-[480px] px-4 py-8">
      <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <p className="text-lg font-bold text-indigo-600">VitaminVui</p>
        <h1 className="mt-2 mb-6 text-2xl font-bold text-gray-900">Tạo tài khoản học sinh</h1>
        <RegisterForm
          grades={config.grades}
          parentConsentAge={config.parent_consent_age}
          referralEnabled={config.referral_code_enabled}
          policyVersion={config.policy_version}
          captchaSiteKey={config.captcha_site_key || env.NEXT_PUBLIC_TURNSTILE_SITE_KEY || null}
        />
      </div>
    </main>
  );
}

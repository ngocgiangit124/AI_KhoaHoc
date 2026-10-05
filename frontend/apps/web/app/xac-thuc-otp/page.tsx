import type { Metadata } from "next";
import { OtpVerifyForm } from "@/components/auth/OtpVerifyForm";
import { AuthProvider } from "@/lib/auth/AuthProvider";
import { publicFetchServer } from "@/lib/api.server";
import { parsePublicConfig } from "@/lib/types/config";

export const metadata: Metadata = { title: "Xác thực tài khoản — VitaminVui" };

export const dynamic = "force-dynamic";

export default async function VerifyOtpPage() {
  const config = parsePublicConfig(await publicFetchServer<unknown>("/api/v1/config/public", { revalidate: 60 }));

  return (
    <main className="mx-auto w-full max-w-[480px] px-4 py-8">
      <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <p className="text-lg font-bold text-indigo-600">VitaminVui</p>
        <h1 className="mt-2 mb-4 text-2xl font-bold text-gray-900">Xác thực tài khoản</h1>
        <AuthProvider>
          <OtpVerifyForm
            ttlMinutes={config.otp.ttl_minutes}
            resendCooldownSeconds={config.otp.resend_cooldown_seconds}
          />
        </AuthProvider>
      </div>
    </main>
  );
}

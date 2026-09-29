import { publicFetchServer } from "@/lib/api.server";
import { parsePublicConfig } from "@/lib/types/config";
import { VerifyOtpForm } from "./VerifyOtpForm";

/**
 * `/xac-thuc-otp` (US-001 §2.2, AC8/AC9). Render động bắt buộc (ADR-004 §2.7 — CSP nonce);
 * dữ liệu người dùng (`GET /auth/me`) chỉ lấy được ở Client Component qua `authFetch` (S16 —
 * không cache dữ liệu theo người dùng). Server Component chỉ lấy `config/public` (công khai)
 * để biết thời hạn OTP (`otp.ttl_minutes`) và cooldown gửi lại mặc định (`otp.resend_cooldown_seconds`).
 */
export const dynamic = "force-dynamic";

export default async function VerifyOtpPage() {
  const raw = await publicFetchServer<unknown>("/api/v1/config/public", { revalidate: 60 });
  const config = parsePublicConfig(raw);

  return (
    <main className="mx-auto max-w-md px-4 py-10">
      <VerifyOtpForm otpTtlMinutes={config.otp.ttl_minutes} resendCooldownSeconds={config.otp.resend_cooldown_seconds} />
    </main>
  );
}

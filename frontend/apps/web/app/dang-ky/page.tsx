import { headers } from "next/headers";
import { publicFetchServer } from "@/lib/api.server";
import { parsePublicConfig } from "@/lib/types/config";
import { RegisterForm } from "./RegisterForm";

/**
 * `/dang-ky` (US-001 §2.1). Render động bắt buộc (ADR-004 §2.7 — CSP nonce), lấy
 * `config/public` phía server (site key Turnstile, ngưỡng tuổi phụ huynh, bật/tắt mã giới
 * thiệu, phiên bản chính sách) rồi truyền xuống form client.
 */
export const dynamic = "force-dynamic";

export default async function RegisterPage() {
  const [raw, nonce] = await Promise.all([
    publicFetchServer<unknown>("/api/v1/config/public", { revalidate: 60 }),
    headers().then((h) => h.get("x-nonce")),
  ]);
  const config = parsePublicConfig(raw);

  return (
    <main className="mx-auto max-w-md px-4 py-6">
      <div className="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <h1 className="mb-1 text-2xl font-bold text-gray-900">Tạo tài khoản học sinh</h1>
        <p className="mb-6 text-sm text-gray-500">
          Đăng ký để mua khóa học và theo dõi tiến độ học tập của bạn.
        </p>

        <RegisterForm
          nonce={nonce}
          config={{
            grades: config.grades,
            captchaSiteKey: config.captcha_site_key,
            parentConsentAge: config.parent_consent_age,
            referralCodeEnabled: config.referral_code_enabled,
            policyVersion: config.policy_version,
          }}
        />
      </div>
    </main>
  );
}

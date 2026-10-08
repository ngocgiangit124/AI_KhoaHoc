import { AuthFrame } from "@/components/v2/auth/AuthFrame";
import { RegisterForm } from "@/components/v2/auth/RegisterForm";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { publicConfig } from "@/lib/mock/v2/catalog";

export const dynamic = "force-dynamic";

export default function RegisterPreview() {
  return (
    <StudentShell current="auth" loggedIn={false} minimal preview={<PreviewBar note="Chọn ngày sinh dưới 18 tuổi để khối phụ huynh (không bắt buộc) tự mở; bấm Tạo tài khoản khi để trống để thấy lỗi." />}>
      <AuthFrame title="Tạo tài khoản học sinh" subtitle="Miễn phí, mất khoảng 2 phút." aside="Bắt đầu từ bài dễ nhất. Ai cũng học được Toán!">
        <RegisterForm
          parentSuggestAge={publicConfig.parent_consent_age}
          policyVersion={publicConfig.policy_version}
          captchaConfigured={publicConfig.captcha_site_key !== null}
          referralEnabled
        />
      </AuthFrame>
    </StudentShell>
  );
}

import { AuthFrame } from "@/components/v2/auth/AuthFrame";
import { ForgotPasswordForm } from "@/components/v2/auth/ForgotPasswordForm";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";

export const dynamic = "force-dynamic";

/** Quên mật khẩu (US-015 bước 1). Bước 2 (mã + mật khẩu mới): `/v2/quen-mat-khau/dat-lai`. */
export default function ForgotPreview() {
  return (
    <StudentShell current="auth" loggedIn={false} minimal preview={<PreviewBar note="Bấm “Gửi mã” để xem màn đã gửi" />}>
      <AuthFrame title="Quên mật khẩu" subtitle="Nhập email hoặc số điện thoại đã đăng ký. Chúng tôi sẽ gửi mã xác nhận tới email đã xác thực của bạn.">
        <ForgotPasswordForm />
      </AuthFrame>
    </StudentShell>
  );
}

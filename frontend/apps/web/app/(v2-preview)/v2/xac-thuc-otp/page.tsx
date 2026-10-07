import { AuthFrame } from "@/components/v2/auth/AuthFrame";
import { OtpVerifyForm, type OtpDemoState } from "@/components/v2/auth/OtpVerifyForm";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { publicConfig } from "@/lib/mock/v2/catalog";
import { maskEmail, one, routes, sampleStudent } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const STATES: Array<{ key?: OtpDemoState; label: string }> = [
  { label: "Mặc định" },
  { key: "sai", label: "Sai mã (OTP_INVALID)" },
  { key: "het-han", label: "Hết hạn (OTP_EXPIRED)" },
  { key: "het-luot", label: "Sai 5 lần (429 của mã)" },
  { key: "qua-nhieu", label: "Thử quá nhiều (429)" },
  { key: "gui-loi", label: "Gửi mã lỗi (503)" },
  { key: "het-luot-gui", label: "Hết lượt gửi trong ngày" },
];

/** Xác thực tài khoản bằng mã OTP (US-001 §2.2, AC8). Học sinh đã đăng nhập, chưa xác thực. */
export default async function OtpPreview({ searchParams }: PageProps<"/v2/xac-thuc-otp">) {
  const sp = await searchParams;
  const raw = one(sp["trang-thai"]) as OtpDemoState | undefined;
  const state = STATES.some((s) => s.key === raw) ? raw : undefined;
  const masked = maskEmail(sampleStudent.email);
  return (
    <StudentShell
      current="auth"
      loggedIn
      minimal
      preview={
        <PreviewBar
          variants={STATES.map((s) => ({ label: s.label, href: s.key ? `${routes.verifyOtp}?trang-thai=${s.key}` : routes.verifyOtp, current: s.key === state }))}
          note="Nhập 000000 để thấy lỗi mã sai, mã khác coi như đúng."
        />
      }
    >
      <AuthFrame
        title="Xác thực tài khoản"
        aside="Một bước nữa thôi! Xác thực email để đăng ký khóa học."
        subtitle={
          <>
            Chúng tôi đã gửi mã gồm 6 chữ số tới <strong className="font-semibold text-ink">{masked}</strong>. Xác thực xong bạn có thể đăng ký khóa học.
          </>
        }
      >
        <OtpVerifyForm key={state ?? "mac-dinh"} maskedEmail={masked} ttlMinutes={publicConfig.otp.ttl_minutes} resendWait={45} demo={state} />
      </AuthFrame>
    </StudentShell>
  );
}

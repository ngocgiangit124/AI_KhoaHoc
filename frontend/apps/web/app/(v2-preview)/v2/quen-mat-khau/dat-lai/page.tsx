import { AuthFrame } from "@/components/v2/auth/AuthFrame";
import { ResetPasswordForm, type ResetDemoState } from "@/components/v2/auth/ResetPasswordForm";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const STATES: Array<{ key?: ResetDemoState; label: string }> = [
  { label: "Mặc định" },
  { key: "het-han", label: "Lỗi mã (luôn OTP_EXPIRED)" },
  { key: "pho-bien", label: "Mật khẩu phổ biến" },
  { key: "khong-khop", label: "Nhập lại không khớp" },
  { key: "qua-nhieu", label: "Thử quá nhiều (429)" },
];

/** Đặt lại mật khẩu bước 2 (US-015 §2.2): mã OTP + mật khẩu mới. Khách (chưa đăng nhập). */
export default async function ResetPasswordPreview({ searchParams }: PageProps<"/v2/quen-mat-khau/dat-lai">) {
  const sp = await searchParams;
  const raw = one(sp["trang-thai"]) as ResetDemoState | undefined;
  const state = STATES.some((s) => s.key === raw) ? raw : undefined;
  return (
    <StudentShell
      current="auth"
      loggedIn={false}
      minimal
      preview={
        <PreviewBar
          variants={STATES.map((s) => ({ label: s.label, href: s.key ? `${routes.resetPassword}?trang-thai=${s.key}` : routes.resetPassword, current: s.key === state }))}
          note="Mã 000000 → lỗi mã; mật khẩu matkhau123 → mật khẩu phổ biến; còn lại → về đăng nhập."
        />
      }
    >
      <AuthFrame title="Đặt lại mật khẩu" subtitle="Nếu thông tin tồn tại, chúng tôi đã gửi mã gồm 6 chữ số đến email của bạn." aside="Đặt mật khẩu mới, nhớ ghi vào chỗ an toàn nhé!">
        <ResetPasswordForm key={state ?? "mac-dinh"} login="minhanh.2011@gmail.com" demo={state} />
      </AuthFrame>
    </StudentShell>
  );
}

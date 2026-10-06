import { AuthFrame } from "@/components/v2/auth/AuthFrame";
import { LoginForm, type LoginNotice } from "@/components/v2/auth/LoginForm";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const NOTICES: Array<{ key?: LoginNotice; label: string }> = [
  { label: "Mặc định" },
  { key: "sai", label: "Sai thông tin" },
  { key: "qua-nhieu", label: "Sai nhiều lần" },
  { key: "khoa", label: "Bị khoá" },
  { key: "thiet-bi", label: "Bị đăng xuất do thiết bị khác" },
  { key: "het-phien", label: "Hết phiên" },
];

export default async function LoginPreview({ searchParams }: PageProps<"/v2/dang-nhap">) {
  const sp = await searchParams;
  const notice = one(sp["trang-thai"]) as LoginNotice | undefined;
  const valid = NOTICES.some((n) => n.key === notice) ? notice : undefined;
  return (
    <StudentShell
      current="auth"
      loggedIn={false}
      minimal
      preview={
        <PreviewBar
          variants={NOTICES.map((n) => ({
            label: n.label,
            href: n.key ? `${routes.login}?trang-thai=${n.key}` : routes.login,
            current: n.key === valid,
          }))}
        />
      }
    >
      <AuthFrame title="Đăng nhập" subtitle="Chào mừng bạn quay lại học tiếp.">
        <LoginForm notice={valid} />
      </AuthFrame>
    </StudentShell>
  );
}

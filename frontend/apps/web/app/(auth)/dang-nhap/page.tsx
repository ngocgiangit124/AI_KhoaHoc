import type { Metadata } from "next";
import { AuthFrame } from "@/components/v2/auth/AuthFrame";
import { LoginForm } from "@/components/auth/LoginForm";
import { parseLoginNotice } from "@/lib/auth/loginNotice";

export const metadata: Metadata = { title: "Đăng nhập — VitaminVui" };

export const dynamic = "force-dynamic";

export default async function LoginPage({ searchParams }: PageProps<"/dang-nhap">) {
  const { next, "trang-thai": status } = await searchParams;

  return (
    <AuthFrame title="Đăng nhập" subtitle="Chào mừng bạn quay lại học tiếp.">
      <LoginForm next={typeof next === "string" ? next : null} notice={parseLoginNotice(typeof status === "string" ? status : undefined)} />
    </AuthFrame>
  );
}

import type { Metadata } from "next";
import { AuthCard } from "@/components/auth/AuthCard";
import { LoginForm } from "@/components/auth/LoginForm";

export const metadata: Metadata = {
  title: "Đăng nhập quản trị — VitaminVui",
};

// Trang cần lấy CSRF token theo phiên (không cache) — ADR-004 §2.5.
export const dynamic = "force-dynamic";

export default async function AdminLoginPage({ searchParams }: PageProps<"/dang-nhap">) {
  const { next, reason } = await searchParams;

  return (
    <AuthCard title="Đăng nhập quản trị" description="Dành cho Admin, Quản lý trang, Giáo viên.">
      <LoginForm next={typeof next === "string" ? next : null} reason={typeof reason === "string" ? reason : null} />
    </AuthCard>
  );
}

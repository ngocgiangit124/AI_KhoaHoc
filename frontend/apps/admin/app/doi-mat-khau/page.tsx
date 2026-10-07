import type { Metadata } from "next";
import { AuthCard } from "@/components/auth/AuthCard";
import { ForcePasswordChangeForm } from "@/components/auth/ForcePasswordChangeForm";
import { SessionProvider } from "@/lib/auth/SessionProvider";

export const metadata: Metadata = { title: "Đổi mật khẩu — VitaminVui Quản trị" };

export const dynamic = "force-dynamic";

export default async function ForcePasswordPage({ searchParams }: PageProps<"/doi-mat-khau">) {
  const { next } = await searchParams;

  return (
    <AuthCard
      title="Đổi mật khẩu để tiếp tục"
      description="Đây là lần đăng nhập đầu tiên (hoặc mật khẩu của bạn vừa được Admin đặt lại). Vui lòng đặt mật khẩu mới trước khi sử dụng hệ thống."
    >
      <SessionProvider>
        <ForcePasswordChangeForm next={typeof next === "string" ? next : null} />
      </SessionProvider>
    </AuthCard>
  );
}

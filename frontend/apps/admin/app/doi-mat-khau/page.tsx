import type { Metadata } from "next";
import { Card } from "@vitaminvui/ui";
import { ForcePasswordChangeForm } from "@/components/auth/ForcePasswordChangeForm";
import { SessionProvider } from "@/lib/auth/SessionProvider";

export const metadata: Metadata = { title: "Đổi mật khẩu — VitaminVui Quản trị" };

export const dynamic = "force-dynamic";

export default async function ForcePasswordPage({ searchParams }: PageProps<"/doi-mat-khau">) {
  const { next } = await searchParams;

  return (
    <main className="flex min-h-full flex-1 items-center justify-center px-4 py-10">
      <Card className="w-full max-w-[420px]">
        <h1 className="text-xl font-semibold text-gray-900">Đổi mật khẩu để tiếp tục</h1>
        <p className="mt-1 mb-4 text-sm text-gray-600">
          Đây là lần đăng nhập đầu tiên (hoặc mật khẩu của bạn vừa được Admin đặt lại). Vui lòng đặt mật khẩu mới
          trước khi sử dụng hệ thống.
        </p>
        <SessionProvider>
          <ForcePasswordChangeForm next={typeof next === "string" ? next : null} />
        </SessionProvider>
      </Card>
    </main>
  );
}

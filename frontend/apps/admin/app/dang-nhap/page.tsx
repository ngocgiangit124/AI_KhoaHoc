import type { Metadata } from "next";
import { Card } from "@vitaminvui/ui";
import { LoginForm } from "@/components/LoginForm";

export const metadata: Metadata = {
  title: "Đăng nhập quản trị — VitaminVui",
};

// Trang cần lấy CSRF token theo phiên (không cache) — ADR-004 §2.5.
export const dynamic = "force-dynamic";

export default function AdminLoginPage() {
  return (
    <main className="flex min-h-full flex-1 items-center justify-center px-4 py-10">
      <Card className="w-full max-w-sm">
        <h1 className="text-xl font-semibold text-gray-900">Đăng nhập quản trị</h1>
        <p className="mt-1 text-sm text-gray-500">
          Dành cho Admin, Quản lý trang, Giáo viên.
        </p>
        <div className="mt-6">
          <LoginForm />
        </div>
      </Card>
    </main>
  );
}

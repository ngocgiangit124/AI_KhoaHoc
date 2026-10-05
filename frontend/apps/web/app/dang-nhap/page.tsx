import type { Metadata } from "next";
import { LoginForm } from "@/components/auth/LoginForm";

export const metadata: Metadata = { title: "Đăng nhập — VitaminVui" };

export const dynamic = "force-dynamic";

export default async function LoginPage({ searchParams }: PageProps<"/dang-nhap">) {
  const { next } = await searchParams;

  return (
    <main className="mx-auto w-full max-w-[480px] px-4 py-8">
      <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <p className="text-lg font-bold text-indigo-600">VitaminVui</p>
        <h1 className="mt-2 mb-6 text-2xl font-bold text-gray-900">Đăng nhập</h1>
        <LoginForm next={typeof next === "string" ? next : null} />
      </div>
    </main>
  );
}

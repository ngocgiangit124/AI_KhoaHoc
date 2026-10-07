import type { Metadata } from "next";
import { LoginForm } from "@/components/auth/LoginForm";

export const metadata: Metadata = { title: "Đăng nhập — VitaminVui" };

export const dynamic = "force-dynamic";

export default async function LoginPage({ searchParams }: PageProps<"/dang-nhap">) {
  const { next } = await searchParams;

  return (
    <div className="mx-auto w-full max-w-[480px] px-4 py-8">
      <div className="rounded-card border border-line bg-surface p-6">
        <h1 className="mb-6 text-title font-extrabold tracking-heading text-ink">Đăng nhập</h1>
        <LoginForm next={typeof next === "string" ? next : null} />
      </div>
    </div>
  );
}

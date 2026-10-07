import type { Metadata } from "next";
import { AuthCard } from "@/components/auth/AuthCard";
import { MfaForm } from "@/components/auth/MfaForm";
import { SessionProvider } from "@/lib/auth/SessionProvider";

export const metadata: Metadata = { title: "Xác thực 2 lớp — VitaminVui Quản trị" };

export const dynamic = "force-dynamic";

export default async function MfaPage({ searchParams }: PageProps<"/xac-thuc-mfa">) {
  const { next } = await searchParams;

  return (
    <AuthCard title="Xác thực 2 lớp">
      <SessionProvider>
        <MfaForm next={typeof next === "string" ? next : null} />
      </SessionProvider>
    </AuthCard>
  );
}

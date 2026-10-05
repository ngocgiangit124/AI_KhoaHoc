import type { Metadata } from "next";
import { Card } from "@vitaminvui/ui";
import { MfaForm } from "@/components/auth/MfaForm";
import { SessionProvider } from "@/lib/auth/SessionProvider";

export const metadata: Metadata = { title: "Xác thực 2 lớp — VitaminVui Quản trị" };

export const dynamic = "force-dynamic";

export default async function MfaPage({ searchParams }: PageProps<"/xac-thuc-mfa">) {
  const { next } = await searchParams;

  return (
    <main className="flex min-h-full flex-1 items-center justify-center px-4 py-10">
      <Card className="w-full max-w-[420px]">
        <h1 className="text-xl font-semibold text-gray-900">Xác thực 2 lớp</h1>
        <div className="mt-4">
          <SessionProvider>
            <MfaForm next={typeof next === "string" ? next : null} />
          </SessionProvider>
        </div>
      </Card>
    </main>
  );
}

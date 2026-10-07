import type { ReactNode } from "react";
import { UploadProvider } from "@/components/curriculum/useUploadManager";
import { AuthGate } from "@/components/shell/AuthGate";
import { SessionProvider } from "@/lib/auth/SessionProvider";

// Khu quản trị không SSR dữ liệu cá nhân (ADR-004 §2.5): phiên kiểm ở trình duyệt qua /admin/auth/me.
export const dynamic = "force-dynamic";

export default function AdminAreaLayout({ children }: { children: ReactNode }) {
  return (
    <SessionProvider>
      <UploadProvider>
        <AuthGate>{children}</AuthGate>
      </UploadProvider>
    </SessionProvider>
  );
}

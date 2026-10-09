"use client";

import { useEffect, type ReactNode } from "react";
import { usePathname, useRouter } from "next/navigation";
import { Alert, Button, LoadingRegion, Skeleton } from "@vitaminvui/ui/v2";
import { useSession } from "@/lib/auth/SessionProvider";
import { AdminShell } from "./AdminShell";
import { LockedNotice } from "./LockedNotice";

function Centered({ children }: { children: ReactNode }) {
  return <main className="mx-auto flex w-full max-w-xl flex-1 flex-col items-center justify-center px-4 py-16">{children}</main>;
}

/**
 * Cổng của khu `/quan-tri`: chỉ render khung + nội dung khi `/admin/auth/me` trả 200. Các trạng thái
 * khác dẫn tới đúng chỗ (đăng nhập, MFA, đổi mật khẩu) hoặc hiện thông báo (khoá, lỗi tạm thời).
 * Chỉ để trải nghiệm — quyền thật nằm ở API.
 */
export function AuthGate({ children }: { children: ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const { state, refresh } = useSession();

  useEffect(() => {
    const next = encodeURIComponent(pathname);
    if (state.kind === "guest") {
      const reason = state.reason ? `&reason=${state.reason}` : "";
      // Điều hướng CỨNG: CSP (Turnstile) gắn theo tài liệu, trang đăng nhập cần CSP của chính nó (GL-A2).
      // eslint-disable-next-line @next/next/no-location-assign-relative-destination -- cố ý: cần tải lại tài liệu để nhận CSP Turnstile
      window.location.assign(`/dang-nhap?next=${next}${reason}`);
    } else if (state.kind === "mfa_required") router.replace(`/xac-thuc-mfa?next=${next}`);
    else if (state.kind === "password_change_required") router.replace(`/doi-mat-khau?next=${next}`);
  }, [state, pathname, router]);

  if (state.kind === "staff") return <AdminShell user={state.user}>{children}</AdminShell>;

  // Bị khoá: chỉ hiện màn khoá (không còn menu/tên/nội dung cũ phía sau).
  if (state.kind === "locked") {
    return (
      <Centered>
        <LockedNotice />
      </Centered>
    );
  }

  if (state.kind === "error") {
    return (
      <Centered>
        <div className="flex w-full flex-col gap-4">
          <Alert tone="danger" title="Không tải được phiên đăng nhập">
            Vui lòng kiểm tra kết nối và thử lại.
          </Alert>
          <Button onClick={() => void refresh()}>Thử lại</Button>
        </div>
      </Centered>
    );
  }

  // loading, hoặc đang chuyển hướng
  return (
    <Centered>
      <LoadingRegion className="flex w-full flex-col gap-4">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-40 w-full" />
      </LoadingRegion>
    </Centered>
  );
}

"use client";

import { useEffect, type ReactNode } from "react";
import { usePathname, useRouter } from "next/navigation";
import { Alert, Button, Skeleton } from "@vitaminvui/ui";
import { ACCOUNT_LOCKED_MESSAGE } from "@/lib/auth/errors";
import { useSession } from "@/lib/auth/SessionProvider";
import { AdminShell } from "./AdminShell";

function Centered({ children }: { children: ReactNode }) {
  return <main className="mx-auto w-full max-w-xl flex-1 px-4 py-16">{children}</main>;
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
      router.replace(`/dang-nhap?next=${next}${reason}`);
    } else if (state.kind === "mfa_required") router.replace(`/xac-thuc-mfa?next=${next}`);
    else if (state.kind === "password_change_required") router.replace(`/doi-mat-khau?next=${next}`);
  }, [state, pathname, router]);

  if (state.kind === "staff") return <AdminShell user={state.user}>{children}</AdminShell>;

  if (state.kind === "locked") {
    return (
      <Centered>
        <Alert variant="danger" title="Tài khoản đã bị khóa">
          {ACCOUNT_LOCKED_MESSAGE}
        </Alert>
      </Centered>
    );
  }

  if (state.kind === "error") {
    return (
      <Centered>
        <Alert variant="danger" title="Không tải được phiên đăng nhập">
          Vui lòng kiểm tra kết nối và thử lại.
        </Alert>
        <Button className="mt-4" onClick={() => void refresh()}>
          Thử lại
        </Button>
      </Centered>
    );
  }

  // loading, hoặc đang chuyển hướng
  return (
    <Centered>
      <span className="sr-only" role="status">
        Đang tải…
      </span>
      <Skeleton variant="text" className="h-8 w-64" />
      <Skeleton variant="card" className="mt-6" />
    </Centered>
  );
}

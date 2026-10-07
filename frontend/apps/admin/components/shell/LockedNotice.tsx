import { Alert, buttonClasses } from "@vitaminvui/ui/v2";
import { ACCOUNT_LOCKED_MESSAGE } from "@/lib/auth/errors";

/**
 * Nội dung màn "tài khoản bị khoá" dùng chung cho `AuthGate` (trạng thái phiên) và overlay của `SessionWatcher`
 * (khoá giữa phiên). Liên kết là `<a>` thường (tải lại cả trang) để xoá sạch trạng thái khoá còn trong bộ nhớ.
 */
export function LockedNotice({ titleId }: { titleId?: string }) {
  return (
    <div className="flex w-full max-w-md flex-col gap-4">
      <Alert tone="danger" title={<span id={titleId}>Tài khoản đã bị khóa</span>} role="none">
        {ACCOUNT_LOCKED_MESSAGE}
      </Alert>
      <a href="/dang-nhap" className={buttonClasses({ variant: "primary", size: "md", block: true })}>
        Về trang đăng nhập
      </a>
    </div>
  );
}

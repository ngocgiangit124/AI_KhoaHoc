import { AccountGatePage } from "@/components/v2/auth/AccountGate";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

/**
 * Màn chặn "Cần xác thực tài khoản" (403 `ACCOUNT_NOT_VERIFIED`, US-001 §2.4 / AC9) — bản trang đầy đủ,
 * dùng khi mở thẳng một trang cần tài khoản đã xác thực (checkout ở V2). Ở trang chi tiết khóa học,
 * cùng nội dung hiện trong hộp thoại: `/v2/khoa-hoc/can-bac-hai-can-bac-ba?viewer=can_register_free&chan=xac-thuc`.
 */
export default function NeedVerifyPreview() {
  return (
    <StudentShell
      current="catalog"
      loggedIn
      preview={
        <PreviewBar
          variants={[
            { label: "Trang đầy đủ", href: routes.needVerify, current: true },
            { label: "Hộp thoại ở chi tiết khóa", href: `${routes.course("can-bac-hai-can-bac-ba")}?viewer=can_register_free&chan=xac-thuc` },
          ]}
        />
      }
    >
      <div className="px-4 py-10 sm:px-6 sm:py-16">
        <AccountGatePage kind="verify" />
      </div>
    </StudentShell>
  );
}

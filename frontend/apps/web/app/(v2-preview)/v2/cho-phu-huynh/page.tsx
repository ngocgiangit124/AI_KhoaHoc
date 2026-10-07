import { AccountGatePage } from "@/components/v2/auth/AccountGate";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const STATES = [
  { key: undefined, label: "Đang chờ" },
  { key: "het-luot", label: "Hết lượt gửi lại hôm nay" },
  { key: "rut-lai", label: "Phụ huynh đã rút đồng ý" },
] as const;

/**
 * Màn chặn "Chờ phụ huynh xác nhận" (403 `PARENT_CONSENT_REQUIRED`, US-017 BR6 / US-001 §2.5).
 * Chỉ xảy ra khi bật `FEATURE_PARENT_CONSENT_ENFORCED` (mặc định tắt ở v1).
 */
export default async function ParentPendingPreview({ searchParams }: PageProps<"/v2/cho-phu-huynh">) {
  const sp = await searchParams;
  const raw = one(sp["trang-thai"]);
  const state = STATES.find((s) => s.key === raw)?.key;
  return (
    <StudentShell
      current="catalog"
      loggedIn
      preview={
        <PreviewBar
          variants={[
            ...STATES.map((s) => ({ label: s.label, href: s.key ? `${routes.parentPending}?trang-thai=${s.key}` : routes.parentPending, current: s.key === state })),
            { label: "Hộp thoại ở chi tiết khóa", href: `${routes.course("can-bac-hai-can-bac-ba")}?viewer=can_register_free&chan=phu-huynh` },
          ]}
          note="Chỉ hiện khi bật FEATURE_PARENT_CONSENT_ENFORCED."
        />
      }
    >
      <div className="px-4 py-10 sm:px-6 sm:py-16">
        <AccountGatePage key={state ?? "cho"} kind={state === "rut-lai" ? "parent-revoked" : "parent-pending"} demo={{ resendExhausted: state === "het-luot" }} />
      </div>
    </StudentShell>
  );
}

"use client";

import { Badge } from "@vitaminvui/ui/v2";
import { fetchConsents } from "@/lib/privacy/api";
import { consentChannelLabel, consentLabel, formatVnDate } from "@/lib/privacy/format";
import type { ConsentsResponse } from "@/lib/privacy/schemas";
import { SectionState } from "./SectionState";
import { useLoad } from "./useLoad";

/**
 * Khối "Đồng ý" (api-contract §2.8.3): loại · phiên bản · thời điểm. Không bao giờ hiện ip/user-agent (server cũng không trả).
 * Dòng "Xác nhận của phụ huynh" (loại cũ) bị bỏ theo ADR-006. Muốn ngừng đồng ý điều khoản: xoá tài khoản (khối cuối trang).
 */
export function ConsentsSection() {
  const { state, reload } = useLoad<ConsentsResponse>(fetchConsents);
  return (
    <SectionState state={state} reload={reload}>
      {(data) => {
        const rows = data.data.filter((c) => c.type !== "parent_consent");
        if (rows.length === 0) return <p className="text-base text-ink-soft">Chưa có bản đồng ý nào được ghi nhận.</p>;
        return (
          <ul className="flex flex-col divide-y divide-line" aria-label="Danh sách đồng ý">
            {rows.map((c) => (
              <li key={`${c.type}-${c.policy_version}-${c.granted_at}`} className="flex flex-col gap-1 py-3 first:pt-0 last:pb-0">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-base font-semibold text-ink">{consentLabel(c.type)}</span>
                  {c.revoked_at ? (
                    <Badge tone="neutral" size="sm">Đã rút</Badge>
                  ) : c.is_current_version ? (
                    <Badge tone="success" size="sm">Đang hiệu lực</Badge>
                  ) : (
                    <Badge tone="warning" size="sm">Bản cũ</Badge>
                  )}
                </div>
                <p className="text-sm text-ink-soft">
                  Phiên bản <span className="num">{c.policy_version}</span> · Đồng ý ngày {formatVnDate(c.granted_at)} · {consentChannelLabel(c.channel)}
                </p>
              </li>
            ))}
          </ul>
        );
      }}
    </SectionState>
  );
}

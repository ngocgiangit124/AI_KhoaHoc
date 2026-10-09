"use client";

import { useState } from "react";
import Link from "next/link";
import { Button, Dialog } from "@vitaminvui/ui/v2";
import { actionLabel, actorView, changeEntries, formatAuditTime, shortUserAgent, subjectView, truncate } from "@/lib/audit/labels";
import type { AuditLog } from "@/lib/audit/schemas";

function Row({ term, children }: { term: string; children: React.ReactNode }) {
  return (
    <div className="grid gap-0.5 sm:grid-cols-[9rem_minmax(0,1fr)] sm:gap-3">
      <dt className="text-sm font-semibold text-ink-soft">{term}</dt>
      <dd className="min-w-0 break-words text-sm text-ink">{children}</dd>
    </div>
  );
}

/** Một giá trị của `changes`: văn bản thuần (React tự escape), dài thì cắt + "Xem thêm". */
function ChangeValue({ value }: { value: string }) {
  const [open, setOpen] = useState(false);
  const { text, truncated } = truncate(value);
  return (
    <span className="break-words font-mono text-xs">
      <span className="whitespace-pre-wrap">{open || !truncated ? value : text}</span>
      {truncated ? (
        <button type="button" aria-expanded={open} onClick={() => setOpen((v) => !v)} className="focus-ring ml-2 rounded px-1 font-sans text-xs font-semibold text-primary underline max-sm:min-h-11">
          {open ? "Thu gọn" : "Xem thêm"}
        </button>
      ) : null}
    </span>
  );
}

/** Hộp xem chi tiết một dòng nhật ký (chỉ đọc). Chỉ được render khi có dòng được chọn (tránh id trùng giữa các hộp). */
export function AuditDetailDialog({ log, onClose }: { log: AuditLog; onClose: () => void }) {
  const [showUa, setShowUa] = useState(false);
  const actor = actorView(log);
  const subject = subjectView(log);
  const entries = changeEntries(log.changes);
  const label = actionLabel(log.action);
  return (
    <Dialog
      open
      onClose={onClose}
      size="lg"
      title={label ?? log.action}
      description={<span className="font-mono text-xs">{log.action}</span>}
      footer={
        <Button variant="secondary" onClick={onClose} className="max-sm:h-11">
          Đóng
        </Button>
      }
    >
      <dl className="flex flex-col gap-3">
        <Row term="Thời điểm">
          <span className="num">{formatAuditTime(log.created_at)}</span>
        </Row>
        <Row term="Người làm">
          {actor.name}
          {actor.role ? <span className="text-ink-soft"> ({actor.role})</span> : null}
        </Row>
        <Row term="Đối tượng">
          {subject.href ? (
            <Link href={subject.href} className="focus-ring rounded font-semibold text-primary underline">
              {subject.text}
            </Link>
          ) : (
            subject.text
          )}
          {log.subject_type ? <span className="block break-all font-mono text-xs text-ink-soft">{log.subject_type}</span> : null}
        </Row>
        <Row term="Địa chỉ IP">
          <span className="font-mono text-xs">{log.ip ?? "—"}</span>
        </Row>
        <Row term="Trình duyệt">
          {shortUserAgent(log.user_agent)}
          {log.user_agent ? (
            <>
              <button type="button" aria-expanded={showUa} onClick={() => setShowUa((v) => !v)} className="focus-ring ml-2 rounded px-1 text-xs font-semibold text-primary underline max-sm:min-h-11">
                {showUa ? "Ẩn chuỗi gốc" : "Xem chuỗi gốc"}
              </button>
              {showUa ? <span className="mt-1 block break-all font-mono text-xs text-ink-soft">{log.user_agent}</span> : null}
            </>
          ) : null}
        </Row>
      </dl>
      <h3 className="mt-5 text-sm font-semibold text-ink">Nội dung thay đổi</h3>
      {entries.length === 0 ? (
        <p className="mt-1 text-sm text-ink-soft">Không có dữ liệu thay đổi được ghi lại.</p>
      ) : (
        <dl className="mt-2 flex flex-col gap-2 rounded-control bg-sunken p-3" data-testid="audit-changes">
          {entries.map((e) => (
            <div key={e.key} className="grid gap-0.5 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-3">
              <dt className="break-all font-mono text-xs font-semibold text-ink-soft">{e.key}</dt>
              <dd className="min-w-0">
                <ChangeValue value={e.value} />
              </dd>
            </div>
          ))}
        </dl>
      )}
    </Dialog>
  );
}

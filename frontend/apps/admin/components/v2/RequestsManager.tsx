"use client";

import { useState } from "react";
import {
  Badge,
  Button,
  DataTable,
  Dialog,
  EmptyState,
  Field,
  IconInbox,
  LinkTabs,
  Textarea,
  formatDateTime,
  useToast,
  type Column,
} from "@vitaminvui/ui/v2";
import type { EnrollmentRequest } from "@/lib/mock/v2/ops";

/**
 * Duyệt đăng ký khóa miễn phí (US-012, FA6; GET/POST /admin/enrollment-requests).
 * Sắp yêu cầu cũ nhất trước; email/SĐT đã che; nút khoá ngay khi bấm (chống bấm 2 lần);
 * từ chối có lý do tuỳ chọn (≤ 1.000 ký tự, văn bản thuần).
 * TODO(dev): 409 ALREADY_PROCESSED → bỏ dòng + toast "Yêu cầu đã được xử lý"; 422 COURSE_NOT_FREE, 409 COURSE_UNAVAILABLE → Alert tại dòng.
 */
export function RequestsManager({ initial, status, tabHref }: { initial: EnrollmentRequest[]; status: EnrollmentRequest["status"]; tabHref: Record<EnrollmentRequest["status"], string> }) {
  const toast = useToast();
  const [items, setItems] = useState(initial);
  const [busy, setBusy] = useState<number | null>(null);
  const [rejecting, setRejecting] = useState<EnrollmentRequest | null>(null);
  const [reason, setReason] = useState("");

  const rows = items.filter((r) => r.status === status).sort((a, b) => a.requested_at.localeCompare(b.requested_at));
  const count = (s: EnrollmentRequest["status"]) => items.filter((r) => r.status === s).length;

  function decide(r: EnrollmentRequest, next: "active" | "rejected", why?: string) {
    setBusy(r.id);
    setTimeout(() => {
      setItems((prev) => prev.map((x) => (x.id === r.id ? { ...x, status: next, rejection_reason: why || null } : x)));
      setBusy(null);
      toast.show({ tone: "success", title: next === "active" ? `Đã duyệt yêu cầu của ${r.student.name}` : `Đã từ chối yêu cầu của ${r.student.name}` });
    }, 600);
  }

  const columns: Array<Column<EnrollmentRequest>> = [
    {
      key: "student",
      header: "Học sinh",
      cell: (r) => (
        <div>
          <p className="font-semibold">{r.student.name}</p>
          <p className="text-xs text-ink-soft">
            Lớp {r.student.grade_level}
            {r.student.email_masked ? ` · ${r.student.email_masked}` : ""}
            {r.student.phone_masked ? ` · ${r.student.phone_masked}` : ""}
          </p>
        </div>
      ),
    },
    { key: "course", header: "Khóa học", cell: (r) => <span className="line-clamp-2">{r.course.title}</span> },
    { key: "time", header: "Gửi lúc", hideBelow: "md", cell: (r) => <span className="num whitespace-nowrap">{formatDateTime(r.requested_at)}</span> },
    status === "pending_approval"
      ? {
          key: "act",
          header: <span className="sr-only">Thao tác</span>,
          align: "right",
          cell: (r) => (
            <div className="flex justify-end gap-2">
              <Button size="sm" variant="secondary" disabled={busy === r.id} onClick={() => { setReason(""); setRejecting(r); }}>
                Từ chối
              </Button>
              <Button size="sm" loading={busy === r.id} loadingText="Đang duyệt…" onClick={() => decide(r, "active")}>
                Duyệt
              </Button>
            </div>
          ),
        }
      : {
          key: "result",
          header: "Kết quả",
          cell: (r) =>
            r.status === "active" ? (
              <Badge size="sm" tone="success">
                Đã duyệt
              </Badge>
            ) : (
              <div className="flex flex-col gap-1">
                <Badge size="sm" tone="danger">
                  Từ chối
                </Badge>
                {r.rejection_reason ? <span className="text-xs text-ink-soft">{r.rejection_reason}</span> : null}
              </div>
            ),
        },
  ];

  return (
    <div className="flex flex-col gap-4">
      <LinkTabs
        label="Trạng thái yêu cầu"
        items={[
          { href: tabHref.pending_approval, label: "Chờ duyệt", count: count("pending_approval"), current: status === "pending_approval" },
          { href: tabHref.active, label: "Đã duyệt", count: count("active"), current: status === "active" },
          { href: tabHref.rejected, label: "Đã từ chối", count: count("rejected"), current: status === "rejected" },
        ]}
      />
      <DataTable
        caption="Yêu cầu đăng ký khóa miễn phí"
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        density="compact"
        empty={<EmptyState size="inline" icon={<IconInbox size={24} />} title={status === "pending_approval" ? "Hiện không có yêu cầu nào đang chờ duyệt" : "Chưa có yêu cầu nào"} headingLevel="h2" />}
      />
      <Dialog
        open={rejecting !== null}
        onClose={() => setRejecting(null)}
        title={`Từ chối yêu cầu của ${rejecting?.student.name ?? ""}`}
        description={rejecting ? `Khóa: ${rejecting.course.title}. Học sinh nhận email kèm lý do (nếu có) và có thể đăng ký lại.` : undefined}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setRejecting(null)}>
              Huỷ
            </Button>
            <Button
              variant="danger"
              onClick={() => {
                if (rejecting) decide(rejecting, "rejected", reason.trim());
                setRejecting(null);
              }}
            >
              Xác nhận từ chối
            </Button>
          </>
        }
      >
        <Field label="Lý do (không bắt buộc)" aside={<span className="num text-ink-soft">{reason.length}/1000</span>}>
          <Textarea rows={3} maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)} className="text-sm" />
        </Field>
      </Dialog>
    </div>
  );
}

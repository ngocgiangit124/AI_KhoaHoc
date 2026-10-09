"use client";

import { useState, type FormEvent } from "react";
import { Avatar, Button, EmptyState, Field, IconLock, Textarea, formatDateTime, useToast } from "@vitaminvui/ui/v2";
import type { InternalNote } from "@/lib/mock/v2/orders";

const MAX = 1000;

/**
 * Ghi chú nội bộ (US-022 BR18, AC25): chỉ thêm, không sửa/xoá; mỗi ghi chú có người viết + thời điểm; học sinh không thấy.
 * Thêm được ở mọi trạng thái đơn, không đổi trạng thái. Mới nhất ở trên.
 * TODO(dev): POST /admin/orders/{code}/notes `{body}` → 201 note; 422 lỗi dưới ô; giữ nội dung ô khi lỗi mạng.
 */
export function InternalNotes({ initial, author, now }: { initial: InternalNote[]; author: { id: number; name: string }; now: string }) {
  const toast = useToast();
  const [notes, setNotes] = useState(() => [...initial].sort((a, b) => b.created_at.localeCompare(a.created_at)));
  const [body, setBody] = useState("");
  const [error, setError] = useState<string>();
  const [saving, setSaving] = useState(false);

  function submit(e: FormEvent) {
    e.preventDefault();
    const text = body.trim();
    if (!text) {
      setError("Nhập nội dung ghi chú.");
      return;
    }
    setSaving(true);
    setTimeout(() => {
      setNotes((prev) => [{ id: Date.now(), author, body: text, created_at: now }, ...prev]);
      setBody("");
      setSaving(false);
      toast.show({ tone: "success", title: "Đã lưu ghi chú nội bộ" });
    }, 500);
  }

  return (
    <div className="flex flex-col gap-4">
      <form onSubmit={submit} noValidate className="flex flex-col gap-2">
        <Field
          label="Thêm ghi chú"
          hint="Ví dụ: “Đã gọi 9h, hẹn chuyển khoản chiều nay”. Không sửa hay xoá được sau khi lưu."
          error={error}
          aside={<span className="num text-ink-soft">{body.length}/{MAX}</span>}
        >
          <Textarea
            rows={2}
            maxLength={MAX}
            value={body}
            onChange={(e) => {
              setBody(e.target.value);
              if (error) setError(undefined);
            }}
            className="text-sm"
          />
        </Field>
        <div>
          <Button type="submit" size="sm" variant="secondary" loading={saving} loadingText="Đang lưu…">
            Lưu ghi chú
          </Button>
        </div>
      </form>
      {notes.length ? (
        <ol className="flex flex-col divide-y divide-line">
          {notes.map((n) => (
            <li key={n.id} className="flex gap-3 py-3 first:pt-0">
              <Avatar name={n.author.name} size="sm" />
              <div className="min-w-0 flex-1">
                <p className="text-sm">
                  <span className="font-semibold text-ink">{n.author.name}</span>{" "}
                  <span className="num text-ink-soft">· {formatDateTime(n.created_at)}</span>
                </p>
                <p className="mt-0.5 whitespace-pre-line break-words text-sm text-ink">{n.body}</p>
              </div>
            </li>
          ))}
        </ol>
      ) : (
        <EmptyState size="inline" icon={<IconLock size={20} />} title="Chưa có ghi chú nội bộ" description="Ghi lại các lần liên hệ để người khác tiếp tục xử lý." headingLevel="h3" />
      )}
    </div>
  );
}

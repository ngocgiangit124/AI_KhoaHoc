"use client";

import { useRef, useState, type FormEvent } from "react";
import { Avatar, Button, EmptyState, Field, IconLock, Textarea, useToast } from "@vitaminvui/ui/v2";
import { addOrderNote } from "@/lib/orders/api";
import { classifyActionError } from "@/lib/orders/errors";
import { formatWhen } from "@/lib/orders/format";
import { NOTE_MAX, firstErrors, noteFormSchema, type OrderNote } from "@/lib/orders/schemas";

/**
 * Ghi chú nội bộ (US-022 BR18, AC25): chỉ thêm, không sửa/xoá; học sinh không thấy. Mới nhất ở trên. Thêm được ở mọi
 * trạng thái đơn. `body` hiển thị dạng TEXT (`whitespace-pre-line`), không HTML. Giữ nội dung ô khi lỗi; khoá nút khi gửi.
 */
export function InternalNotes({ code, notes, onAdded }: { code: string; notes: OrderNote[]; onAdded: (note: OrderNote) => void }) {
  const toast = useToast();
  const [body, setBody] = useState("");
  const [error, setError] = useState<string>();
  const [saving, setSaving] = useState(false);
  const lock = useRef(false);
  const sorted = [...notes].sort((a, b) => b.created_at.localeCompare(a.created_at) || b.id - a.id);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (lock.current) return;
    const parsed = noteFormSchema.safeParse({ body });
    if (!parsed.success) {
      setError(firstErrors(parsed.error)["body"]);
      return;
    }
    lock.current = true;
    setSaving(true);
    setError(undefined);
    try {
      const note = await addOrderNote(code, parsed.data.body);
      onAdded(note);
      setBody("");
      toast.show({ tone: "success", title: "Đã lưu ghi chú nội bộ" });
    } catch (err) {
      const f = classifyActionError(err);
      setError(f.fields.body ?? f.message);
    } finally {
      lock.current = false;
      setSaving(false);
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <form onSubmit={submit} noValidate className="flex flex-col gap-2">
        <Field
          label="Thêm ghi chú"
          hint="Ví dụ: “Đã gọi 9h, hẹn chuyển khoản chiều nay”. Không sửa hay xoá được sau khi lưu."
          error={error}
          aside={
            <span className="num text-ink-soft">
              {body.length}/{NOTE_MAX}
            </span>
          }
        >
          <Textarea
            rows={2}
            maxLength={NOTE_MAX}
            value={body}
            onChange={(e) => {
              setBody(e.target.value);
              if (error) setError(undefined);
            }}
            className="text-sm"
          />
        </Field>
        <div>
          <Button type="submit" size="sm" variant="secondary" loading={saving} loadingText="Đang lưu…" className="max-sm:h-11">
            Lưu ghi chú
          </Button>
        </div>
      </form>
      {sorted.length ? (
        <ol className="flex flex-col divide-y divide-line" aria-label="Ghi chú nội bộ, mới nhất trước">
          {sorted.map((n) => (
            <li key={n.id} className="flex gap-3 py-3 first:pt-0">
              <Avatar name={n.author?.name ?? "?"} size="sm" />
              <div className="min-w-0 flex-1">
                <p className="text-sm">
                  <span className="font-semibold text-ink">{n.author?.name ?? "Quản trị viên"}</span>{" "}
                  <span className="num text-ink-soft">· {formatWhen(n.created_at)}</span>
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

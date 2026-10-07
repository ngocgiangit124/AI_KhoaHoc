"use client";

import { useRef, useState } from "react";
import { ApiError } from "@vitaminvui/api-client";
import { Button, Field, TextInput, useToast } from "@vitaminvui/ui/v2";
import { setManualOrder } from "@/lib/courses/api";
import { courseActionError } from "@/lib/courses/errors";

export const MANUAL_ORDER_MAX = 1_000_000;

/** Parse ô thứ tự: rỗng → null (bỏ thứ tự); số nguyên 0..1.000.000; khác → undefined (không hợp lệ). */
export function parseManualOrder(raw: string): number | null | undefined {
  const t = raw.trim();
  if (t === "") return null;
  if (!/^\d{1,7}$/.test(t)) return undefined;
  const n = Number(t);
  return n <= MANUAL_ORDER_MAX ? n : undefined;
}

export interface ManualOrderFieldProps {
  courseId: number;
  value: number | null | undefined;
  onSaved: () => void;
  /** Khóa học đã bị xoá từ nơi khác (404). */
  onGone: () => void;
}

/**
 * "Thứ tự nổi bật" (chỉ staff, `PATCH .../manual-order`). Lưu riêng bằng nút của chính ô này; Enter KHÔNG gửi form cha.
 * Để trống = bỏ thứ tự thủ công.
 */
export function ManualOrderField({ courseId, value, onSaved, onGone }: ManualOrderFieldProps) {
  const toast = useToast();
  const initial = value == null ? "" : String(value);
  const [text, setText] = useState(initial);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const pendingRef = useRef(false);

  async function save() {
    if (pendingRef.current) return;
    const parsed = parseManualOrder(text);
    if (parsed === undefined) {
      setError("Nhập số nguyên từ 0 đến 1.000.000 (để trống nếu không đặt thứ tự).");
      return;
    }
    pendingRef.current = true;
    setPending(true);
    setError(null);
    try {
      await setManualOrder(courseId, parsed);
      toast.show({ tone: "success", title: "Đã lưu thứ tự nổi bật" });
      onSaved();
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && err.errors?.manual_order?.[0]) setError(err.errors.manual_order[0]);
      else {
        setError(courseActionError(err));
        if (err instanceof ApiError && err.status === 404) onGone();
      }
    } finally {
      pendingRef.current = false;
      setPending(false);
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <Field label="Thứ tự nổi bật" hint="Số nhỏ hiện trước khi sắp xếp “Nổi bật”. Để trống = không nổi bật." error={error ?? undefined}>
        <TextInput
          size="sm"
          name="manual_order"
          inputMode="numeric"
          autoComplete="off"
          value={text}
          disabled={pending}
          className="num max-sm:h-11"
          onChange={(e) => {
            setText(e.target.value);
            if (error) setError(null);
          }}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              void save();
            }
          }}
        />
      </Field>
      <Button type="button" size="sm" variant="secondary" className="self-start max-sm:h-11" loading={pending} disabled={text.trim() === initial} onClick={() => void save()}>
        Lưu thứ tự
      </Button>
    </div>
  );
}

"use client";

import { useRef, useState } from "react";
import { Field, IconAlertCircle, IconButton, IconX, Select } from "@vitaminvui/ui/v2";

export interface TeacherOption {
  id: number;
  name: string;
  /** Nhãn phụ (ví dụ "không còn hoạt động"). */
  note?: string;
}

export interface TeacherPickerProps {
  /** Giáo viên đang hoạt động có thể thêm. */
  options: readonly TeacherOption[];
  /** Giáo viên đã chọn (có thể gồm người nay không còn hoạt động; vẫn giữ được khi lưu lại). */
  selected: readonly TeacherOption[];
  onChange: (ids: number[]) => void;
  error?: string;
  disabled?: boolean;
  loading?: boolean;
  loadError?: string | null;
  onRetry?: () => void;
  /** Báo lỗi khi bấm bỏ người cuối (khóa học luôn cần ≥ 1 giáo viên). */
  onLastRemove?: () => void;
}

/**
 * Multi-select giáo viên phụ trách (chỉ staff): thẻ đã chọn + ô "Thêm giáo viên" (select gốc: bàn phím, mobile picker).
 * Bỏ người cuối bị chặn ngay ở đây (BR6: tối thiểu 1 giáo viên); quyền và danh sách hợp lệ thật do API kiểm.
 */
export function TeacherPicker({ options, selected, onChange, error, disabled, loading, loadError, onRetry, onLastRemove }: TeacherPickerProps) {
  const rootRef = useRef<HTMLDivElement>(null);
  const selectRef = useRef<HTMLSelectElement>(null);
  const [announce, setAnnounce] = useState("");
  const chosen = new Set(selected.map((t) => t.id));
  const addable = options.filter((t) => !chosen.has(t.id));

  return (
    <div ref={rootRef} className="flex flex-col gap-3" data-testid="teachers-group" data-invalid={error ? "true" : undefined}>
      <p className="text-sm text-ink-soft">
        <span className="num font-semibold text-ink">{selected.length}</span> giáo viên đã chọn
      </p>
      <p className="sr-only" role="status" aria-live="polite">
        {announce}
      </p>
      {selected.length > 0 ? (
        <ul className="flex flex-wrap gap-2" aria-label="Giáo viên đã chọn">
          {selected.map((t, index) => (
            <li key={t.id} className="inline-flex items-center gap-1 rounded-full bg-primary-soft py-0.5 pl-3 pr-0.5 text-sm font-semibold text-primary">
              <span className="break-words">
                {t.name}
                {t.note ? <span className="ml-1 text-xs font-normal">({t.note})</span> : null}
              </span>
              <IconButton
                size="sm"
                label={`Bỏ ${t.name}`}
                icon={<IconX size={14} />}
                className="size-7 max-sm:size-11"
                data-chip-remove=""
                disabled={disabled}
                onClick={() => {
                  if (selected.length === 1) {
                    onLastRemove?.();
                    return;
                  }
                  onChange(selected.filter((x) => x.id !== t.id).map((x) => x.id));
                  setAnnounce(`Đã bỏ ${t.name}`);
                  // Nút vừa bấm bị gỡ khỏi DOM: đưa focus sang chip kế bên, hết chip thì về ô "Thêm giáo viên".
                  setTimeout(() => {
                    const buttons = rootRef.current?.querySelectorAll<HTMLElement>("[data-chip-remove]");
                    (buttons && buttons.length > 0 ? buttons[Math.min(index, buttons.length - 1)] : selectRef.current)?.focus();
                  }, 0);
                }}
              />
            </li>
          ))}
        </ul>
      ) : null}
      {error ? (
        <p role="alert" className="flex items-start gap-1.5 text-sm font-medium text-danger">
          <IconAlertCircle size={16} className="mt-0.5" />
          <span>{error}</span>
        </p>
      ) : null}
      {loadError ? (
        <div className="text-sm text-danger" role="alert">
          <p>{loadError}</p>
          {onRetry ? (
            <button type="button" onClick={onRetry} className="focus-ring mt-1 min-h-11 rounded font-semibold text-primary underline">
              Thử lại
            </button>
          ) : null}
        </div>
      ) : (
        <Field label="Thêm giáo viên" hint={loading ? "Đang tải danh sách giáo viên…" : "Chỉ hiện tài khoản có vai trò Giáo viên đang hoạt động."}>
          <Select
            ref={selectRef}
            size="sm"
            className="max-sm:h-11"
            value=""
            disabled={disabled || loading}
            onChange={(e) => {
              const id = Number(e.target.value);
              if (id && !chosen.has(id)) {
                onChange([...selected.map((x) => x.id), id]);
                setAnnounce(`Đã thêm ${addable.find((t) => t.id === id)?.name ?? "giáo viên"}`);
              }
            }}
          >
            <option value="">{addable.length === 0 && !loading ? "Không còn giáo viên để thêm" : "Chọn giáo viên…"}</option>
            {addable.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </Select>
        </Field>
      )}
    </div>
  );
}

"use client";

import { useState, type FormEvent } from "react";
import { Alert, Button, Dialog, Field, IconFileText, PasswordInput, useToast } from "@vitaminvui/ui/v2";
import { downloadDataExport, fetchExportStatus } from "@/lib/privacy/api";
import { saveBlob } from "@/lib/privacy/download";
import { classifyExportError, EXPORT_LIMIT_TEXT } from "@/lib/privacy/errors";
import type { DataExportStatus } from "@/lib/privacy/schemas";
import { SectionState } from "./SectionState";
import { useLoad } from "./useLoad";

/**
 * Khối "Tải dữ liệu" (api-contract §2.8.4): `GET` hiện hạn mức, `POST` (mật khẩu) trả file JSON -> blob -> `<a download>`.
 * 429 `DATA_EXPORT_LIMIT`: khoá nút và hiện giờ đặt lại (`errors.resets_at`, giờ VN).
 */
export function DataExportSection() {
  const { state, reload, set } = useLoad<DataExportStatus>(fetchExportStatus);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  return (
    <SectionState state={state} reload={reload}>
      {(status) => {
        const exhausted = status.remaining <= 0;
        return (
          <div className="flex flex-col gap-4">
            <p className="text-base text-ink-soft">
              Tải về một tệp JSON gồm thông tin tài khoản, đồng ý, khóa học, đơn hàng, tiến độ học và kết quả quiz của chính bạn. Tệp có thông tin
              cá nhân nên hãy cất giữ cẩn thận.
            </p>
            {exhausted ? (
              <Alert tone="warning" title="Đã hết lượt tải hôm nay">
                {EXPORT_LIMIT_TEXT(status.limit_per_day, status.resets_at)}
              </Alert>
            ) : (
              <p className="text-sm font-semibold text-ink" data-testid="export-remaining">
                Còn {status.remaining} lượt hôm nay
                <span className="font-normal text-ink-soft"> (tối đa {status.limit_per_day} lượt mỗi ngày)</span>
              </p>
            )}
            <div>
              <Button leadingIcon={<IconFileText size={18} />} disabled={exhausted} onClick={() => setOpen(true)}>
                Tải dữ liệu của tôi
              </Button>
            </div>
            <Dialog
              open={open}
              onClose={() => setOpen(false)}
              dismissible={!busy}
              title="Tải dữ liệu của tôi"
              description="Nhập mật khẩu hiện tại để xác nhận. Mỗi lần tải thành công tính một lượt trong ngày."
            >
              {open ? (
                <ExportForm
                  onBusy={setBusy}
                  onClose={() => setOpen(false)}
                  onDone={() => {
                    setOpen(false);
                    reload();
                  }}
                  onLimit={(resetsAt) => {
                    setOpen(false);
                    set({ ...status, used_today: status.limit_per_day, remaining: 0, resets_at: resetsAt ?? status.resets_at });
                  }}
                />
              ) : null}
            </Dialog>
          </div>
        );
      }}
    </SectionState>
  );
}

function ExportForm({ onBusy, onClose, onDone, onLimit }: { onBusy: (b: boolean) => void; onClose: () => void; onDone: () => void; onLimit: (resetsAt: string | null) => void }) {
  const toast = useToast();
  const [password, setPassword] = useState("");
  const [fieldError, setFieldError] = useState<string | undefined>();
  const [banner, setBanner] = useState<{ message: string; requestId?: string } | null>(null);
  const [pending, setPending] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (pending) return;
    setBanner(null);
    if (!password) {
      setFieldError("Vui lòng nhập mật khẩu hiện tại.");
      document.getElementById("export-password")?.focus();
      return;
    }
    setFieldError(undefined);
    setPending(true);
    onBusy(true);
    try {
      const { blob, filename } = await downloadDataExport(password);
      saveBlob(blob, filename);
      toast.show({ tone: "success", title: "Đã tải dữ liệu", description: `Tệp ${filename} đã được lưu vào máy của bạn.` });
      onBusy(false);
      onDone();
    } catch (err) {
      setPassword("");
      onBusy(false);
      setPending(false);
      const failure = classifyExportError(err);
      if (failure.kind === "limit") onLimit(failure.resetsAt);
      else if (failure.kind === "password") {
        setFieldError(failure.message);
        document.getElementById("export-password")?.focus();
      } else if (failure.kind === "throttled") setBanner({ message: failure.message });
      else setBanner({ message: failure.message, requestId: failure.requestId });
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate aria-busy={pending} className="flex flex-col gap-4">
      {banner ? (
        <Alert tone="danger">
          {banner.message}
          {banner.requestId ? <span className="mt-1 block text-ink-soft">Mã lỗi: {banner.requestId}</span> : null}
        </Alert>
      ) : null}
      <Field id="export-password" label="Mật khẩu hiện tại" required error={fieldError}>
        <PasswordInput autoComplete="current-password" autoFocus value={password} disabled={pending} onChange={(e) => setPassword(e.target.value)} />
      </Field>
      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button type="button" variant="secondary" disabled={pending} onClick={onClose}>
          Huỷ
        </Button>
        <Button type="submit" loading={pending} loadingText="Đang chuẩn bị tệp…">
          Tải về
        </Button>
      </div>
    </form>
  );
}

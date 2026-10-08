"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert, Badge, Button, Field, PasswordInput, TextInput, useToast } from "@vitaminvui/ui/v2";
import { focusFirstError } from "@/lib/focus";
import { fetchParentContact, updateParentContact } from "@/lib/privacy/api";
import { classifyParentContactError } from "@/lib/privacy/errors";
import { PARENT_NOTICE_STATUS_LABEL, formatVnDate, parentNoticeStatus } from "@/lib/privacy/format";
import { buildParentContactBody, type ParentContactErrors } from "@/lib/privacy/parentContact";
import type { ParentContact } from "@/lib/privacy/schemas";
import { SectionState } from "./SectionState";
import { useLoad } from "./useLoad";

const STATUS_TONE = { none: "neutral", enabled: "success", "opted-out": "warning", paused: "neutral" } as const;

/**
 * Khối "Thông tin phụ huynh" (api-contract §2.8.2, ADR-006): xem bản che, giải thích phụ huynh nhận thư gì, sửa/xoá (cần mật khẩu hiện tại).
 * Giá trị đầy đủ không bao giờ có ở FE: ô sửa để trống = giữ nguyên, "Xoá" = gửi `null`.
 */
export function ParentContactSection() {
  const { state, reload, set } = useLoad<ParentContact>(fetchParentContact);
  const [editing, setEditing] = useState(false);

  return (
    <SectionState state={state} reload={reload}>
      {(contact) => (
        <div className="flex flex-col gap-4">
          <ContactSummary contact={contact} />
          <Explain />
          {editing ? (
            <ParentContactForm
              contact={contact}
              onCancel={() => setEditing(false)}
              onSaved={(next) => {
                set(next);
                setEditing(false);
              }}
            />
          ) : (
            <div>
              <Button variant="secondary" onClick={() => setEditing(true)}>
                {contact.has_email || contact.has_phone ? "Sửa thông tin phụ huynh" : "Thêm thông tin phụ huynh"}
              </Button>
            </div>
          )}
        </div>
      )}
    </SectionState>
  );
}

function ContactSummary({ contact }: { contact: ParentContact }) {
  const status = parentNoticeStatus(contact);
  return (
    <div className="flex flex-col gap-3">
      <dl className="grid gap-3 text-base sm:grid-cols-[160px_1fr]">
        <dt className="text-ink-soft">Email phụ huynh</dt>
        <dd className="break-all text-ink" data-testid="parent-email-masked">
          {contact.email_masked ?? "Chưa có"}
        </dd>
        <dt className="text-ink-soft">SĐT phụ huynh</dt>
        <dd className="text-ink" data-testid="parent-phone-masked">
          {contact.phone_masked ?? "Chưa có"}
        </dd>
        <dt className="text-ink-soft">Thông báo</dt>
        <dd className="flex flex-wrap items-center gap-2 text-ink">
          <Badge tone={STATUS_TONE[status]} size="sm">
            {PARENT_NOTICE_STATUS_LABEL[status]}
          </Badge>
          {status === "opted-out" && contact.notices_opted_out_at ? (
            <span className="text-sm text-ink-soft">từ {formatVnDate(contact.notices_opted_out_at)}</span>
          ) : null}
        </dd>
      </dl>
    </div>
  );
}

function Explain() {
  return (
    <div className="rounded-card bg-sunken p-4 text-sm leading-relaxed text-ink-soft">
      <p className="font-semibold text-ink">Phụ huynh nhận thư gì?</p>
      <ul className="mt-1 list-disc pl-5">
        <li>Thư báo khi tài khoản của bạn được xác thực lần đầu và khi bạn thêm hoặc đổi email phụ huynh.</li>
        <li>Thư báo khi bạn thanh toán thành công một khóa học có phí.</li>
      </ul>
      <p className="mt-2">
        Thư không chứa email, số điện thoại hay ngày sinh của bạn, và không cần phụ huynh làm gì thêm. Mỗi thư đều có liên kết để phụ huynh
        huỷ nhận bất cứ lúc nào. Bạn cũng có thể xoá email phụ huynh ở dưới.
      </p>
    </div>
  );
}

function ParentContactForm({ contact, onCancel, onSaved }: { contact: ParentContact; onCancel: () => void; onSaved: (c: ParentContact) => void }) {
  const toast = useToast();
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [removeEmail, setRemoveEmail] = useState(false);
  const [removePhone, setRemovePhone] = useState(false);
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState<ParentContactErrors>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [throttle, setThrottle] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  // Hết 429 thì mở lại nút sau tối đa 60 giây (không biết đúng mốc; server vẫn là nơi chặn).
  useEffect(() => {
    if (!throttle) return;
    const id = setTimeout(() => setThrottle(null), 60_000);
    return () => clearTimeout(id);
  }, [throttle]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (pending || throttle) return;
    setBanner(null);
    const { body, errors: local } = buildParentContactBody({ email, phone, removeEmail, removePhone, password });
    setErrors(local);
    if (!body) {
      focusFirstError([["pc-email", !!local.parent_email], ["pc-phone", !!local.parent_phone], ["pc-password", !!local.current_password]]);
      return;
    }
    setPending(true);
    try {
      const next = await updateParentContact(body);
      toast.show({ tone: "success", title: "Đã lưu thông tin phụ huynh" });
      onSaved(next);
    } catch (err) {
      setPassword(""); // không giữ mật khẩu sau lỗi
      const failure = classifyParentContactError(err);
      if (failure.kind === "fields") {
        setErrors(failure.errors);
        focusFirstError([["pc-email", !!failure.errors.parent_email], ["pc-phone", !!failure.errors.parent_phone], ["pc-password", !!failure.errors.current_password]]);
      } else if (failure.kind === "throttled") {
        setThrottle(failure.message);
      } else {
        setBanner(failure.message);
      }
    } finally {
      setPending(false);
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate aria-busy={pending} aria-label="Sửa thông tin phụ huynh" className="flex flex-col gap-5 border-t border-line pt-5">
      {throttle ? (
        <Alert tone="warning" title="Bạn đã thử quá nhiều lần">
          {throttle}
        </Alert>
      ) : null}
      {banner ? <Alert tone="danger">{banner}</Alert> : null}
      {errors.form ? <Alert tone="danger">{errors.form}</Alert> : null}

      <div className="flex flex-col gap-2">
        <Field
          id="pc-email"
          label="Email phụ huynh"
          error={errors.parent_email}
          hint={removeEmail ? "Email phụ huynh sẽ được xoá khi bạn lưu." : contact.has_email ? "Để trống nếu muốn giữ email hiện tại. Nhập email mới để thay." : "Không bắt buộc."}
        >
          <TextInput
            type="email"
            autoComplete="off"
            inputMode="email"
            placeholder={contact.email_masked ?? "email@phu-huynh.com"}
            value={email}
            disabled={pending || removeEmail}
            onChange={(e) => setEmail(e.target.value)}
          />
        </Field>
        {contact.has_email ? (
          <div>
            <Button type="button" variant="ghost" size="md" disabled={pending} onClick={() => { setRemoveEmail((v) => !v); setEmail(""); }}>
              {removeEmail ? "Hoàn tác xoá email" : "Xoá email phụ huynh"}
            </Button>
          </div>
        ) : null}
      </div>

      <div className="flex flex-col gap-2">
        <Field
          id="pc-phone"
          label="Số điện thoại phụ huynh"
          error={errors.parent_phone}
          hint={removePhone ? "Số điện thoại phụ huynh sẽ được xoá khi bạn lưu." : contact.has_phone ? "Để trống nếu muốn giữ số hiện tại. Nhập số mới để thay." : "Không bắt buộc."}
        >
          <TextInput
            type="tel"
            inputMode="tel"
            autoComplete="off"
            placeholder={contact.phone_masked ?? "09xxxxxxxx"}
            value={phone}
            disabled={pending || removePhone}
            onChange={(e) => setPhone(e.target.value)}
          />
        </Field>
        {contact.has_phone ? (
          <div>
            <Button type="button" variant="ghost" size="md" disabled={pending} onClick={() => { setRemovePhone((v) => !v); setPhone(""); }}>
              {removePhone ? "Hoàn tác xoá số điện thoại" : "Xoá số điện thoại phụ huynh"}
            </Button>
          </div>
        ) : null}
      </div>

      <Field id="pc-password" label="Mật khẩu hiện tại" required error={errors.current_password} hint="Nhập mật khẩu hiện tại để xác nhận thay đổi.">
        <PasswordInput autoComplete="current-password" value={password} disabled={pending} onChange={(e) => setPassword(e.target.value)} />
      </Field>

      <div className="flex flex-col-reverse gap-2 sm:flex-row">
        <Button type="button" variant="secondary" disabled={pending} onClick={onCancel}>
          Huỷ
        </Button>
        <Button type="submit" loading={pending} loadingText="Đang lưu…" disabled={throttle !== null}>
          Lưu thay đổi
        </Button>
      </div>
    </form>
  );
}

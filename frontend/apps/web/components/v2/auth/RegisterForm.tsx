"use client";

import Link from "next/link";
import { useRef, useState, type FormEvent } from "react";
import { Alert, Button, Checkbox, Field, IconShieldCheck, PasswordInput, Select, TextInput, cx, useToast } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

type Errors = Partial<Record<"name" | "date_of_birth" | "email" | "phone" | "grade_level" | "password" | "password_confirmation" | "parent" | "consent", string>>;

const LABELS: Record<keyof Errors, string> = {
  name: "Họ và tên",
  date_of_birth: "Ngày sinh",
  email: "Email",
  phone: "Số điện thoại",
  grade_level: "Lớp đang học",
  password: "Mật khẩu",
  password_confirmation: "Xác nhận mật khẩu",
  parent: "Liên hệ phụ huynh",
  consent: "Đồng ý điều khoản",
};

function ageOn(dob: string, today = new Date()): number | null {
  const d = new Date(dob);
  if (Number.isNaN(d.getTime())) return null;
  let age = today.getFullYear() - d.getFullYear();
  const m = today.getMonth() - d.getMonth();
  if (m < 0 || (m === 0 && today.getDate() < d.getDate())) age--;
  return age;
}

/**
 * Form đăng ký học sinh (POST /auth/register, US-001 + US-017).
 * - Dưới `parent_consent_age` (18): hiện thêm liên hệ phụ huynh (≥ 1 trong 2).
 * - 2 checkbox đồng ý tách riêng, không tick sẵn; ghi rõ `policy_version`.
 * - Lỗi hiện ngay dưới ô + hộp tóm tắt lỗi đầu form (có liên kết tới từng ô), focus vào hộp tóm tắt.
 * - Turnstile: khi `captcha_site_key` null (local) hiện ghi chú thay widget.
 * TODO(dev): nối API, reset Turnstile sau mọi lỗi 422/CAPTCHA_FAILED; hiện `errors.password[0]` khi mật khẩu phổ biến.
 */
export function RegisterForm({ parentConsentAge, policyVersion, captchaConfigured, referralEnabled }: { parentConsentAge: number; policyVersion: string; captchaConfigured: boolean; referralEnabled: boolean }) {
  const toast = useToast();
  const [dob, setDob] = useState("");
  const [errors, setErrors] = useState<Errors>({});
  const [loading, setLoading] = useState(false);
  const summaryRef = useRef<HTMLDivElement>(null);
  const age = dob ? ageOn(dob) : null;
  const minor = age !== null && age < parentConsentAge;

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const v = (k: string) => String(f.get(k) ?? "").trim();
    const next: Errors = {};
    if (!v("name")) next.name = "Vui lòng nhập họ và tên.";
    if (!v("date_of_birth")) next.date_of_birth = "Vui lòng chọn ngày sinh.";
    if (!/^\S+@\S+\.\S+$/.test(v("email"))) next.email = "Email chưa đúng định dạng, ví dụ: ten@gmail.com.";
    if (!/^(0|\+?84)\d{9}$/.test(v("phone").replace(/\s/g, ""))) next.phone = "Số điện thoại gồm 10 chữ số, bắt đầu bằng 0.";
    if (!v("grade_level")) next.grade_level = "Vui lòng chọn lớp đang học.";
    if (v("password").length < 8) next.password = "Mật khẩu cần tối thiểu 8 ký tự.";
    if (v("password_confirmation") !== v("password") || !v("password_confirmation")) next.password_confirmation = "Xác nhận mật khẩu không khớp.";
    if (minor && !v("parent_phone") && !v("parent_email")) next.parent = "Vui lòng nhập ít nhất số điện thoại hoặc email phụ huynh.";
    if (!f.get("accept_terms") || !f.get("accept_privacy")) next.consent = "Vui lòng đồng ý với cả điều khoản sử dụng và chính sách xử lý dữ liệu cá nhân.";
    setErrors(next);
    if (Object.keys(next).length) {
      requestAnimationFrame(() => summaryRef.current?.focus());
      return;
    }
    setLoading(true);
    setTimeout(() => {
      setLoading(false);
      toast.show({ tone: "info", title: "Bản xem trước", description: "Chưa nối API đăng ký." });
    }, 1000);
  }

  const errorKeys = Object.keys(errors) as Array<keyof Errors>;
  return (
    <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
      {errorKeys.length ? (
        <div ref={summaryRef} tabIndex={-1} className="focus-ring rounded-card">
          <Alert tone="danger" title={`Còn ${errorKeys.length} mục cần sửa`}>
            <ul className="list-disc pl-5">
              {errorKeys.map((k) => (
                <li key={k}>
                  <a href={`#reg-${k}`} className="font-semibold underline underline-offset-2">
                    {LABELS[k]}
                  </a>
                  : {errors[k]}
                </li>
              ))}
            </ul>
          </Alert>
        </div>
      ) : null}

      <Field id="reg-name" label="Họ và tên" required error={errors.name}>
        <TextInput name="name" autoComplete="name" maxLength={150} />
      </Field>
      <div className="grid gap-5 sm:grid-cols-2">
        <Field id="reg-date_of_birth" label="Ngày sinh" required error={errors.date_of_birth}>
          <TextInput name="date_of_birth" type="date" value={dob} onChange={(e) => setDob(e.target.value)} max="2020-12-31" />
        </Field>
        <Field id="reg-grade_level" label="Lớp đang học" required error={errors.grade_level}>
          <Select name="grade_level" defaultValue="">
            <option value="" disabled>
              Chọn lớp
            </option>
            {[6, 7, 8, 9, 10, 11, 12].map((g) => (
              <option key={g} value={g}>
                Lớp {g}
              </option>
            ))}
          </Select>
        </Field>
      </div>

      {/* Mở thêm khi dưới 18 tuổi: không tải lại trang, chuyển động chỉ khi người dùng cho phép. */}
      {minor ? (
        <fieldset className="flex flex-col gap-4 rounded-card border border-info/30 bg-info-soft p-4 motion-safe:animate-rise-in">
          <legend className="sr-only">Liên hệ phụ huynh</legend>
          <p className="flex items-start gap-2 text-sm text-ink">
            <IconShieldCheck size={18} className="mt-0.5 shrink-0 text-info" />
            Vì bạn dưới {parentConsentAge} tuổi, hãy nhập ít nhất 1 cách liên hệ phụ huynh. Chúng tôi sẽ gửi email để phụ huynh xác nhận đồng ý.
          </p>
          <Field id="reg-parent" label="Số điện thoại phụ huynh" error={errors.parent}>
            <TextInput name="parent_phone" type="tel" inputMode="tel" autoComplete="off" />
          </Field>
          <Field label="Email phụ huynh">
            <TextInput name="parent_email" type="email" autoComplete="off" />
          </Field>
        </fieldset>
      ) : null}

      <Field id="reg-email" label="Email" required error={errors.email} hint="Mã xác thực sẽ gửi tới email này.">
        <TextInput name="email" type="email" autoComplete="email" />
      </Field>
      <Field id="reg-phone" label="Số điện thoại" required error={errors.phone}>
        <TextInput name="phone" type="tel" inputMode="tel" autoComplete="tel" />
      </Field>
      <Field id="reg-password" label="Mật khẩu" required error={errors.password} hint="Tối thiểu 8 ký tự. Tránh mật khẩu dễ đoán như 12345678.">
        <PasswordInput name="password" autoComplete="new-password" maxLength={128} />
      </Field>
      <Field id="reg-password_confirmation" label="Xác nhận mật khẩu" required error={errors.password_confirmation}>
        <PasswordInput name="password_confirmation" autoComplete="new-password" maxLength={128} />
      </Field>
      {referralEnabled ? (
        <Field label="Mã giới thiệu (không bắt buộc)" hint="Nếu có, nhập mã của người giới thiệu bạn.">
          <TextInput name="referral_code" autoComplete="off" />
        </Field>
      ) : null}

      <fieldset id="reg-consent" className={cx("flex flex-col rounded-card", errors.consent && "border border-danger/40 px-3 py-1")}>
        <legend className="sr-only">Đồng ý điều khoản</legend>
        <Checkbox
          id="reg-accept-terms"
          name="accept_terms"
          label={
            <>
              Tôi đã đọc và đồng ý với{" "}
              <Link href={routes.terms} target="_blank" className="font-semibold text-primary underline underline-offset-2">
                Điều khoản sử dụng
              </Link>
            </>
          }
        />
        <Checkbox
          id="reg-accept-privacy"
          name="accept_privacy"
          label={
            <>
              Tôi đã đọc và đồng ý với{" "}
              <Link href={routes.privacy} target="_blank" className="font-semibold text-primary underline underline-offset-2">
                Chính sách xử lý dữ liệu cá nhân
              </Link>
            </>
          }
        />
        {errors.consent ? <p className="pb-2 text-sm font-medium text-danger">{errors.consent}</p> : null}
        <p className="pb-1 text-sm text-ink-soft">Phiên bản chính sách: {policyVersion}</p>
      </fieldset>

      {captchaConfigured ? (
        <div className="h-16 rounded-control border border-line bg-sunken" aria-label="Xác minh chống spam" />
      ) : (
        <p className="rounded-control border border-dashed border-line-strong px-3 py-2 text-sm text-ink-soft">
          Captcha Turnstile hiện ở đây (môi trường local chưa cấu hình nên ẩn).
        </p>
      )}

      <Button type="submit" size="lg" block loading={loading} loadingText="Đang tạo tài khoản…">
        Tạo tài khoản
      </Button>
      <p className="text-center text-base text-ink-soft">
        Đã có tài khoản?{" "}
        <Link href={routes.login} className="focus-ring rounded font-semibold text-primary hover:underline">
          Đăng nhập
        </Link>
      </p>
    </form>
  );
}

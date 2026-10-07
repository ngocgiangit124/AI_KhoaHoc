"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm, useWatch, type FieldErrors } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { getDeviceId } from "@vitaminvui/api-client";
import { TurnstileWidget } from "@vitaminvui/ui";
import { Alert, Button, Field, IconShieldCheck, PasswordInput, Select, TextInput } from "@vitaminvui/ui/v2";
import { buildRegisterPayload, registerStudent } from "@/lib/auth/api";
import { markOtpSent } from "@/lib/auth/flash";
import { isBelowConsentAge } from "@/lib/auth/age";
import { REGISTER_FIELD_MAP, classifyRegisterError } from "@/lib/auth/errors";
import { createRegisterSchema, CONSENT_ERROR_MESSAGE, type RegisterFormValues } from "@/lib/auth/schemas";
import { routes } from "@/lib/routes";
import { ConsentCheckboxGroup } from "./ConsentCheckboxGroup";

export interface RegisterFormProps {
  grades: readonly number[];
  parentConsentAge: number;
  referralEnabled: boolean;
  policyVersion: string;
  /** `null` khi chưa cấu hình Turnstile (local) — bỏ qua widget, không gửi `captcha_token`. */
  captchaSiteKey: string | null;
}

const DEFAULTS: RegisterFormValues = {
  name: "",
  date_of_birth: "",
  email: "",
  phone: "",
  grade_level: "",
  password: "",
  password_confirmation: "",
  parent_phone: "",
  parent_email: "",
  referral_code: "",
  accept_terms: false,
  accept_privacy: false,
};

/** Mục trong hộp tóm tắt lỗi: thứ tự theo vị trí trên form, mỗi mục liên kết tới ô của nó. */
type SummaryKey =
  | "name"
  | "date_of_birth"
  | "parent"
  | "email"
  | "phone"
  | "grade_level"
  | "password"
  | "password_confirmation"
  | "referral_code"
  | "consent";

const SUMMARY_ID = "reg-summary";

const SUMMARY: Array<{ key: SummaryKey; label: string; anchor: string }> = [
  { key: "name", label: "Họ và tên", anchor: "reg-name" },
  { key: "date_of_birth", label: "Ngày sinh", anchor: "reg-date_of_birth" },
  { key: "parent", label: "Liên hệ phụ huynh", anchor: "reg-parent_phone" },
  { key: "email", label: "Email", anchor: "reg-email" },
  { key: "phone", label: "Số điện thoại", anchor: "reg-phone" },
  { key: "grade_level", label: "Lớp đang học", anchor: "reg-grade_level" },
  { key: "password", label: "Mật khẩu", anchor: "reg-password" },
  { key: "password_confirmation", label: "Xác nhận mật khẩu", anchor: "reg-password_confirmation" },
  { key: "referral_code", label: "Mã giới thiệu", anchor: "reg-referral_code" },
  { key: "consent", label: "Đồng ý điều khoản", anchor: "reg-accept-terms" },
];

function summaryItems(errors: FieldErrors<RegisterFormValues>) {
  const message = (key: SummaryKey): string | undefined => {
    if (key === "parent") return errors.parent_phone?.message ?? errors.parent_email?.message;
    if (key === "consent") return errors.accept_terms || errors.accept_privacy ? CONSENT_ERROR_MESSAGE : undefined;
    return errors[key]?.message;
  };
  return SUMMARY.flatMap((item) => {
    const m = message(item.key);
    return m ? [{ ...item, message: m }] : [];
  });
}

/** Form `/dang-ky` (US-001 §2.1) trên design v2. Validate client để tiện, Laravel mới là nơi quyết định. */
export function RegisterForm({
  grades,
  parentConsentAge,
  referralEnabled,
  policyVersion,
  captchaSiteKey,
}: RegisterFormProps) {
  const router = useRouter();
  const schema = useMemo(() => createRegisterSchema({ grades, parentConsentAge }), [grades, parentConsentAge]);

  const {
    register,
    handleSubmit,
    setError,
    resetField,
    control,
    formState: { errors, isSubmitting },
  } = useForm<RegisterFormValues>({ resolver: zodResolver(schema), defaultValues: DEFAULTS });

  const [banner, setBanner] = useState<string | null>(null);
  const [captchaMessage, setCaptchaMessage] = useState<string | null>(null);
  const [captchaToken, setCaptchaToken] = useState<string | null>(null);
  const [captchaKey, setCaptchaKey] = useState(0);
  const [done, setDone] = useState(false);
  // Server báo lỗi parent_* dù client tính là đủ tuổi (config cache/lệch ngày) → ép hiện khối phụ huynh.
  const [forceParent, setForceParent] = useState(false);

  const dateOfBirth = useWatch({ control, name: "date_of_birth" });
  const isMinor = isBelowConsentAge(dateOfBirth, parentConsentAge) || forceParent;
  // Site key rỗng ("") hoặc null = chưa cấu hình Turnstile → không bật captcha.
  const captchaEnabled = typeof captchaSiteKey === "string" && captchaSiteKey.trim() !== "";
  const captchaPending = captchaEnabled && captchaToken === null;
  const locked = isSubmitting || done;

  function resetCaptcha() {
    setCaptchaToken(null);
    setCaptchaKey((k) => k + 1);
  }

  // Mỗi lần tăng, đưa focus vào hộp tóm tắt lỗi (design-system-v2 §12.8). Chờ khung hình sau để hộp đã render.
  const [summaryFocus, setSummaryFocus] = useState(0);
  useEffect(() => {
    if (summaryFocus === 0) return;
    const id = requestAnimationFrame(() => document.getElementById(SUMMARY_ID)?.focus());
    return () => cancelAnimationFrame(id);
  }, [summaryFocus]);
  const focusSummary = () => setSummaryFocus((n) => n + 1);

  const onSubmit = handleSubmit(
    async (values) => {
      setBanner(null);
      setCaptchaMessage(null);

      try {
        await registerStudent(
          buildRegisterPayload({
            values,
            captchaToken,
            parentConsentAge,
            referralEnabled,
            deviceId: getDeviceId(),
            forceParent,
          }),
        );
        markOtpSent();
        setDone(true);
        router.replace("/");
        router.refresh();
        return;
      } catch (err) {
        const failure = classifyRegisterError(err);
        if (failure.kind === "fields") {
          let shown = 0;
          const unshown: string[] = [];
          for (const [apiField, message] of Object.entries(failure.errors)) {
            const field = REGISTER_FIELD_MAP[apiField] as keyof RegisterFormValues | undefined;
            if (!field) {
              unshown.push(message);
            } else if (field === "referral_code" && !referralEnabled) {
              unshown.push(message); // ô không hiển thị → đưa vào banner
            } else {
              if (field === "parent_phone" || field === "parent_email") setForceParent(true);
              setError(field, { type: "server", message });
              shown += 1;
            }
          }
          if (unshown.length > 0) setBanner(unshown[0] ?? null);
          if (shown > 0) focusSummary();
        } else if (failure.kind === "captcha") {
          setCaptchaMessage(failure.message);
        } else {
          setBanner(failure.message);
        }
      }

      // Token Turnstile dùng 1 lần; mật khẩu không giữ lại (design US-001 §2.1 "Hành động").
      resetCaptcha();
      // `keepError`: lỗi server của chính ô mật khẩu (vd. mật khẩu phổ biến) vẫn phải hiện dưới ô.
      resetField("password", { defaultValue: "", keepError: true });
      resetField("password_confirmation", { defaultValue: "", keepError: true });
    },
    focusSummary,
  );

  const items = summaryItems(errors);
  const consentError = errors.accept_terms || errors.accept_privacy ? CONSENT_ERROR_MESSAGE : undefined;

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5" aria-busy={locked}>
      {banner ? <Alert tone="danger">{banner}</Alert> : null}

      {items.length > 0 ? (
        <div id={SUMMARY_ID} tabIndex={-1} className="focus-ring rounded-card">
          <Alert tone="danger" title={`Còn ${items.length} mục cần sửa`}>
            <ul className="list-disc pl-5">
              {items.map((item) => (
                <li key={item.key}>
                  <a href={`#${item.anchor}`} className="font-semibold underline underline-offset-2">
                    {item.label}
                  </a>
                  : {item.message}
                </li>
              ))}
            </ul>
          </Alert>
        </div>
      ) : null}

      <fieldset disabled={locked} className="flex min-w-0 flex-col gap-5">
        <Field id="reg-name" label="Họ và tên" required error={errors.name?.message}>
          <TextInput autoComplete="name" maxLength={150} {...register("name")} />
        </Field>

        <div className="grid gap-5 sm:grid-cols-2">
          <Field id="reg-date_of_birth" label="Ngày sinh" required error={errors.date_of_birth?.message}>
            <TextInput type="date" autoComplete="bday" {...register("date_of_birth")} />
          </Field>
          <Field id="reg-grade_level" label="Lớp đang học" required error={errors.grade_level?.message}>
            <Select {...register("grade_level")}>
              <option value="">Chọn lớp</option>
              {grades.map((grade) => (
                <option key={grade} value={grade}>
                  Lớp {grade}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        {/* Mở thêm khi dưới ngưỡng tuổi: không tải lại trang, chuyển động chỉ khi người dùng cho phép. */}
        {isMinor ? (
          <div
            role="group"
            aria-label="Liên hệ phụ huynh"
            data-testid="parent-fields"
            className="flex flex-col gap-4 rounded-card border border-info/30 bg-info-soft p-4 motion-safe:animate-rise-in"
          >
            <p className="flex items-start gap-2 text-sm text-ink">
              <IconShieldCheck size={18} className="mt-0.5 shrink-0 text-info" />
              Bắt buộc nhập ít nhất 1 trong 2 thông tin liên hệ phụ huynh vì bạn dưới {parentConsentAge} tuổi. Chúng tôi sẽ gửi
              email để phụ huynh xác nhận đồng ý.
            </p>
            <Field id="reg-parent_phone" label="Số điện thoại phụ huynh" error={errors.parent_phone?.message}>
              <TextInput type="tel" inputMode="tel" autoComplete="off" {...register("parent_phone")} />
            </Field>
            <Field id="reg-parent_email" label="Email phụ huynh" error={errors.parent_email?.message}>
              <TextInput type="email" autoComplete="off" {...register("parent_email")} />
            </Field>
          </div>
        ) : null}

        <Field id="reg-email" label="Email" required error={errors.email?.message} hint="Mã xác thực sẽ gửi tới email này.">
          <TextInput type="email" autoComplete="email" {...register("email")} />
        </Field>
        <Field id="reg-phone" label="Số điện thoại" required error={errors.phone?.message}>
          <TextInput type="tel" inputMode="tel" autoComplete="tel" {...register("phone")} />
        </Field>
        <Field
          id="reg-password"
          label="Mật khẩu"
          required
          error={errors.password?.message}
          hint="Tối thiểu 8 ký tự. Tránh mật khẩu dễ đoán như 12345678."
        >
          <PasswordInput autoComplete="new-password" maxLength={128} {...register("password")} />
        </Field>
        <Field id="reg-password_confirmation" label="Xác nhận mật khẩu" required error={errors.password_confirmation?.message}>
          <PasswordInput autoComplete="new-password" maxLength={128} {...register("password_confirmation")} />
        </Field>

        {referralEnabled ? (
          <Field
            id="reg-referral_code"
            label="Mã giới thiệu (không bắt buộc)"
            hint="Nếu có, nhập mã của người giới thiệu bạn."
            error={errors.referral_code?.message}
          >
            <TextInput autoComplete="off" {...register("referral_code")} />
          </Field>
        ) : null}

        <ConsentCheckboxGroup register={register} policyVersion={policyVersion} error={consentError} />
      </fieldset>

      {captchaEnabled && captchaSiteKey ? (
        <div className="flex flex-col gap-2">
          {captchaMessage ? <Alert tone="danger">{captchaMessage}</Alert> : null}
          <TurnstileWidget
            key={captchaKey}
            siteKey={captchaSiteKey}
            onToken={setCaptchaToken}
            onError={() => setCaptchaMessage("Xác minh chống spam thất bại, vui lòng thử lại")}
          />
          {captchaPending ? <p className="text-sm text-ink-soft">Vui lòng hoàn tất xác minh chống spam để tạo tài khoản.</p> : null}
        </div>
      ) : null}

      <Button type="submit" size="lg" block loading={locked} loadingText="Đang tạo tài khoản…" disabled={captchaPending}>
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

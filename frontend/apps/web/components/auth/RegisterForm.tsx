"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { getDeviceId } from "@vitaminvui/api-client";
import { Alert, Button, FormField, PasswordInput, Select, TextInput, TurnstileWidget } from "@vitaminvui/ui";
import { buildRegisterPayload, registerStudent } from "@/lib/auth/api";
import { isBelowConsentAge } from "@/lib/auth/age";
import { REGISTER_FIELD_MAP, REGISTER_FIELD_ORDER, classifyRegisterError } from "@/lib/auth/errors";
import { createRegisterSchema, CONSENT_ERROR_MESSAGE, type RegisterFormValues } from "@/lib/auth/schemas";
import { flashForStatus, setRegisterFlash } from "@/lib/auth/flash";
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

/** Form `/dang-ky` (US-001 §2.1). Validate client để tiện, Laravel mới là nơi quyết định. */
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
    setFocus,
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

  const onSubmit = handleSubmit(async (values) => {
    setBanner(null);
    setCaptchaMessage(null);

    try {
      const user = await registerStudent(
        buildRegisterPayload({
          values,
          captchaToken,
          parentConsentAge,
          referralEnabled,
          deviceId: getDeviceId(),
          forceParent,
        }),
      );
      setDone(true);
      setRegisterFlash(flashForStatus(user?.parent_consent_status));
      router.replace("/");
      router.refresh();
      return;
    } catch (err) {
      const failure = classifyRegisterError(err);
      if (failure.kind === "fields") {
        const shown: string[] = [];
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
            shown.push(field);
          }
        }
        if (unshown.length > 0) setBanner(unshown[0] ?? null);
        const first = REGISTER_FIELD_ORDER.find((f) => shown.includes(f));
        // Chờ khối phụ huynh (nếu vừa ép hiện) render xong rồi mới focus.
        if (first) setTimeout(() => setFocus(first), 0);
      } else if (failure.kind === "captcha") {
        setCaptchaMessage(failure.message);
      } else {
        setBanner(failure.message);
      }
    }

    // Token Turnstile dùng 1 lần; mật khẩu không giữ lại (design US-001 §2.1 "Hành động").
    resetCaptcha();
    resetField("password", { defaultValue: "" });
    resetField("password_confirmation", { defaultValue: "" });
  });

  const consentError = errors.accept_terms || errors.accept_privacy ? CONSENT_ERROR_MESSAGE : undefined;

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4" aria-busy={locked}>
      {banner ? <Alert variant="danger">{banner}</Alert> : null}

      <fieldset disabled={locked} className="space-y-4">
        <FormField label="Họ và tên" required error={errors.name?.message}>
          <TextInput autoComplete="name" {...register("name")} />
        </FormField>

        <FormField label="Ngày sinh" required error={errors.date_of_birth?.message}>
          <TextInput type="date" autoComplete="bday" {...register("date_of_birth")} />
        </FormField>

        {isMinor ? (
          <div className="space-y-4 rounded-lg border border-indigo-100 bg-indigo-50 p-4" data-testid="parent-fields">
            <p className="text-sm text-gray-800">
              Bắt buộc nhập ít nhất 1 trong 2 thông tin liên hệ phụ huynh vì bạn dưới {parentConsentAge} tuổi.
            </p>
            <FormField label="Số điện thoại phụ huynh" error={errors.parent_phone?.message}>
              <TextInput type="tel" inputMode="tel" autoComplete="off" {...register("parent_phone")} />
            </FormField>
            <FormField label="Email phụ huynh" error={errors.parent_email?.message}>
              <TextInput type="email" autoComplete="off" {...register("parent_email")} />
            </FormField>
          </div>
        ) : null}

        <FormField label="Email" required error={errors.email?.message}>
          <TextInput type="email" autoComplete="email" {...register("email")} />
        </FormField>

        <FormField label="Số điện thoại" required error={errors.phone?.message}>
          <TextInput type="tel" inputMode="tel" autoComplete="tel" {...register("phone")} />
        </FormField>

        <FormField label="Lớp đang học" required error={errors.grade_level?.message}>
          <Select {...register("grade_level")}>
            <option value="">Chọn lớp</option>
            {grades.map((grade) => (
              <option key={grade} value={grade}>
                Lớp {grade}
              </option>
            ))}
          </Select>
        </FormField>

        <FormField label="Mật khẩu" required hint="Tối thiểu 8 ký tự" error={errors.password?.message}>
          <PasswordInput autoComplete="new-password" {...register("password")} />
        </FormField>

        <FormField label="Xác nhận mật khẩu" required error={errors.password_confirmation?.message}>
          <PasswordInput autoComplete="new-password" {...register("password_confirmation")} />
        </FormField>

        {referralEnabled ? (
          <FormField
            label="Mã giới thiệu"
            hint="Nếu có, nhập mã của người giới thiệu bạn"
            error={errors.referral_code?.message}
          >
            <TextInput autoComplete="off" {...register("referral_code")} />
          </FormField>
        ) : null}

        <ConsentCheckboxGroup register={register} policyVersion={policyVersion} error={consentError} />
      </fieldset>

      {captchaEnabled && captchaSiteKey ? (
        <div className="space-y-2">
          {captchaMessage ? <Alert variant="danger">{captchaMessage}</Alert> : null}
          <TurnstileWidget
            key={captchaKey}
            siteKey={captchaSiteKey}
            onToken={setCaptchaToken}
            onError={() => setCaptchaMessage("Xác minh chống spam thất bại, vui lòng thử lại")}
          />
          {captchaPending ? (
            <p className="text-xs text-gray-600">Vui lòng hoàn tất xác minh chống spam để tạo tài khoản.</p>
          ) : null}
        </div>
      ) : null}

      <Button type="submit" size="lg" className="w-full" loading={locked} disabled={captchaPending}>
        Tạo tài khoản
      </Button>

      <p className="text-center text-sm text-gray-700">
        Đã có tài khoản?{" "}
        <Link href="/dang-nhap" className="font-medium text-indigo-700 underline hover:text-indigo-800">
          Đăng nhập
        </Link>
      </p>
    </form>
  );
}

"use client";

import { useRouter } from "next/navigation";
import { useMemo, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import {
  Alert,
  Button,
  PasswordInput,
  Select,
  TextInput,
  TurnstileWidget,
  useToast,
} from "@vitaminvui/ui";
import { ApiError, NetworkError, getDeviceId } from "@vitaminvui/api-client";
import { authFetch } from "@/lib/api";
import { calculateAgeYears } from "@/lib/age";
import { applyApiErrorToForm } from "@/lib/auth/mapApiError";
import { notifyAuthChanged } from "@/lib/auth/useCurrentUser";
import { parseAuthUser } from "@/lib/types/auth";
import { buildRegisterSchema, type RegisterFormValues } from "@/lib/validation/registerSchema";
import { ConsentCheckboxGroup } from "./ConsentCheckboxGroup";

/**
 * Đối chiếu với `backend/app/Services/Auth/Captcha/FakeCaptchaVerifier.php` (chỉ bind ở
 * local/testing — production cấm `fake` qua boot guard): `verify()` chấp nhận MỌI chuỗi
 * không rỗng, trừ hằng số quy ước `FakeCaptchaVerifier::INVALID_TOKEN` ("test-invalid-captcha",
 * dùng trong test Pest để mô phỏng captcha sai). Chuỗi cố định dưới đây khác rỗng và khác
 * giá trị đó nên được `FakeCaptchaVerifier` chấp nhận ở local (`captcha_site_key = null`).
 */
const LOCAL_FAKE_CAPTCHA_TOKEN = "local-dev-fake-captcha-token";

const KNOWN_FIELDS = [
  "name",
  "date_of_birth",
  "parent_phone",
  "parent_email",
  "email",
  "phone",
  "grade_level",
  "password",
  "password_confirmation",
  "referral_code",
  "accept_terms",
  "accept_privacy",
  "captcha_token",
] as const;

export interface RegisterFormConfig {
  grades: number[];
  captchaSiteKey: string | null;
  parentConsentAge: number;
  referralCodeEnabled: boolean;
  policyVersion: string;
}

export interface RegisterFormProps {
  config: RegisterFormConfig;
  /** Nonce CSP của trang hiện tại — cần cho `<TurnstileWidget>` (ADR-004 §2.6). */
  nonce: string | null;
}

interface Banner {
  message: string;
  variant: "danger" | "warning";
}

export function RegisterForm({ config, nonce }: RegisterFormProps) {
  const router = useRouter();
  const toast = useToast();
  const [banner, setBanner] = useState<Banner | null>(null);
  // Đổi key để buộc TurnstileWidget remount (yêu cầu xác minh lại) sau CAPTCHA_FAILED —
  // token Turnstile chỉ dùng được 1 lần (US-001 §2.1 "hành động").
  const [turnstileResetKey, setTurnstileResetKey] = useState(0);

  const schema = useMemo(
    () => buildRegisterSchema({ parentConsentAge: config.parentConsentAge }),
    [config.parentConsentAge],
  );

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RegisterFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: "",
      date_of_birth: "",
      parent_phone: "",
      parent_email: "",
      email: "",
      phone: "",
      grade_level: "",
      password: "",
      password_confirmation: "",
      referral_code: "",
      accept_terms: false,
      accept_privacy: false,
      captcha_token: config.captchaSiteKey ? "" : LOCAL_FAKE_CAPTCHA_TOKEN,
    },
  });

  const dateOfBirth = watch("date_of_birth");
  const acceptTerms = watch("accept_terms");
  const acceptPrivacy = watch("accept_privacy");
  const captchaToken = watch("captcha_token");

  const age = dateOfBirth ? calculateAgeYears(dateOfBirth) : null;
  const isMinor = age !== null && age < config.parentConsentAge;

  const gradeOptions = useMemo(
    () => config.grades.map((g) => ({ value: String(g), label: `Lớp ${g}` })),
    [config.grades],
  );

  const canSubmit = acceptTerms && acceptPrivacy && Boolean(captchaToken) && !isSubmitting;

  async function onSubmit(values: RegisterFormValues) {
    setBanner(null);

    const payload: Record<string, unknown> = {
      name: values.name,
      date_of_birth: values.date_of_birth,
      email: values.email,
      phone: values.phone,
      grade_level: Number(values.grade_level),
      password: values.password,
      password_confirmation: values.password_confirmation,
      accept_terms: values.accept_terms,
      accept_privacy: values.accept_privacy,
      captcha_token: values.captcha_token,
      device_id: getDeviceId(),
    };
    if (values.parent_phone) payload.parent_phone = values.parent_phone;
    if (values.parent_email) payload.parent_email = values.parent_email;
    if (config.referralCodeEnabled && values.referral_code) {
      payload.referral_code = values.referral_code;
    }

    try {
      const raw = await authFetch<unknown>("/api/v1/auth/register", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      parseAuthUser(raw);

      toast.show(
        "info",
        isMinor
          ? "Vui lòng xác thực tài khoản. Chúng tôi cũng đã gửi email xác nhận tới phụ huynh của bạn — bạn cần cả 2 bước hoàn tất mới mua được khóa học."
          : "Vui lòng xác thực tài khoản để có thể mua khóa học.",
      );
      notifyAuthChanged();
      // TODO(T04/FW1 phần OTP): điều hướng thẳng /xac-thuc-otp khi màn đó được làm; hiện
      // tại chỉ có trang chủ nên tạm chuyển về "/" kèm banner nhắc xác thực (AC1).
      router.push("/");
      return;
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.code === "CAPTCHA_FAILED") {
          setValue("captcha_token", config.captchaSiteKey ? "" : LOCAL_FAKE_CAPTCHA_TOKEN);
          setTurnstileResetKey((k) => k + 1);
        }
        const result = applyApiErrorToForm<RegisterFormValues>(err, setError, KNOWN_FIELDS);
        setBanner(result.bannerMessage ? { message: result.bannerMessage, variant: result.bannerVariant } : null);
        return;
      }
      if (err instanceof NetworkError) {
        setBanner({ message: err.message, variant: "danger" });
        return;
      }
      setBanner({ message: "Đã có lỗi xảy ra, vui lòng thử lại sau.", variant: "danger" });
    }
  }

  return (
    <form className="space-y-4" onSubmit={handleSubmit(onSubmit)} noValidate>
      {banner ? (
        <Alert variant={banner.variant}>{banner.message}</Alert>
      ) : null}

      <TextInput label="Họ và tên" required error={errors.name?.message} {...register("name")} />

      <TextInput
        label="Ngày sinh"
        type="date"
        required
        error={errors.date_of_birth?.message}
        {...register("date_of_birth")}
      />

      {isMinor ? (
        <div className="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
          <p className="text-xs text-amber-800">
            Bạn dưới {config.parentConsentAge} tuổi. Vui lòng cung cấp ít nhất 1 thông tin liên hệ phụ huynh bên dưới.
          </p>
          <TextInput
            label="Số điện thoại phụ huynh"
            type="tel"
            placeholder="09xxxxxxxx"
            {...register("parent_phone")}
          />
          <TextInput
            label="Email phụ huynh"
            type="email"
            placeholder="phuhuynh@email.com"
            {...register("parent_email")}
          />
          {errors.parent_phone ? (
            <p role="alert" className="flex items-center gap-1 text-xs text-rose-600">
              <span aria-hidden="true">⚠</span> {errors.parent_phone.message}
            </p>
          ) : null}
        </div>
      ) : null}

      <TextInput label="Email" type="email" required error={errors.email?.message} {...register("email")} />

      <TextInput
        label="Số điện thoại"
        type="tel"
        required
        error={errors.phone?.message}
        {...register("phone")}
      />

      <Select
        label="Lớp đang học"
        required
        placeholder="-- Chọn lớp --"
        options={gradeOptions}
        error={errors.grade_level?.message}
        {...register("grade_level")}
      />

      <PasswordInput
        label="Mật khẩu"
        required
        hint="Tối thiểu 8 ký tự"
        error={errors.password?.message}
        {...register("password")}
      />

      <PasswordInput
        label="Xác nhận mật khẩu"
        required
        error={errors.password_confirmation?.message}
        {...register("password_confirmation")}
      />

      {config.referralCodeEnabled ? (
        <TextInput
          label="Mã giới thiệu (không bắt buộc)"
          placeholder="VD: BANBE2026"
          hint="Nếu có, nhập mã của người giới thiệu bạn"
          {...register("referral_code")}
        />
      ) : null}

      <ConsentCheckboxGroup
        termsChecked={acceptTerms}
        privacyChecked={acceptPrivacy}
        onTermsChange={(checked) => setValue("accept_terms", checked, { shouldValidate: true })}
        onPrivacyChange={(checked) => setValue("accept_privacy", checked, { shouldValidate: true })}
        error={errors.accept_terms?.message}
        policyVersion={config.policyVersion}
      />

      {config.captchaSiteKey ? (
        <TurnstileWidget
          key={turnstileResetKey}
          siteKey={config.captchaSiteKey}
          nonce={nonce}
          onVerify={(token) => setValue("captcha_token", token, { shouldValidate: true })}
          onExpire={() => setValue("captcha_token", "", { shouldValidate: true })}
          onError={() => {
            setValue("captcha_token", "", { shouldValidate: true });
            setBanner({ message: "Xác minh chống spam thất bại, vui lòng thử lại", variant: "danger" });
          }}
        />
      ) : (
        <p className="text-xs text-gray-500">
          Xác minh chống spam: chưa cấu hình Turnstile ở môi trường này (local).
        </p>
      )}

      <Button
        type="submit"
        variant="primary"
        size="lg"
        className="w-full"
        loading={isSubmitting}
        disabled={!canSubmit}
        title={!canSubmit && !isSubmitting ? "Cần đồng ý điều khoản + hoàn tất captcha" : undefined}
      >
        {isSubmitting ? "Đang tạo tài khoản..." : "Tạo tài khoản"}
      </Button>

      <p className="text-center text-sm text-gray-500">
        Đã có tài khoản?{" "}
        <a href="/dang-nhap" className="font-medium text-indigo-600">
          Đăng nhập
        </a>
      </p>
    </form>
  );
}

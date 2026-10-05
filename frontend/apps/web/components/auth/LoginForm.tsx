"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { safeRedirect } from "@vitaminvui/api-client";
import { Alert, Button, FormField, PasswordInput, TextInput } from "@vitaminvui/ui";
import { loginStudent } from "@/lib/auth/api";
import { loginErrorMessage } from "@/lib/auth/errors";
import { loginSchema, type LoginFormValues } from "@/lib/auth/schemas";

export interface LoginFormProps {
  /** Giá trị thô của `?next=` — được validate bằng `safeRedirect` (chống open redirect). */
  next?: string | null;
}

/** Form `/dang-nhap` (US-001 §2.3). Quên mật khẩu (US-015) chưa làm ở FW1 phần 1. */
export function LoginForm({ next }: LoginFormProps) {
  const router = useRouter();
  const [banner, setBanner] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const {
    register,
    handleSubmit,
    resetField,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { login: "", password: "" },
  });

  const locked = isSubmitting || done;

  const onSubmit = handleSubmit(async (values) => {
    setBanner(null);
    try {
      await loginStudent(values);
      setDone(true);
      router.replace(safeRedirect(next, "/"));
      router.refresh();
    } catch (err) {
      setBanner(loginErrorMessage(err));
      resetField("password", { defaultValue: "" });
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4" aria-busy={locked}>
      {banner ? <Alert variant="danger">{banner}</Alert> : null}

      <fieldset disabled={locked} className="space-y-4">
        <FormField label="Email hoặc số điện thoại" required error={errors.login?.message}>
          <TextInput autoComplete="username" placeholder="Email hoặc số điện thoại" {...register("login")} />
        </FormField>

        <FormField label="Mật khẩu" required error={errors.password?.message}>
          <PasswordInput autoComplete="current-password" {...register("password")} />
        </FormField>
      </fieldset>

      <Button type="submit" size="lg" className="w-full" loading={locked}>
        Đăng nhập
      </Button>

      <p className="text-center text-sm text-gray-700">
        Chưa có tài khoản?{" "}
        <Link href="/dang-ky" className="font-medium text-indigo-700 underline hover:text-indigo-800">
          Đăng ký ngay
        </Link>
      </p>
    </form>
  );
}

"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Alert, Button, PasswordInput, TextInput, useToast } from "@vitaminvui/ui";
import { ApiError, NetworkError, getDeviceId, safeRedirect } from "@vitaminvui/api-client";
import { authFetch } from "@/lib/api";
import { notifyAuthChanged } from "@/lib/auth/useCurrentUser";
import { parseAuthUser } from "@/lib/types/auth";
import { loginSchema, type LoginFormValues } from "@/lib/validation/loginSchema";

interface Banner {
  message: string;
  variant: "danger" | "warning";
}

export function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const toast = useToast();
  const [banner, setBanner] = useState<Banner | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { login: "", password: "" },
  });

  async function onSubmit(values: LoginFormValues) {
    setBanner(null);

    try {
      const raw = await authFetch<unknown>("/api/v1/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ...values, device_id: getDeviceId() }),
      });
      parseAuthUser(raw);

      toast.show("success", "Đăng nhập thành công");
      notifyAuthChanged();
      router.push(safeRedirect(searchParams.get("next")));
      return;
    } catch (err) {
      // AC4/BR5: sai thông tin đăng nhập luôn hiển thị banner CHUNG, không chỉ rõ field
      // nào sai (chống dò tài khoản) — khác đăng ký (lỗi trùng email/SĐT vẫn hiện theo
      // field). ACCOUNT_LOCKED, WRONG_PORTAL, TOO_MANY_ATTEMPTS (429) đều dùng message
      // tiếng Việt do server trả (api-contract §1.7), chỉ đổi màu banner theo mức nghiêm
      // trọng.
      if (err instanceof ApiError) {
        setBanner({ message: err.message, variant: err.status === 429 ? "warning" : "danger" });
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
      {banner ? <Alert variant={banner.variant}>{banner.message}</Alert> : null}

      <TextInput
        label="Email hoặc số điện thoại"
        required
        error={errors.login?.message}
        {...register("login")}
      />
      <PasswordInput label="Mật khẩu" required error={errors.password?.message} {...register("password")} />

      <div className="text-right">
        <a href="/quen-mat-khau" className="text-sm font-medium text-indigo-600">
          Quên mật khẩu?
        </a>
      </div>

      <Button type="submit" variant="primary" size="lg" className="w-full" loading={isSubmitting}>
        {isSubmitting ? "Đang đăng nhập..." : "Đăng nhập"}
      </Button>

      <p className="text-center text-sm text-gray-500">
        Chưa có tài khoản?{" "}
        <a href="/dang-ky" className="font-medium text-indigo-600">
          Đăng ký ngay
        </a>
      </p>
    </form>
  );
}

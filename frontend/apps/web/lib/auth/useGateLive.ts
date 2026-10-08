"use client";

import { useMemo } from "react";
import { sendOtp } from "./api";
import { useAuth } from "./AuthProvider";
import { maskEmail } from "./otp";
import type { GateLive } from "@/components/v2/auth/AccountGate";
import { routes } from "@/lib/routes";

/**
 * Dữ liệu + hành động THẬT cho màn chặn (`AccountGate`): email đã che từ `/auth/me`, gửi mã OTP qua `POST /auth/otp/send`.
 */
export function useGateLive(): GateLive {
  const { state } = useAuth();
  const email = state.status === "user" ? state.user.email : null;
  return useMemo<GateLive>(
    () => ({
      maskedEmail: email ? maskEmail(email) : undefined,
      onSendCode: async () => {
        try {
          await sendOtp();
        } catch {
          // 429 (đang chờ / hết lượt) và 503: màn OTP tự báo; vẫn dẫn người dùng sang đó.
        }
      },
      verifyHref: routes.verifyOtp,
      catalogHref: routes.catalog,
    }),
    [email],
  );
}

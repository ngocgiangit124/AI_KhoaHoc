import type { Metadata } from "next";
import { AuthFrame } from "@/components/v2/auth/AuthFrame";
import { LoginForm } from "@/components/auth/LoginForm";
import { env } from "@/env";
import { fetchPublicConfig } from "@/lib/catalog/api";
import { parseLoginNotice } from "@/lib/auth/loginNotice";

/** GL-A2: site key Turnstile cho lần đăng nhập bị đòi captcha. Lỗi cấu hình KHÔNG được làm hỏng trang đăng nhập. */
async function loadCaptchaSiteKey(): Promise<string | null> {
  try {
    return (await fetchPublicConfig()).captcha_site_key || env.NEXT_PUBLIC_TURNSTILE_SITE_KEY || null;
  } catch {
    return env.NEXT_PUBLIC_TURNSTILE_SITE_KEY || null;
  }
}

export const metadata: Metadata = { title: "Đăng nhập — VitaminVui" };

export const dynamic = "force-dynamic";

export default async function LoginPage({ searchParams }: PageProps<"/dang-nhap">) {
  const { next, "trang-thai": status } = await searchParams;
  const captchaSiteKey = await loadCaptchaSiteKey();

  return (
    <AuthFrame title="Đăng nhập" subtitle="Chào mừng bạn quay lại học tiếp.">
      <LoginForm
        captchaSiteKey={captchaSiteKey}
        next={typeof next === "string" ? next : null} notice={parseLoginNotice(typeof status === "string" ? status : undefined)}
      />
    </AuthFrame>
  );
}

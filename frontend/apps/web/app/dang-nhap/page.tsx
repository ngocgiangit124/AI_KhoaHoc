import { Suspense } from "react";
import { LoginForm } from "./LoginForm";

/** `/dang-nhap` (US-001 §2.3). Render động (ADR-004 §2.7 — CSP nonce). */
export const dynamic = "force-dynamic";

export default function LoginPage() {
  return (
    <main className="mx-auto max-w-md px-4 py-10">
      <div className="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <h1 className="mb-1 text-2xl font-bold text-gray-900">Đăng nhập</h1>
        <p className="mb-6 text-sm text-gray-500">Chào mừng bạn quay lại VitaminVui!</p>

        {/* useSearchParams() (đọc ?next=) cần bọc Suspense theo khuyến nghị của Next.js. */}
        <Suspense fallback={null}>
          <LoginForm />
        </Suspense>
      </div>
    </main>
  );
}

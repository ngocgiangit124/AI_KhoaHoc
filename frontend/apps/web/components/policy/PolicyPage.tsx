import type { ReactNode } from "react";
import { Alert, Sheet } from "@vitaminvui/ui/v2";

/** Khung trang văn bản chính sách TẠM: nhãn "Bản tạm — chờ pháp chế" + phiên bản hiện hành từ `/config/public`. */
export function PolicyPage({ title, version, children }: { title: string; version: string; children: ReactNode }) {
  return (
    <div className="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6">
      <Sheet>
        <h1 className="text-title font-extrabold tracking-heading text-ink">{title}</h1>
        <p className="mt-1 text-sm text-ink-soft">
          Phiên bản <span className="num font-semibold text-ink" data-testid="policy-version">{version}</span>
        </p>
        <Alert tone="warning" title="Bản tạm — chờ pháp chế" className="mt-4">
          Đây là nội dung tạm thời để bạn biết chúng tôi xử lý dữ liệu thế nào. Văn bản này chưa phải văn bản pháp lý chính thức và sẽ được thay khi
          bộ phận pháp chế hoàn thiện.
        </Alert>
        <div className="mt-6 flex flex-col gap-6 text-base leading-relaxed text-ink">{children}</div>
      </Sheet>
    </div>
  );
}

export function PolicySection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-2">
      <h2 className="text-heading font-extrabold tracking-heading text-ink">{title}</h2>
      {children}
    </section>
  );
}

import type { ReactNode } from "react";
import { IconCheck } from "@vitaminvui/ui/v2";

/**
 * Bố cục trang đăng nhập/đăng ký: desktop chia 2 — trái là trang vở (ô ly + lề đỏ + ghi chú viết tay,
 * trang trí), phải là form. Mobile chỉ còn form, không có gì đẩy form xuống dưới màn hình.
 */
export function AuthFrame({ title, subtitle, children, aside }: { title: string; subtitle?: ReactNode; children: ReactNode; aside?: ReactNode }) {
  return (
    <div className="flex flex-1">
      <div aria-hidden="true" className="bg-oly relative hidden w-[42%] shrink-0 overflow-hidden border-r border-line bg-paper lg:block">
        <span className="absolute inset-y-0 left-12 w-0.5 bg-margin" />
        <div className="flex h-full flex-col justify-center gap-6 py-16 pl-20 pr-10">
          <p className="max-w-sm -rotate-1 font-hand text-2xl leading-snug text-primary">
            {aside ?? "Mỗi ngày một bài, cuối tuần làm một đề. Học đều là chắc!"}
          </p>
          <ul className="flex flex-col gap-3 text-base text-ink">
            {["Video bài giảng theo đúng chương trình", "Trắc nghiệm có lời giải sau mỗi bài", "Lưu tiến độ, học tiếp đúng chỗ"].map((t) => (
              <li key={t} className="flex items-center gap-2">
                <span className="flex size-6 items-center justify-center rounded-full bg-primary text-on-primary">
                  <IconCheck size={14} strokeWidth={3} />
                </span>
                {t}
              </li>
            ))}
          </ul>
        </div>
      </div>
      <div className="flex flex-1 justify-center px-4 py-8 sm:py-12">
        <div className="w-full max-w-md">
          <h1 className="text-title font-extrabold tracking-heading text-ink">{title}</h1>
          {subtitle ? <div className="mt-2 text-base text-ink-soft">{subtitle}</div> : null}
          <div className="mt-6">{children}</div>
        </div>
      </div>
    </div>
  );
}

"use client";

import { useState, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { Button, ButtonLink, Dialog, IconMail } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

/**
 * Màn chặn trước hành động cần tài khoản đã xác thực (đăng ký học miễn phí, sau này checkout).
 * ADR-006: không còn màn chờ phụ huynh đồng ý.
 * - `verify`          403 `ACCOUNT_NOT_VERIFIED` (US-001 §2.4, AC9).
 * Không dùng màu đỏ: đây không phải lỗi của học sinh. Luôn có một việc làm tiếp + một lối thoát.
 */
export type GateKind = "verify";

/**
 * Dữ liệu và hành động THẬT (bản app, không phải bản xem trước). Bỏ trống → bản xem trước dùng dữ liệu mẫu.
 * - `onSendCode`: `POST /auth/otp/send` (lỗi 429/503 được nuốt: vẫn sang `verifyHref`, màn OTP tự hiện thời gian chờ/lỗi).
 */
export interface GateLive {
  maskedEmail?: string;
  onSendCode: () => Promise<void>;
  verifyHref: string;
  /** Lối thoát khi mở thẳng (trang): danh mục khóa học. */
  catalogHref: string;
}

interface GateCopy {
  icon: ReactNode;
  title: string;
  body: ReactNode;
}

function copyFor(maskedEmail: string): GateCopy {
  return {
    icon: <IconMail size={28} />,
    title: "Xác thực email để tiếp tục",
    body: (
      <>
        Bạn cần xác thực email trước khi đăng ký khóa học. Chúng tôi sẽ gửi mã gồm 6 chữ số tới <strong className="font-semibold text-ink">{maskedEmail}</strong>.
      </>
    ),
  };
}

/** Hành động của màn chặn: gửi mã xác thực + lối thoát. */
function GateActions({ live, onLater, laterLabel }: { live?: GateLive; onLater?: () => void; laterLabel: string }) {
  const router = useRouter();
  const [sending, setSending] = useState(false);

  return (
    <div className="flex w-full flex-col gap-2">
      <Button
        size="lg"
        block
        loading={sending}
        loadingText="Đang gửi mã…"
        leadingIcon={<IconMail size={18} />}
        onClick={() => {
          setSending(true);
          if (live) {
            // 202 → sang màn OTP; 429/503 → vẫn sang, màn OTP hiện thời gian chờ / "Gửi lại mã".
            void live.onSendCode().finally(() => router.push(live.verifyHref));
            return;
          }
          setTimeout(() => window.location.assign(routes.verifyOtp), 700);
        }}
      >
        Gửi mã xác nhận
      </Button>
      <ButtonLink href={live?.verifyHref ?? routes.verifyOtp} variant="ghost" block>
        Tôi đã có mã
      </ButtonLink>
      {onLater ? (
        <Button variant="ghost" block onClick={onLater}>
          {laterLabel}
        </Button>
      ) : (
        <ButtonLink href={live?.catalogHref ?? routes.catalog} variant="ghost" block>
          {laterLabel}
        </ButtonLink>
      )}
    </div>
  );
}

const MASKED_EMAIL = "m*****1@gmail.com";

/** Bản hộp thoại: mở tại chỗ khi bấm "Đăng ký học miễn phí" mà API trả 403 — giữ ngữ cảnh khóa học. */
export function AccountGateDialog({ open, onClose, live }: { kind?: GateKind; open: boolean; onClose: () => void; live?: GateLive }) {
  const c = copyFor(live ? (live.maskedEmail ?? "email của bạn") : MASKED_EMAIL);
  return (
    <Dialog
      open={open}
      onClose={onClose}
      size="sm"
      sheetOnMobile
      title={
        <span className="flex flex-col gap-3">
          <span aria-hidden="true" className="bg-oly flex size-14 items-center justify-center rounded-card border border-line bg-paper text-primary">
            {c.icon}
          </span>
          {c.title}
        </span>
      }
      description={c.body}
      footer={<GateActions live={live} onLater={onClose} laterLabel="Để sau" />}
    />
  );
}

/** Bản trang đầy đủ: khi mở thẳng một trang cần điều kiện (ví dụ checkout ở V2). */
export function AccountGatePage({ live }: { kind?: GateKind; live?: GateLive }) {
  const c = copyFor(live ? (live.maskedEmail ?? "email của bạn") : MASKED_EMAIL);
  return (
    <div className="mx-auto flex max-w-md flex-col items-center gap-4 rounded-sheet border border-line bg-surface px-5 py-8 text-center sm:px-8 sm:py-10">
      <span aria-hidden="true" className="bg-oly flex size-20 items-center justify-center rounded-card border border-line bg-paper text-primary">
        {c.icon}
      </span>
      <h1 className="text-title font-extrabold tracking-heading text-ink">{c.title}</h1>
      <p className="text-base text-ink-soft">{c.body}</p>
      <div className="mt-2 w-full">
        <GateActions live={live} laterLabel="Xem khóa học khác" />
      </div>
    </div>
  );
}

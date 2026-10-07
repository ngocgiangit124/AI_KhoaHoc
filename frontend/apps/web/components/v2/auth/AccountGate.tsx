"use client";

import { useState, type ReactNode } from "react";
import { Alert, Button, ButtonLink, Dialog, IconHourglass, IconMail, ResendCode, useToast } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

/**
 * Màn chặn trước hành động cần tài khoản "đủ điều kiện" (đăng ký học miễn phí, sau này checkout):
 * - `verify`          403 `ACCOUNT_NOT_VERIFIED` (US-001 §2.4, AC9).
 * - `parent-pending`  403 `PARENT_CONSENT_REQUIRED`, `parent_consent_status=pending` (US-017 BR6, §2.5).
 * - `parent-revoked`  như trên nhưng phụ huynh đã rút lại đồng ý.
 * Không dùng màu đỏ: đây không phải lỗi của học sinh. Luôn có một việc làm tiếp + một lối thoát.
 */
export type GateKind = "verify" | "parent-pending" | "parent-revoked";

export interface GateDemo {
  /** Đã hết 3 lượt gửi lại email phụ huynh trong ngày (POST /me/parent-consent/resend → 429). */
  resendExhausted?: boolean;
}

interface GateCopy {
  icon: ReactNode;
  title: string;
  body: ReactNode;
}

function copyFor(kind: GateKind, maskedEmail: string, maskedParent: string): GateCopy {
  if (kind === "verify") {
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
  if (kind === "parent-pending") {
    return {
      icon: <IconHourglass size={28} />,
      title: "Đang chờ phụ huynh xác nhận",
      body: (
        <>
          Vì bạn dưới 18 tuổi, phụ huynh cần đồng ý trước khi bạn đăng ký khóa học. Chúng tôi đã gửi email xác nhận tới{" "}
          <strong className="font-semibold text-ink">{maskedParent}</strong>. Nhắc bố mẹ mở thư và bấm “Đồng ý” nhé.
        </>
      ),
    };
  }
  return {
    icon: <IconHourglass size={28} />,
    title: "Phụ huynh đã rút lại đồng ý",
    body: (
      <>
        Phụ huynh của bạn đã rút lại sự đồng ý trước đó, nên tạm thời bạn chưa đăng ký thêm khóa học được. Hãy nói chuyện với bố mẹ, rồi gửi lại email xin xác nhận tới{" "}
        <strong className="font-semibold text-ink">{maskedParent}</strong>.
      </>
    ),
  };
}

/** Hành động của màn chặn: gửi mã/gửi email phụ huynh + lối thoát. */
function GateActions({ kind, demo, onLater, laterLabel }: { kind: GateKind; demo?: GateDemo; onLater?: () => void; laterLabel: string }) {
  const toast = useToast();
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState<{ wait: number; key: number }>({ wait: 0, key: 0 });

  if (kind === "verify") {
    return (
      <div className="flex w-full flex-col gap-2">
        <Button
          size="lg"
          block
          loading={sending}
          loadingText="Đang gửi mã…"
          leadingIcon={<IconMail size={18} />}
          onClick={() => {
            // TODO(dev): POST /auth/otp/send (202 resend_available_at) rồi chuyển /xac-thuc-otp. 429 → vẫn chuyển, màn OTP hiện thời gian chờ.
            setSending(true);
            setTimeout(() => window.location.assign(routes.verifyOtp), 700);
          }}
        >
          Gửi mã xác nhận
        </Button>
        <ButtonLink href={routes.verifyOtp} variant="ghost" block>
          Tôi đã có mã
        </ButtonLink>
      </div>
    );
  }
  return (
    <div className="flex w-full flex-col gap-2">
      <ResendCode
        key={sent.key}
        waitSeconds={sent.wait}
        loading={sending}
        block
        label="Gửi lại email cho phụ huynh"
        lockedReason={demo?.resendExhausted ? "Đã gửi lại 3 lần hôm nay. Bạn có thể gửi lại vào ngày mai." : undefined}
        onResend={() => {
          // TODO(dev): POST /me/parent-consent/resend (tối đa 3 lần/ngày) — 429 → lockedReason.
          setSending(true);
          setTimeout(() => {
            setSending(false);
            setSent((s) => ({ wait: 60, key: s.key + 1 }));
            toast.show({ tone: "success", title: "Đã gửi lại email cho phụ huynh" });
          }, 800);
        }}
      />
      {onLater ? (
        <Button variant="ghost" block onClick={onLater}>
          {laterLabel}
        </Button>
      ) : (
        <ButtonLink href={routes.catalog} variant="ghost" block>
          {laterLabel}
        </ButtonLink>
      )}
    </div>
  );
}

const MASKED_EMAIL = "m*****1@gmail.com";
const MASKED_PARENT = "h*****g@gmail.com";

/** Bản hộp thoại: mở tại chỗ khi bấm "Đăng ký học miễn phí" mà API trả 403 — giữ ngữ cảnh khóa học. */
export function AccountGateDialog({ kind, open, onClose, demo }: { kind: GateKind; open: boolean; onClose: () => void; demo?: GateDemo }) {
  const c = copyFor(kind, MASKED_EMAIL, MASKED_PARENT);
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
      footer={<GateActions kind={kind} demo={demo} onLater={onClose} laterLabel="Để sau" />}
    />
  );
}

/** Bản trang đầy đủ: khi mở thẳng một trang cần điều kiện (ví dụ checkout ở V2). */
export function AccountGatePage({ kind, demo }: { kind: GateKind; demo?: GateDemo }) {
  const c = copyFor(kind, MASKED_EMAIL, MASKED_PARENT);
  return (
    <div className="mx-auto flex max-w-md flex-col items-center gap-4 rounded-sheet border border-line bg-surface px-5 py-8 text-center sm:px-8 sm:py-10">
      <span aria-hidden="true" className="bg-oly flex size-20 items-center justify-center rounded-card border border-line bg-paper text-primary">
        {c.icon}
      </span>
      <h1 className="text-title font-extrabold tracking-heading text-ink">{c.title}</h1>
      <p className="text-base text-ink-soft">{c.body}</p>
      {kind !== "verify" ? (
        <Alert tone="info" className="text-left">
          Trong lúc chờ, bạn vẫn xem được danh mục và học thử các bài cho xem thử.
        </Alert>
      ) : null}
      <div className="mt-2 w-full">
        <GateActions kind={kind} demo={demo} laterLabel="Xem khóa học khác" />
      </div>
    </div>
  );
}

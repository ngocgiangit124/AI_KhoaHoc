"use client";

import { useEffect, useRef, useState } from "react";
import { Alert, Button, ButtonLink, Sheet } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/routes";
import { unsubscribeParentNotice } from "@/lib/privacy/api";
import { classifyUnsubscribeError } from "@/lib/privacy/errors";
import { readUnsubscribeToken } from "@/lib/privacy/unsubscribe";

export const UNSUBSCRIBE_DONE_MESSAGE = "Đã ghi nhận. Bạn sẽ không nhận thêm thông báo từ VitaminVui về học sinh này.";

/**
 * Trang công khai huỷ nhận thông báo của phụ huynh. Mở trang KHÔNG tự gửi gì (trình quét link của hộp thư sẽ mở trang). Token đọc từ `?t=`
 * rồi gỡ khỏi URL bằng `history.replaceState`; bấm nút mới POST. Mọi phản hồi thành công như nhau (không lộ token đúng/sai).
 */
export function UnsubscribeView() {
  const [token, setToken] = useState<string | null>(null);
  const [checked, setChecked] = useState(false);
  const [status, setStatus] = useState<"idle" | "pending" | "done">("idle");
  const [error, setError] = useState<string | null>(null);
  const [invalid, setInvalid] = useState(false);
  const inflight = useRef(false);

  useEffect(() => {
    // Đọc URL (hệ thống ngoài) chỉ làm được sau hydrate; token giữ trong state, URL được dọn ngay.
    const t = readUnsubscribeToken(window.location.search);
    if (t) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- một lần khi mount, đồng bộ từ URL
      setToken(t);
      window.history.replaceState(window.history.state, "", window.location.pathname);
    }
    setChecked(true);
  }, []);

  async function submit() {
    if (inflight.current || !token) return; // chặn bấm kép
    inflight.current = true;
    setStatus("pending");
    setError(null);
    try {
      await unsubscribeParentNotice(token);
      setStatus("done");
      setToken(null);
    } catch (err) {
      const f = classifyUnsubscribeError(err);
      setStatus("idle");
      if (f.kind === "invalid-link") setInvalid(true);
      else setError(f.message);
      inflight.current = false;
    }
  }

  return (
    <Sheet>
      <h1 className="text-title font-extrabold tracking-heading text-ink">Huỷ nhận thông báo</h1>
      <div className="mt-4 flex flex-col gap-4">
        {status === "done" ? (
          <Alert tone="success" title="Đã huỷ nhận thông báo">
            {UNSUBSCRIBE_DONE_MESSAGE}
          </Alert>
        ) : !checked ? (
          <p className="text-base text-ink-soft">Đang tải…</p>
        ) : token && !invalid ? (
          <>
            <p className="text-base text-ink-soft">
              VitaminVui gửi thư thông báo cho phụ huynh về tài khoản học của con (ví dụ khi tạo tài khoản hoặc khi thanh toán khóa học). Bấm nút bên
              dưới nếu bạn không muốn nhận thêm thư loại này.
            </p>
            {error ? <Alert tone="danger">{error}</Alert> : null}
            <div>
              <Button block loading={status === "pending"} loadingText="Đang gửi…" onClick={() => void submit()}>
                Huỷ nhận thông báo
              </Button>
            </div>
          </>
        ) : (
          <Alert tone="warning" title="Liên kết không dùng được">
            Liên kết huỷ nhận thiếu thông tin hoặc đã được mở lại (tải lại trang sẽ làm mất mã). Vui lòng mở lại liên kết trong thư VitaminVui gần nhất, hoặc liên hệ bộ phận hỗ trợ.
          </Alert>
        )}
        <div>
          <ButtonLink href={routes.home} variant="ghost">
            Về trang chủ
          </ButtonLink>
        </div>
      </div>
    </Sheet>
  );
}

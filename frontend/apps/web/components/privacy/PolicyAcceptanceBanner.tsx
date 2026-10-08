"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Alert, Button, Checkbox, useToast } from "@vitaminvui/ui/v2";
import { useAuth } from "@/lib/auth/AuthProvider";
import { routes } from "@/lib/routes";
import { acceptConsents, fetchConsents } from "@/lib/privacy/api";
import { classifyAcceptError } from "@/lib/privacy/errors";

/**
 * Banner "Điều khoản đã cập nhật" khi `/auth/me.needs_policy_acceptance = true` (api-contract §2.8.1, §2.8.3). KHÔNG chặn học/mua.
 * Phiên bản hiện hành lấy từ `GET /me/consents` (meta). Hai ô đồng ý không tick sẵn. 409 `CONSENT_VERSION_CHANGED`: dùng
 * `errors.current_version` (hoặc tải lại), bỏ tick và nhắc người dùng xem lại văn bản.
 */
export function PolicyAcceptanceBanner() {
  const { state, refresh } = useAuth();
  const needs = state.status === "user" && state.user.needs_policy_acceptance === true;
  if (!needs) return null;
  return <BannerBody onAccepted={() => void refresh()} />;
}

function BannerBody({ onAccepted }: { onAccepted: () => void }) {
  const toast = useToast();
  const [version, setVersion] = useState<string | null>(null);
  const [terms, setTerms] = useState(false);
  const [privacy, setPrivacy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    fetchConsents(controller.signal).then(
      (res) => {
        if (!controller.signal.aborted) setVersion(res.meta.current_policy_version);
      },
      () => undefined, // không có phiên bản thì nút Đồng ý tự thử tải lại khi bấm
    );
    return () => controller.abort();
  }, []);

  async function submit() {
    if (pending) return;
    setError(null);
    if (!terms || !privacy) {
      setError("Vui lòng tích cả hai ô để tiếp tục.");
      return;
    }
    setPending(true);
    try {
      let v = version;
      if (!v) {
        v = (await fetchConsents()).meta.current_policy_version;
        setVersion(v);
      }
      await acceptConsents(v);
      toast.show({ tone: "success", title: "Đã ghi nhận đồng ý", description: "Cảm ơn bạn đã xem điều khoản mới." });
      onAccepted();
    } catch (err) {
      const f = classifyAcceptError(err);
      if (f.kind === "version-changed") {
        setVersion(f.currentVersion);
        setTerms(false);
        setPrivacy(false);
        setNotice("Điều khoản vừa được cập nhật lại. Vui lòng xem văn bản mới và tích đồng ý lần nữa.");
        if (!f.currentVersion) fetchConsents().then((r) => setVersion(r.meta.current_policy_version), () => undefined);
      } else {
        setError(f.message);
      }
    } finally {
      setPending(false);
    }
  }

  return (
    <Alert tone="info" title="Điều khoản đã cập nhật" className="mb-2">
      <div className="flex flex-col gap-1">
        {notice ? <p className="font-semibold">{notice}</p> : null}
        <p>
          Chúng tôi đã cập nhật{" "}
          <Link href={routes.terms} className="focus-ring rounded font-semibold text-primary underline">Điều khoản sử dụng</Link> và{" "}
          <Link href={routes.privacy} className="focus-ring rounded font-semibold text-primary underline">Chính sách xử lý dữ liệu cá nhân</Link>
          {version ? <> (phiên bản <span className="num">{version}</span>)</> : null}. Bạn vẫn học và mua khóa như bình thường; hãy xem và đồng ý khi tiện.
        </p>
        <Checkbox id="policy-terms" checked={terms} onChange={(e) => setTerms(e.target.checked)} label="Tôi đã đọc và đồng ý với Điều khoản sử dụng" />
        <Checkbox id="policy-privacy" checked={privacy} onChange={(e) => setPrivacy(e.target.checked)} label="Tôi đã đọc và đồng ý với Chính sách xử lý dữ liệu cá nhân" />
        {error ? <p role="alert" className="font-semibold text-danger">{error}</p> : null}
        <div className="pt-1">
          <Button onClick={() => void submit()} loading={pending} loadingText="Đang gửi…">
            Đồng ý
          </Button>
        </div>
      </div>
    </Alert>
  );
}

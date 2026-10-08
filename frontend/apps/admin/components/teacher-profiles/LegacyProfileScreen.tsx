"use client";

import { useEffect, useState } from "react";
import { Alert, Button, ConfirmDialog, EmptyState, IconCheckCircle, IconImage, IconInfo, LoadingRegion, Skeleton, formatDateTime, useToast } from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { deleteAvatar, getProfile, withdrawConsent } from "@/lib/teacher-profiles/api";
import { classifyProfileError, isForbidden, profileActionError } from "@/lib/teacher-profiles/errors";
import { hasLegacyData, useLegacyProfile } from "@/lib/teacher-profiles/legacy";
import type { TeacherProfile } from "@/lib/teacher-profiles/types";
import { ProfileStatusCard } from "./ProfileStatusCard";

type Loaded = { profile: TeacherProfile | null; error: unknown };

/**
 * "Hồ sơ giáo viên cũ" (FA11-1, US-020 / security T36 L1): người đã đổi vai trò, không còn là giáo viên, vẫn tự rút đồng ý
 * công khai và xoá ảnh của mình. Chỉ xem trạng thái + hai thao tác gỡ; KHÔNG sửa nội dung, KHÔNG đồng ý lại
 * (chỉ giáo viên được đồng ý; server cũng chặn bằng 403).
 */
export function LegacyProfileScreen() {
  const toast = useToast();
  const { report } = useLegacyProfile();
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [profile, setProfile] = useState<TeacherProfile | null>(null);
  const [confirm, setConfirm] = useState<"consent" | "avatar" | null>(null);
  const [busy, setBusy] = useState(false);
  const [banner, setBanner] = useState<string | null>(null);
  const [broken, setBroken] = useState(false);
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    getProfile({ kind: "me" }, controller.signal)
      .then((p) => {
        setProfile(p);
        setLoaded({ profile: p, error: null });
        report(p);
      })
      .catch((error: unknown) => {
        if (!controller.signal.aborted) setLoaded({ profile: null, error });
      });
    return () => controller.abort();
  }, [report, retry]);

  async function run() {
    const kind = confirm;
    if (!kind || busy) return;
    setBusy(true);
    setBanner(null);
    try {
      const next = kind === "consent" ? await withdrawConsent() : await deleteAvatar({ kind: "me" });
      setProfile(next);
      report(next);
      if (kind === "avatar") setBroken(false);
      toast.show(
        kind === "consent"
          ? { tone: "success", title: "Đã rút đồng ý công khai", description: "Ảnh và giới thiệu sẽ ẩn khỏi website trong tối đa 1 phút." }
          : { tone: "success", title: "Đã xoá ảnh hồ sơ" },
      );
    } catch (err) {
      setBanner(classifyProfileError(err).banner ?? profileActionError(err));
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  }

  if (loaded === null) {
    return (
      <LoadingRegion className="flex flex-col gap-4">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-64 w-full" />
      </LoadingRegion>
    );
  }
  if (!profile) {
    if (isForbidden(loaded.error)) return <ForbiddenView />;
    return (
      <Alert
        tone="danger"
        title="Không tải được hồ sơ"
        action={
          <Button size="sm" variant="secondary" onClick={() => { setLoaded(null); setRetry((n) => n + 1); }}>
            Thử lại
          </Button>
        }
      >
        {profileActionError(loaded.error)}
      </Alert>
    );
  }

  const { consent } = profile;
  const showAvatar = profile.avatar_url !== null && !broken;

  return (
    <div className="flex flex-col">
      <h1 className="text-title font-extrabold tracking-heading text-ink">Hồ sơ giáo viên cũ</h1>
      <p className="mt-1 max-w-3xl text-sm text-ink-soft">
        Tài khoản của bạn không còn là giáo viên nhưng vẫn lưu ảnh và đồng ý công khai trước đây. Bạn có thể rút đồng ý và xoá ảnh bất kỳ lúc nào. Không thể sửa nội dung hay đồng ý lại.
      </p>

      <div className="mt-6 grid gap-6 xl:grid-cols-[1fr_380px]">
        <div className="flex min-w-0 flex-col gap-6">
          {banner ? <Alert tone="danger" title={banner} role="alert" /> : null}
          {!hasLegacyData(profile) ? (
            <div data-testid="legacy-empty">
              <EmptyState headingLevel="h2" icon={<IconCheckCircle size={32} />} title="Không còn dữ liệu công khai" description="Bạn đã rút đồng ý và không còn ảnh hồ sơ. Mục này sẽ ẩn khỏi menu." />
            </div>
          ) : null}

          <section aria-labelledby="legacy-anh" className="flex flex-col gap-4 rounded-card border border-line bg-surface p-5">
            <h2 id="legacy-anh" className="text-base font-semibold text-ink">Ảnh đại diện</h2>
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
              <div className="relative size-32 shrink-0 overflow-hidden rounded-card border border-line">
                {showAvatar ? (
                  // eslint-disable-next-line @next/next/no-img-element -- ảnh tĩnh từ STATIC_URL
                  <img src={profile.avatar_url as string} alt={`Ảnh hồ sơ ${profile.user.name}`} className="size-full object-cover" onError={() => setBroken(true)} />
                ) : (
                  <div className="flex size-full flex-col items-center justify-center gap-1 bg-sunken text-sm text-ink-soft">
                    <IconImage />
                    {profile.avatar_url ? "Không tải được ảnh" : "Chưa có ảnh"}
                  </div>
                )}
              </div>
              {profile.avatar_url !== null ? (
                <Button type="button" variant="secondary" size="sm" className="max-sm:h-11" disabled={busy} onClick={() => setConfirm("avatar")}>
                  Xoá ảnh
                </Button>
              ) : null}
            </div>
          </section>

          <section aria-labelledby="legacy-dong-y" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
            <h2 id="legacy-dong-y" className="text-base font-semibold text-ink">Đồng ý công khai</h2>
            {consent.given ? (
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <p className="flex flex-1 items-start gap-2 text-base text-ink" data-testid="legacy-consent-given">
                  <IconCheckCircle className="mt-0.5 shrink-0 text-success" />
                  <span>Bạn đang đồng ý công khai{consent.given_at ? ` (từ ${formatDateTime(consent.given_at)})` : ""}.</span>
                </p>
                <Button type="button" variant="secondary" size="sm" className="max-sm:h-11" disabled={busy} onClick={() => setConfirm("consent")}>
                  Rút đồng ý
                </Button>
              </div>
            ) : (
              <p className="flex items-start gap-2 text-sm text-ink-soft" data-testid="legacy-consent-off">
                <IconInfo size={16} className="mt-0.5 shrink-0" />
                <span>Bạn không còn đồng ý công khai{consent.withdrawn_at ? ` (đã rút lúc ${formatDateTime(consent.withdrawn_at)})` : ""}. Chỉ giáo viên mới đồng ý lại được.</span>
              </p>
            )}
          </section>
        </div>
        <aside aria-label="Trạng thái" className="xl:sticky xl:top-6 xl:self-start">
          <ProfileStatusCard profile={profile} />
        </aside>
      </div>

      <ConfirmDialog
        open={confirm === "consent"}
        onClose={() => !busy && setConfirm(null)}
        onConfirm={() => void run()}
        loading={busy}
        loadingText="Đang rút…"
        tone="danger"
        title="Rút đồng ý công khai?"
        description="Trong tối đa 1 phút, ảnh và phần giới thiệu của bạn sẽ không còn hiện trên website. Bạn không đồng ý lại được vì không còn là giáo viên."
        confirmLabel="Rút đồng ý"
      />
      <ConfirmDialog
        open={confirm === "avatar"}
        onClose={() => !busy && setConfirm(null)}
        onConfirm={() => void run()}
        loading={busy}
        loadingText="Đang xoá…"
        tone="danger"
        title="Xoá ảnh hồ sơ?"
        description="Ảnh sẽ bị xoá vĩnh viễn và không khôi phục được."
        confirmLabel="Xoá ảnh"
      />
    </div>
  );
}

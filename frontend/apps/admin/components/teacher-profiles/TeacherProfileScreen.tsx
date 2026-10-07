"use client";

import { useCallback, useEffect, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import {
  Alert,
  Breadcrumb,
  Button,
  ButtonLink,
  Checkbox,
  ConfirmDialog,
  EmptyState,
  Field,
  IconCheckCircle,
  IconImage,
  IconInfo,
  IconSearch,
  IconTrash,
  LoadingRegion,
  Skeleton,
  TeacherCard,
  TextInput,
  Textarea,
  formatDateTime,
  useToast,
} from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { checkImageDimensions, checkImageFile, THUMBNAIL_ACCEPT } from "@/lib/courses/image";
import { deleteAvatar, getProfile, giveConsent, patchProfile, uploadAvatar, withdrawConsent } from "@/lib/teacher-profiles/api";
import {
  classifyProfileError,
  isConsentVersionChanged,
  isForbidden,
  isNotFound,
  profileActionError,
} from "@/lib/teacher-profiles/errors";
import { buildContentPatch, fieldValue, normalizeText, pruneDraft, validateDraft, type ContentField, type Draft, type FieldErrors } from "@/lib/teacher-profiles/form";
import { HOMEPAGE_TEACHERS_PATH } from "@/lib/teacher-profiles/query";
import { BIO_MAX, HEADLINE_MAX, type ProfileTarget, type TeacherProfile } from "@/lib/teacher-profiles/types";
import { AvatarCropDialog } from "./AvatarCropDialog";
import { ProfileStatusCard } from "./ProfileStatusCard";

type Loaded = { profile: TeacherProfile | null; error: unknown };

function readDataUrl(file: Blob): Promise<string | null> {
  return new Promise((resolve) => {
    const reader = new FileReader();
    reader.onerror = () => resolve(null);
    reader.onload = () => resolve(typeof reader.result === "string" ? reader.result : null);
    reader.readAsDataURL(file);
  });
}

function readDimensions(url: string): Promise<{ width: number; height: number } | null> {
  return new Promise((resolve) => {
    const img = new Image();
    img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
    img.onerror = () => resolve(null);
    img.src = url;
  });
}

/**
 * Hồ sơ giáo viên công khai (US-020, FA11), hình thức theo bản xem trước `/v2/quan-tri/ho-so` và `/giao-vien/[id]`.
 * - `mode="self"`: "Hồ sơ của tôi" — giáo viên sửa ảnh/chuyên môn/giới thiệu, tự tick đồng ý hoặc rút đồng ý (BR4).
 * - `mode="admin"`: Admin/QLT sửa hộ nội dung; ô đồng ý chỉ đọc kèm chữ giải thích, không có API đồng ý thay (BR6, AC8).
 * PATCH chỉ gửi trường đã đổi (AC21). Ảnh qua upload multipart riêng sau bước cắt vuông (BR7).
 */
export function TeacherProfileScreen({ target, mode }: { target: ProfileTarget; mode: "self" | "admin" }) {
  const toast = useToast();
  const [reloadKey, setReloadKey] = useState(0);
  const [loaded, setLoaded] = useState<(Loaded & { key: number }) | null>(null);
  const [profile, setProfile] = useState<TeacherProfile | null>(null);
  const [draft, setDraft] = useState<Draft>({});
  const [pendingAvatar, setPendingAvatar] = useState<{ blob: Blob; url: string } | null>(null);
  const [removeAvatar, setRemoveAvatar] = useState(false);
  const [avatarBroken, setAvatarBroken] = useState(false);
  const [cropSrc, setCropSrc] = useState<string | null>(null);
  const [avatarPickError, setAvatarPickError] = useState<string | null>(null);
  const [tick, setTick] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [banner, setBanner] = useState<{ tone: "danger" | "info"; text: string } | null>(null);
  const [consentChanged, setConsentChanged] = useState(false);
  const [saving, setSaving] = useState(false);
  const [withdrawOpen, setWithdrawOpen] = useState(false);
  const [withdrawing, setWithdrawing] = useState(false);
  const pendingRef = useRef(false);
  const formRef = useRef<HTMLFormElement>(null);
  const targetKey = target.kind === "me" ? "me" : String(target.id);

  useEffect(() => {
    const controller = new AbortController();
    getProfile(target, controller.signal)
      .then((p) => {
        setLoaded({ key: reloadKey, profile: p, error: null });
        setProfile(p);
      })
      .catch((error: unknown) => {
        if (!controller.signal.aborted) setLoaded({ key: reloadKey, profile: null, error });
      });
    return () => controller.abort();
    // `target` được xác định bởi `targetKey`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [targetKey, reloadKey]);

  const reload = useCallback(() => setReloadKey((n) => n + 1), []);

  const patch = profile ? buildContentPatch(profile, draft) : null;
  const dirty = Boolean(patch) || pendingAvatar !== null || removeAvatar || tick;

  // Cảnh báo khi rời trang với dữ liệu chưa lưu (cùng quy ước form khóa học).
  useEffect(() => {
    if (!dirty) return;
    const handler = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [dirty]);

  function focusFirstInvalid() {
    setTimeout(() => {
      const el = formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"],[data-invalid="true"]');
      if (!el) return;
      const focusable = el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement ? el : el.querySelector<HTMLElement>("input,button");
      focusable?.focus();
    }, 0);
  }

  if (loaded === null || (loaded.key !== reloadKey && !profile)) {
    return (
      <LoadingRegion className="flex flex-col gap-4">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-96 w-full" />
      </LoadingRegion>
    );
  }
  if (!profile) {
    const error = loaded.error;
    if (isForbidden(error)) return <ForbiddenView />;
    if (isNotFound(error)) {
      return (
        <div data-testid="profile-not-found" className="mx-auto max-w-xl py-8">
          <EmptyState
            headingLevel="h1"
            icon={<IconSearch size={32} />}
            title="Không tìm thấy hồ sơ giáo viên"
            description="Tài khoản có thể không còn là giáo viên hoặc đã bị xoá."
            action={<ButtonLink href={HOMEPAGE_TEACHERS_PATH}>Về danh sách giáo viên</ButtonLink>}
          />
        </div>
      );
    }
    return (
      <Alert
        tone="danger"
        title="Không tải được hồ sơ"
        action={
          <Button size="sm" variant="secondary" onClick={reload}>
            Thử lại
          </Button>
        }
      >
        {profileActionError(error)}
      </Alert>
    );
  }

  const p = profile;
  const canEdit = p.abilities.edit_content;
  const isSelf = mode === "self";
  const headlineValue = fieldValue(p, draft, "headline");
  const bioValue = fieldValue(p, draft, "bio");
  const consent = p.consent;
  const needsReconsent = consent.given && consent.version !== consent.current_version;
  const avatarUrl = removeAvatar ? null : (pendingAvatar?.url ?? p.avatar_url);
  const showAvatar = avatarUrl !== null && !(avatarUrl === p.avatar_url && avatarBroken);
  const editedByOther = p.last_edited_by && !p.last_edited_by.is_self && p.last_edited_at;
  const avatarError = avatarPickError ?? errors.avatar;

  const setField = (field: ContentField, value: string) => {
    setDraft((d) => ({ ...d, [field]: value }));
    setErrors((e) => (e[field] ? { ...e, [field]: undefined } : e));
  };

  async function onFile(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    setAvatarPickError(null);
    setErrors((er) => ({ ...er, avatar: undefined }));
    const basic = await checkImageFile(file);
    if (basic) return setAvatarPickError(basic);
    const url = await readDataUrl(file);
    const dims = url ? await readDimensions(url) : null;
    if (!url || !dims) return setAvatarPickError("Không đọc được ảnh. Vui lòng chọn ảnh JPG/PNG/WebP khác.");
    const dimError = checkImageDimensions(dims.width, dims.height);
    if (dimError) return setAvatarPickError(dimError);
    setCropSrc(url);
  }

  async function onCropped(blob: Blob) {
    const url = await readDataUrl(blob);
    setCropSrc(null);
    if (!url) return setAvatarPickError("Không đọc được ảnh đã cắt. Vui lòng thử lại.");
    setPendingAvatar({ blob, url });
    setRemoveAvatar(false);
    toast.show({ tone: "info", title: "Đã chọn ảnh", description: "Bấm “Lưu hồ sơ” để cập nhật." });
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (pendingRef.current) return;
    const invalid = validateDraft(draft);
    setErrors(invalid);
    setBanner(null);
    setConsentChanged(false);
    if (Object.keys(invalid).length > 0) {
      focusFirstInvalid();
      return;
    }
    if (!dirty) {
      setBanner({ tone: "info", text: "Chưa có thay đổi nào để lưu." });
      return;
    }

    pendingRef.current = true;
    setSaving(true);
    let cur = p;
    let avatarDone = false;
    let sent: ContentField[] = [];
    let consentDone = false;
    let gone = false;
    try {
      // 1) ảnh  2) nội dung (chỉ trường đã đổi)  3) đồng ý. Dừng ở bước lỗi, bước đã xong vẫn được giữ.
      if (pendingAvatar && canEdit) {
        cur = await uploadAvatar(target, pendingAvatar.blob);
        avatarDone = true;
      } else if (removeAvatar) {
        cur = await deleteAvatar(target);
        avatarDone = true;
      }
      if (patch && canEdit) {
        cur = await patchProfile(target, patch);
        sent = Object.keys(patch) as ContentField[];
      }
      if (isSelf && tick) {
        cur = await giveConsent(consent.current_version);
        consentDone = true;
      }
      toast.show({ tone: "success", title: "Đã lưu hồ sơ" });
    } catch (err) {
      if (isConsentVersionChanged(err)) {
        // Câu đồng ý đã đổi trong lúc bạn đọc: tải lại để hiện câu mới, bỏ tick cũ.
        try {
          cur = await getProfile(target);
        } catch {
          /* giữ cur */
        }
        setConsentChanged(true);
        setTick(false);
      } else {
        const failure = classifyProfileError(err, { hadFile: pendingAvatar !== null && !avatarDone });
        setErrors(failure.fields);
        setBanner(failure.banner ? { tone: "danger", text: failure.banner } : null);
        if (failure.gone) {
          gone = true;
          setLoaded({ key: reloadKey, profile: null, error: err });
        }
        focusFirstInvalid();
      }
    } finally {
      pendingRef.current = false;
      setSaving(false);
      setProfile(gone ? null : cur);
      if (avatarDone) {
        setPendingAvatar(null);
        setRemoveAvatar(false);
        setAvatarBroken(false);
      }
      if (consentDone) setTick(false);
      setDraft((d) => pruneDraft(cur, d, sent));
    }
  }

  async function doWithdraw() {
    setWithdrawing(true);
    try {
      const next = await withdrawConsent();
      setProfile(next);
      setTick(false);
      setBanner(null);
      toast.show({ tone: "success", title: "Đã rút đồng ý công khai", description: "Ảnh và giới thiệu sẽ ẩn khỏi website trong tối đa 1 phút." });
    } catch (err) {
      setBanner({ tone: "danger", text: classifyProfileError(err).banner ?? profileActionError(err) });
    } finally {
      setWithdrawing(false);
      setWithdrawOpen(false);
    }
  }

  const title = isSelf ? "Hồ sơ của tôi" : `Hồ sơ công khai — ${p.user.name}`;
  const cardTeacher = {
    id: p.user.id,
    name: p.user.name,
    avatar_url: null,
    headline: normalizeText(headlineValue) || null,
    // API hồ sơ không trả lớp dạy: để rỗng thì thẻ ẩn dòng lớp (không đoán).
    grade_levels: [],
    published_courses_count: p.published_courses_count,
    bio: normalizeText(bioValue) || null,
  };

  return (
    <div className="flex flex-col">
      {!isSelf ? <Breadcrumb items={[{ label: "Giáo viên trang chủ", href: HOMEPAGE_TEACHERS_PATH }, { label: p.user.name }]} /> : null}
      <h1 className="mt-3 break-words text-title font-extrabold tracking-heading text-ink">{title}</h1>
      <p className="mt-1 max-w-3xl text-sm text-ink-soft">
        {isSelf
          ? "Ảnh và phần giới thiệu giúp học sinh, phụ huynh biết ai dạy khóa học. Chỉ hiện trên website khi bạn đồng ý công khai."
          : "Bạn đang sửa hộ giáo viên. Giáo viên sẽ thấy “Chỉnh sửa gần nhất bởi” bạn trong “Hồ sơ của tôi”."}
      </p>

      <form ref={formRef} onSubmit={onSubmit} noValidate className="mt-6 grid gap-6 xl:grid-cols-[1fr_380px]" aria-label="Hồ sơ giáo viên công khai">
        <div className="flex min-w-0 flex-col gap-6">
          {banner ? (
            <Alert tone={banner.tone} title={banner.text} role={banner.tone === "danger" ? "alert" : "status"} />
          ) : null}
          {consentChanged ? (
            <Alert tone="warning" title="Câu đồng ý đã được cập nhật" role="alert">
              Vui lòng đọc câu mới ở mục “Đồng ý công khai” rồi tick lại nếu bạn đồng ý. Phần còn lại của hồ sơ đã được lưu.
            </Alert>
          ) : null}
          {editedByOther ? (
            <Alert tone="info" title={`Chỉnh sửa gần nhất bởi ${p.last_edited_by?.name}, ${formatDateTime(p.last_edited_at as string)}`}>
              {isSelf
                ? "Nội dung do Admin/Quản lý trang sửa đã có hiệu lực. Nếu không đồng ý với nội dung này, bạn có thể sửa lại hoặc rút đồng ý công khai."
                : "Nội dung này được sửa gần nhất bởi người khác với giáo viên."}
            </Alert>
          ) : null}
          {!canEdit ? (
            <Alert tone="warning" title="Không sửa được nội dung" role="status">
              Tài khoản này không còn là giáo viên nên chỉ xoá ảnh được. Các ô bên dưới bị khoá.
            </Alert>
          ) : null}

          <section aria-labelledby="anh" className="flex flex-col gap-4 rounded-card border border-line bg-surface p-5">
            <h2 id="anh" className="text-base font-semibold text-ink">
              Ảnh đại diện
            </h2>
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center" data-invalid={avatarError ? "true" : undefined}>
              <div className="relative size-32 shrink-0 overflow-hidden rounded-card border border-line">
                {showAvatar ? (
                  // eslint-disable-next-line @next/next/no-img-element -- ảnh tĩnh từ STATIC_URL hoặc data: URL xem trước
                  <img
                    src={avatarUrl as string}
                    alt={`Ảnh thầy/cô ${p.user.name}`}
                    className="size-full object-cover"
                    onError={() => avatarUrl === p.avatar_url && setAvatarBroken(true)}
                  />
                ) : (
                  <div className="flex size-full flex-col items-center justify-center gap-1 bg-sunken text-sm text-ink-soft">
                    <IconImage />
                    {p.avatar_url && avatarBroken && !removeAvatar ? "Không tải được ảnh" : "Chưa có ảnh"}
                  </div>
                )}
              </div>
              <div className="flex flex-col gap-2">
                <div className="flex flex-wrap gap-2">
                  {canEdit ? (
                    <label className="focus-ring inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-line-strong bg-surface px-3 text-sm font-semibold text-ink hover:border-primary hover:text-primary has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-focus sm:min-h-9">
                      <IconImage size={16} />
                      {avatarUrl !== null ? "Đổi ảnh" : "Chọn ảnh"}
                      <input type="file" name="avatar" accept={THUMBNAIL_ACCEPT} className="sr-only" disabled={saving} aria-invalid={avatarError ? true : undefined} aria-describedby="avatar-hint" onChange={(e) => void onFile(e)} />
                    </label>
                  ) : null}
                  {avatarUrl !== null ? (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="max-sm:h-11"
                      leadingIcon={<IconTrash size={16} />}
                      disabled={saving}
                      onClick={() => {
                        setAvatarPickError(null);
                        if (pendingAvatar) setPendingAvatar(null);
                        else setRemoveAvatar(true);
                      }}
                    >
                      {pendingAvatar ? "Bỏ ảnh vừa chọn" : "Xoá ảnh"}
                    </Button>
                  ) : null}
                  {removeAvatar ? (
                    <Button type="button" variant="ghost" size="sm" className="max-sm:h-11" disabled={saving} onClick={() => setRemoveAvatar(false)}>
                      Giữ ảnh hiện tại
                    </Button>
                  ) : null}
                </div>
                <p id="avatar-hint" className="text-sm text-ink-soft">
                  JPG, PNG hoặc WebP, tối đa 2 MB, không quá 4000×4000 px. Ảnh chân dung rõ mặt, nền gọn; sẽ được cắt vuông.
                  {removeAvatar ? " Ảnh sẽ bị xoá khi bạn bấm “Lưu hồ sơ”." : ""}
                </p>
                {avatarError ? (
                  <p role="alert" className="flex items-start gap-1.5 text-sm font-medium text-danger" data-testid="avatar-error">
                    {avatarError}
                  </p>
                ) : null}
              </div>
            </div>
          </section>

          <section aria-labelledby="gioi-thieu" className="flex flex-col gap-5 rounded-card border border-line bg-surface p-5">
            <h2 id="gioi-thieu" className="text-base font-semibold text-ink">
              Giới thiệu
            </h2>
            <Field
              label="Chuyên môn (một dòng)"
              hint="Ví dụ: Giáo viên Toán THCS, chuyên hình học thi vào 10. Không bắt buộc."
              error={errors.headline}
              aside={<span className="num text-ink-soft" aria-live="polite">{[...headlineValue].length}/{HEADLINE_MAX}</span>}
            >
              <TextInput size="sm" className="max-sm:h-11" name="headline" value={headlineValue} disabled={!canEdit || saving} autoComplete="off" onChange={(e) => setField("headline", e.target.value)} />
            </Field>
            <Field
              label="Giới thiệu bản thân"
              hint="Văn bản thuần, giữ xuống dòng, không dùng định dạng. Trang chủ hiện 3 dòng đầu. Thành tích (nếu có) bạn tự chịu trách nhiệm về tính đúng."
              error={errors.bio}
              aside={<span className="num text-ink-soft" aria-live="polite">{[...bioValue].length}/{BIO_MAX}</span>}
            >
              <Textarea name="bio" rows={6} value={bioValue} disabled={!canEdit || saving} className="text-sm" onChange={(e) => setField("bio", e.target.value)} />
            </Field>
          </section>

          <section aria-labelledby="dong-y" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
            <h2 id="dong-y" className="text-base font-semibold text-ink">
              Đồng ý công khai
            </h2>
            {!isSelf ? (
              <>
                <Checkbox id="consent-admin" label={consent.current_text} checked={consent.given} disabled readOnly />
                <p className="flex items-start gap-2 text-sm text-ink-soft" data-testid="consent-readonly-note">
                  <IconInfo size={16} className="mt-0.5 shrink-0" />
                  <span>
                    Chỉ giáo viên được đồng ý công khai. Bạn sửa được nội dung hồ sơ nhưng không đồng ý thay.
                    {consent.given && consent.given_at
                      ? ` Giáo viên đã đồng ý lúc ${formatDateTime(consent.given_at)}.`
                      : " Giáo viên chưa đồng ý: ảnh và giới thiệu chưa hiện ở bất kỳ trang công khai nào."}
                  </span>
                </p>
              </>
            ) : consent.given && !needsReconsent ? (
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <p className="flex flex-1 items-start gap-2 text-base text-ink" data-testid="consent-given">
                  <IconCheckCircle className="mt-0.5 shrink-0 text-success" />
                  <span>
                    Bạn đã đồng ý công khai{consent.given_at ? ` lúc ${formatDateTime(consent.given_at)}` : ""}{" "}
                    <span className="text-sm text-ink-soft">(phiên bản {consent.version})</span>.
                  </span>
                </p>
                <Button type="button" variant="secondary" size="sm" className="max-sm:h-11" disabled={saving} onClick={() => setWithdrawOpen(true)}>
                  Rút đồng ý
                </Button>
              </div>
            ) : (
              <>
                {needsReconsent ? (
                  <Alert tone="info" title="Câu đồng ý đã được cập nhật">
                    Bạn đã đồng ý bản cũ. Tick lại để đồng ý nội dung mới, hoặc rút đồng ý nếu không đồng ý.
                  </Alert>
                ) : null}
                <Checkbox
                  id="consent-self"
                  name="consent"
                  label={consent.current_text}
                  description={`Phiên bản nội dung: ${consent.current_version}. Bạn có thể rút đồng ý bất kỳ lúc nào.`}
                  checked={tick}
                  disabled={saving}
                  onChange={(e) => setTick(e.target.checked)}
                />
                {!consent.given ? (
                  <p className="text-sm text-ink-soft">Khi chưa đồng ý, website chỉ hiện họ tên của bạn ở trang khóa học; ảnh và giới thiệu không hiện ở đâu.</p>
                ) : (
                  <div>
                    <Button type="button" variant="secondary" size="sm" className="max-sm:h-11" disabled={saving} onClick={() => setWithdrawOpen(true)}>
                      Rút đồng ý
                    </Button>
                  </div>
                )}
                {consent.withdrawn_at && !consent.given ? <p className="text-sm text-ink-soft">Bạn đã rút đồng ý lúc {formatDateTime(consent.withdrawn_at)}.</p> : null}
              </>
            )}
          </section>

          <div className="sticky bottom-0 z-10 -mx-4 flex justify-end gap-3 border-t border-line bg-surface px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <Button
              type="button"
              variant="secondary"
              size="sm"
              className="max-sm:h-11"
              disabled={saving || !dirty}
              onClick={() => {
                setDraft({});
                setPendingAvatar(null);
                setRemoveAvatar(false);
                setTick(false);
                setErrors({});
                setBanner(null);
                setAvatarPickError(null);
              }}
            >
              Huỷ thay đổi
            </Button>
            <Button type="submit" size="sm" className="max-sm:h-11" loading={saving} loadingText="Đang lưu…">
              Lưu hồ sơ
            </Button>
          </div>
        </div>

        <aside aria-label="Trạng thái và xem trước" className="flex flex-col gap-4 xl:sticky xl:top-6 xl:self-start">
          <ProfileStatusCard profile={p} />
          {!isSelf ? (
            <p className="text-sm text-ink-soft">
              Bật/tắt hiển thị và thứ tự ở{" "}
              <a href={HOMEPAGE_TEACHERS_PATH} className="focus-ring rounded font-semibold text-primary underline">
                danh sách giáo viên trang chủ
              </a>
              .
            </p>
          ) : null}
          <section className="flex flex-col gap-2" aria-labelledby="xem-truoc-the">
            <h2 id="xem-truoc-the" className="text-sm font-semibold text-ink-soft">
              Xem trước thẻ trên trang chủ
            </h2>
            <TeacherCard
              teacher={cardTeacher}
              image={
                showAvatar ? (
                  // eslint-disable-next-line @next/next/no-img-element -- xem trước từ STATIC_URL/data: URL
                  <img src={avatarUrl as string} alt={`Ảnh thầy/cô ${p.user.name}`} className="size-full object-cover" />
                ) : undefined
              }
            />
          </section>
        </aside>
      </form>

      {cropSrc ? <AvatarCropDialog src={cropSrc} onClose={() => setCropSrc(null)} onDone={(b) => void onCropped(b)} /> : null}
      <ConfirmDialog
        open={withdrawOpen}
        onClose={() => !withdrawing && setWithdrawOpen(false)}
        onConfirm={() => void doWithdraw()}
        loading={withdrawing}
        loadingText="Đang rút…"
        tone="danger"
        title="Rút đồng ý công khai?"
        description="Trong tối đa 1 phút, ảnh và phần giới thiệu của bạn sẽ không còn hiện ở trang chủ và trang khóa học. Hồ sơ vẫn được giữ, bạn có thể đồng ý lại."
        confirmLabel="Rút đồng ý"
      />
    </div>
  );
}


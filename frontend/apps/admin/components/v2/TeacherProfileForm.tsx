"use client";

import { useState, type ChangeEvent } from "react";
import {
  Alert,
  Badge,
  Button,
  Checkbox,
  ConfirmDialog,
  Field,
  IconCheckCircle,
  IconCircle,
  IconImage,
  IconInfo,
  IconTrash,
  TeacherCard,
  TextInput,
  Textarea,
  formatDateTime,
  useToast,
} from "@vitaminvui/ui/v2";
import { CONSENT_TEXT, CONSENT_VERSION, homepageConditions, type TeacherProfile } from "@/lib/mock/v2/teacher-profiles";
import { AvatarCropper } from "./AvatarCropper";

const SAMPLE = "/v2-preview/chan-dung-mau.svg";

/**
 * Hồ sơ giáo viên công khai (US-020).
 * - `mode="self"`: "Hồ sơ của tôi" — giáo viên sửa ảnh/headline/bio, tự tick đồng ý hoặc rút đồng ý (BR4).
 * - `mode="admin"`: Admin/QLT sửa hộ nội dung; ô đồng ý bị khoá kèm chữ giải thích (BR6, AC8).
 * Cột phải: xem trước thẻ trang chủ theo nội dung đang nhập + checklist điều kiện hiển thị (BR2, AC1).
 * TODO(dev): PUT chỉ gửi trường đã đổi (AC21); ảnh qua upload riêng (ImageUploadService); đồng ý/rút là API riêng.
 */
export function TeacherProfileForm({
  profile,
  mode,
  startWithCropper = false,
}: {
  profile: TeacherProfile;
  mode: "self" | "admin";
  startWithCropper?: boolean;
}) {
  const toast = useToast();
  const [headline, setHeadline] = useState(profile.headline ?? "");
  const [bio, setBio] = useState(profile.bio ?? "");
  const [hasAvatar, setHasAvatar] = useState(profile.avatar_url !== null);
  const [consentAt, setConsentAt] = useState(profile.public_profile_consent_at);
  const [tick, setTick] = useState(false);
  const [cropSrc, setCropSrc] = useState<string | null>(startWithCropper ? SAMPLE : null);
  const [withdrawOpen, setWithdrawOpen] = useState(false);
  const [saving, setSaving] = useState(false);

  const live: TeacherProfile = { ...profile, headline: headline || null, bio: bio || null, avatar_url: hasAvatar ? profile.avatar_url ?? "mới" : null, public_profile_consent_at: consentAt };
  const conditions = homepageConditions(live);
  const shown = conditions.every((c) => c.ok);
  const editedByOther = profile.profile_updated_by && profile.profile_updated_by.id !== profile.id;

  function onFile(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type) || file.size > 2 * 1024 * 1024) {
      toast.show({ tone: "danger", title: "Ảnh không hợp lệ", description: "Chỉ nhận JPG, PNG hoặc WebP, tối đa 2MB. Không nhận SVG/GIF." });
      return;
    }
    const reader = new FileReader();
    reader.onload = () => setCropSrc(String(reader.result));
    reader.readAsDataURL(file);
  }

  function save() {
    setSaving(true);
    setTimeout(() => {
      setSaving(false);
      if (tick) {
        setConsentAt(new Date().toISOString());
        setTick(false);
      }
      toast.show({ tone: "success", title: "Đã lưu hồ sơ" });
    }, 800);
  }

  return (
    <div className="grid gap-6 xl:grid-cols-[1fr_380px]">
      <div className="flex min-w-0 flex-col gap-6">
        {editedByOther && mode === "self" && profile.profile_updated_at ? (
          <Alert tone="info" title={`Chỉnh sửa gần nhất bởi ${profile.profile_updated_by?.name}, ${formatDateTime(profile.profile_updated_at)}`}>
            Nội dung do Admin/Quản lý trang sửa đã có hiệu lực. Nếu không đồng ý với nội dung này, bạn có thể sửa lại hoặc rút đồng ý công khai.
          </Alert>
        ) : null}

        <section aria-labelledby="anh" className="flex flex-col gap-4 rounded-card border border-line bg-surface p-5">
          <h2 id="anh" className="text-base font-semibold text-ink">
            Ảnh đại diện
          </h2>
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
            <div className="relative size-32 shrink-0 overflow-hidden rounded-card border border-line">
              {hasAvatar ? (
                <div className="bg-oly flex size-full items-center justify-center bg-primary-soft text-sm font-semibold text-primary" aria-label={`Ảnh thầy/cô ${profile.name}`} role="img">
                  Ảnh hiện tại
                </div>
              ) : (
                <div className="flex size-full flex-col items-center justify-center gap-1 bg-sunken text-sm text-ink-soft">
                  <IconImage />
                  Chưa có ảnh
                </div>
              )}
            </div>
            <div className="flex flex-col gap-2">
              <div className="flex flex-wrap gap-2">
                <label className="focus-ring inline-flex h-9 cursor-pointer items-center gap-2 rounded-control border border-line-strong bg-surface px-3 text-sm font-semibold text-ink hover:border-primary hover:text-primary has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-focus">
                  <IconImage size={16} />
                  {hasAvatar ? "Đổi ảnh" : "Chọn ảnh"}
                  <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={onFile} />
                </label>
                {hasAvatar ? (
                  <Button variant="ghost" size="sm" leadingIcon={<IconTrash size={16} />} onClick={() => setHasAvatar(false)}>
                    Xoá ảnh
                  </Button>
                ) : null}
              </div>
              <p className="text-sm text-ink-soft">JPG, PNG hoặc WebP, tối đa 2MB. Ảnh chân dung rõ mặt, nền gọn; sẽ được cắt vuông.</p>
            </div>
          </div>
        </section>

        <section aria-labelledby="gioi-thieu" className="flex flex-col gap-5 rounded-card border border-line bg-surface p-5">
          <h2 id="gioi-thieu" className="text-base font-semibold text-ink">
            Giới thiệu
          </h2>
          <Field label="Chuyên môn (một dòng)" hint="Ví dụ: Giáo viên Toán THCS, chuyên hình học thi vào 10. Không bắt buộc." aside={<span className="num text-ink-soft">{headline.length}/120</span>}>
            <TextInput size="sm" value={headline} maxLength={120} onChange={(e) => setHeadline(e.target.value)} />
          </Field>
          <Field
            label="Giới thiệu bản thân"
            hint="Văn bản thuần, giữ xuống dòng. Trang chủ hiện 3 dòng đầu. Thành tích (nếu có) bạn tự chịu trách nhiệm về tính đúng."
            aside={<span className="num text-ink-soft">{bio.length}/600</span>}
          >
            <Textarea rows={6} value={bio} maxLength={600} onChange={(e) => setBio(e.target.value)} className="text-sm" />
          </Field>
        </section>

        <section aria-labelledby="dong-y" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
          <h2 id="dong-y" className="text-base font-semibold text-ink">
            Đồng ý công khai
          </h2>
          {mode === "admin" ? (
            <>
              <Checkbox id="consent-admin" label={CONSENT_TEXT} checked={consentAt !== null} disabled readOnly />
              <p className="flex items-start gap-2 text-sm text-ink-soft">
                <IconInfo size={16} className="mt-0.5 shrink-0" />
                Chỉ giáo viên được đồng ý công khai. Bạn sửa được nội dung hồ sơ nhưng không đồng ý thay.
                {consentAt ? ` Giáo viên đã đồng ý lúc ${formatDateTime(consentAt)}.` : " Giáo viên chưa đồng ý: ảnh và giới thiệu chưa hiện ở bất kỳ trang công khai nào."}
              </p>
            </>
          ) : consentAt ? (
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
              <p className="flex flex-1 items-start gap-2 text-base text-ink">
                <IconCheckCircle className="mt-0.5 shrink-0 text-success" />
                <span>
                  Bạn đã đồng ý công khai lúc {formatDateTime(consentAt)} <span className="text-sm text-ink-soft">(phiên bản {profile.public_profile_consent_version ?? CONSENT_VERSION})</span>.
                </span>
              </p>
              <Button variant="secondary" size="sm" onClick={() => setWithdrawOpen(true)}>
                Rút đồng ý
              </Button>
            </div>
          ) : (
            <>
              <Checkbox id="consent-self" label={CONSENT_TEXT} description={`Phiên bản nội dung: ${CONSENT_VERSION}. Bạn có thể rút đồng ý bất kỳ lúc nào.`} checked={tick} onChange={(e) => setTick(e.target.checked)} />
              <p className="text-sm text-ink-soft">Khi chưa đồng ý, website chỉ hiện họ tên của bạn ở trang khóa học; ảnh và giới thiệu không hiện ở đâu.</p>
            </>
          )}
        </section>

        <div className="sticky bottom-0 z-10 -mx-4 flex justify-end gap-3 border-t border-line bg-surface px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
          <Button size="sm" onClick={save} loading={saving} loadingText="Đang lưu…">
            Lưu hồ sơ
          </Button>
        </div>
      </div>

      <aside aria-labelledby="xem-truoc-the" className="flex flex-col gap-4 xl:sticky xl:top-6 xl:self-start">
        <section className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-base font-semibold text-ink">Trang chủ</h2>
            {shown ? (
              <Badge tone="success" dot size="sm">
                Đang hiển thị
              </Badge>
            ) : (
              <Badge tone="warning" dot size="sm">
                Chưa hiển thị
              </Badge>
            )}
          </div>
          <ul className="flex flex-col gap-1.5">
            {conditions.map((c) => (
              <li key={c.key} className="flex items-center gap-2 text-sm">
                {c.ok ? <IconCheckCircle size={18} className="text-success" /> : <IconCircle size={18} className="text-line-strong" />}
                <span className={c.ok ? "text-ink" : "font-semibold text-ink"}>{c.label}</span>
                <span className="sr-only">{c.ok ? ": đạt" : ": chưa đạt"}</span>
              </li>
            ))}
          </ul>
          {!shown ? <p className="text-sm text-ink-soft">Thiếu một điều kiện thì không hiện, học sinh không thấy thông báo lỗi nào.</p> : null}
        </section>
        <section className="flex flex-col gap-2">
          <h2 id="xem-truoc-the" className="text-sm font-semibold text-ink-soft">
            Xem trước thẻ trên trang chủ
          </h2>
          <TeacherCard
            teacher={{
              id: profile.id,
              name: profile.name,
              avatar_url: null,
              headline: headline || null,
              grade_levels: profile.grade_levels,
              published_courses_count: profile.published_courses_count,
              bio: bio || null,
            }}
            coursesHref="#"
          />
        </section>
      </aside>

      <AvatarCropper
        open={cropSrc !== null}
        src={cropSrc}
        onClose={() => setCropSrc(null)}
        onDone={() => {
          setCropSrc(null);
          setHasAvatar(true);
          toast.show({ tone: "info", title: "Đã chọn ảnh", description: "Bấm “Lưu hồ sơ” để cập nhật." });
        }}
      />
      <ConfirmDialog
        open={withdrawOpen}
        onClose={() => setWithdrawOpen(false)}
        onConfirm={() => {
          setWithdrawOpen(false);
          setConsentAt(null);
          toast.show({ tone: "success", title: "Đã rút đồng ý công khai", description: "Ảnh và giới thiệu sẽ ẩn khỏi website trong tối đa 1 phút." });
        }}
        tone="danger"
        title="Rút đồng ý công khai?"
        description="Trong tối đa 1 phút, ảnh và phần giới thiệu của bạn sẽ không còn hiện ở trang chủ và trang khóa học. Hồ sơ vẫn được giữ, bạn có thể đồng ý lại."
        confirmLabel="Rút đồng ý"
      />
    </div>
  );
}

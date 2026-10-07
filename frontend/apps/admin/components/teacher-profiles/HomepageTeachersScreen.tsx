"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import {
  Alert,
  Avatar,
  Badge,
  Button,
  ButtonLink,
  Checkbox,
  EmptyState,
  IconChevronDown,
  IconChevronUp,
  IconPencil,
  IconRotateCcw,
  IconSearch,
  IconUsers,
  IconButton,
  LoadingRegion,
  Pagination,
  Select,
  Skeleton,
  Switch,
  TextInput,
} from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { listProfiles, patchHomepage } from "@/lib/teacher-profiles/api";
import { isForbidden, profileActionError } from "@/lib/teacher-profiles/errors";
import { HOMEPAGE_TEACHERS_PATH, parseProfileQuery, profileQueryToSearch } from "@/lib/teacher-profiles/query";
import { reasonText } from "@/lib/teacher-profiles/reasons";
import { HOMEPAGE_MAX_DEFAULT, PER_PAGE_OPTIONS, type ProfileListQuery, type TeacherProfile, type TeacherProfilePage } from "@/lib/teacher-profiles/types";
import { useSession } from "@/lib/auth/SessionProvider";

/** Thứ tự cuối thật sự: lớn hơn mọi thứ tự đang có (tắt người giữa chừng để lại khoảng trống), tối đa 999. */
export function nextOrder(enabled: ReadonlyArray<Pick<TeacherProfile, "homepage_order">>): number {
  return Math.min(999, Math.max(0, ...enabled.map((t) => t.homepage_order ?? 0)) + 1);
}

type LoadResult = { key: string; page: TeacherProfilePage | null; error: unknown };

const SEARCH_DEBOUNCE_MS = 300;

/**
 * Admin/QLT: giáo viên hiện ở trang chủ (US-020 BR3, AC9–AC11), hình thức theo `/v2/quan-tri/giao-vien`.
 * - Bật người thứ 7: server trả 409 `TEACHER_HOMEPAGE_LIMIT`, hiện đúng thông điệp, trạng thái cũ giữ nguyên.
 * - Bật người chưa đủ điều kiện vẫn được, nhưng nhãn "Chưa hiện: lý do" (không hiển thị giả).
 * - Thứ tự = thứ tự danh sách "Đang bật"; nút Lên/Xuống (bàn phím dùng được, không cần kéo-thả), đổi bằng PATCH `/homepage`.
 * - Không có thao tác đồng ý thay giáo viên; đồng ý chỉ để xem.
 * Bộ lọc/trang trên URL. Người bị khoá hoặc đã đổi vai trò vẫn hiện để tắt được.
 */
export function HomepageTeachersScreen() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { state } = useSession();
  const query = useMemo(() => parseProfileQuery(searchParams), [searchParams]);
  const apiKey = profileQueryToSearch(query);
  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<LoadResult | null>(null);
  const [qInput, setQInput] = useState(query.q);
  const committedQ = useRef(query.q);
  const [busy, setBusy] = useState<number | "order" | null>(null);
  const busyRef = useRef(false);
  const [error, setError] = useState<string | null>(null);

  const ready = state.kind === "staff";
  const requestKey = `${apiKey}#${reloadKey}`;
  const loading = result === null || result.key !== requestKey;
  const page = result?.page ?? null;
  const loadError = !loading && result ? result.error : null;

  useEffect(() => {
    if (!ready) return;
    const controller = new AbortController();
    listProfiles(parseProfileQuery(new URLSearchParams(apiKey)), controller.signal)
      .then((data) => setResult({ key: requestKey, page: data, error: null }))
      .catch((err: unknown) => {
        if (controller.signal.aborted) return;
        setResult((prev) => ({ key: requestKey, page: prev?.page ?? null, error: err }));
      });
    return () => controller.abort();
  }, [apiKey, requestKey, ready]);

  const navigate = useCallback((next: ProfileListQuery) => router.replace(`${pathname}${profileQueryToSearch(next)}`, { scroll: false }), [router, pathname]);

  useEffect(() => {
    if (query.q !== committedQ.current) {
      committedQ.current = query.q;
      setQInput(query.q);
    }
  }, [query.q]);

  useEffect(() => {
    const q = qInput.trim();
    if (q === query.q) return;
    if (query.q !== committedQ.current) return;
    const t = setTimeout(() => {
      committedQ.current = q;
      navigate({ ...query, q, page: 1 });
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [qInput, query, navigate]);

  useEffect(() => {
    if (!loading && result?.page && result.page.data.length === 0 && query.page > 1) {
      navigate({ ...query, page: Math.max(1, Math.min(query.page - 1, result.page.meta.last_page || 1)) });
    }
  }, [loading, result, query, navigate]);

  const reload = useCallback(() => setReloadKey((n) => n + 1), []);

  if (state.kind !== "staff") return null;
  if (loadError && isForbidden(loadError)) return <ForbiddenView />;

  const rows = page?.data ?? [];
  const enabledRows = rows.filter((t) => t.show_on_homepage);
  const otherRows = rows.filter((t) => !t.show_on_homepage);
  const max = page?.meta.homepage.max ?? HOMEPAGE_MAX_DEFAULT;
  const enabledCount = page?.meta.homepage.enabled_count ?? 0;
  const hasFilter = query.q !== "" || query.onlyEnabled;
  const canReorder = query.q === "" && busy === null;

  async function run(id: number | "order", fn: () => Promise<unknown>) {
    if (busyRef.current) return;
    busyRef.current = true;
    setBusy(id);
    setError(null);
    try {
      await fn();
    } catch (err) {
      setError(profileActionError(err));
    } finally {
      busyRef.current = false;
      setBusy(null);
      reload();
    }
  }

  function toggle(t: TeacherProfile, next: boolean) {
    // Bật: kèm thứ tự cuối. Server kiểm giới hạn trong transaction (409 nếu đã đủ).
    // Thứ tự cuối tính trên MỌI người đang bật (tối đa 6, vừa 1 trang), không trên danh sách đang lọc theo tên (QA FA11 BUG-1).
    void run(t.user.id, async () => {
      if (!next) return void (await patchHomepage(t.user.id, { show_on_homepage: false, homepage_order: null }));
      const enabled = query.q === "" && !query.onlyEnabled && query.page === 1 ? enabledRows : (await listProfiles({ q: "", onlyEnabled: true, page: 1, perPage: 25 })).data;
      await patchHomepage(t.user.id, { show_on_homepage: true, homepage_order: nextOrder(enabled) });
    });
  }

  function move(index: number, dir: -1 | 1) {
    const list = [...enabledRows];
    const target = index + dir;
    if (target < 0 || target >= list.length) return;
    [list[index], list[target]] = [list[target] as TeacherProfile, list[index] as TeacherProfile];
    void run("order", async () => {
      for (const [i, t] of list.entries()) {
        if (t.homepage_order !== i + 1) await patchHomepage(t.user.id, { homepage_order: i + 1 });
      }
    });
  }

  const row = (t: TeacherProfile, index?: number) => {
    const isTeacher = t.user.role === "giao_vien";
    const consent = t.consent.given;
    const reasons = t.homepage_status.reasons.filter((r) => r !== "not_enabled");
    const rowBusy = busy === t.user.id;
    return (
      <li key={t.user.id} data-testid={`teacher-row-${t.user.id}`} className="flex flex-col gap-3 border-b border-line px-3 py-3 last:border-b-0 sm:flex-row sm:items-center sm:px-4">
        <div className="flex min-w-0 flex-1 items-center gap-3">
          {index !== undefined ? (
            <span className="num w-6 shrink-0 text-center text-base font-extrabold text-primary" aria-label={`Vị trí ${index + 1}`}>
              {index + 1}
            </span>
          ) : null}
          <Avatar name={t.user.name} />
          <div className="min-w-0">
            <p className="break-words text-sm font-semibold text-ink">{t.user.name}</p>
            <p className="truncate text-xs text-ink-soft">
              {t.headline ?? "Chưa có dòng chuyên môn"} · {t.published_courses_count} khóa đang bán
            </p>
            <div className="mt-1 flex flex-wrap items-center gap-1.5">
              <Badge size="sm" tone={consent ? "success" : "warning"}>
                {consent ? "Đã đồng ý" : "Chưa đồng ý"}
              </Badge>
              {!isTeacher ? (
                <Badge size="sm" tone="neutral">
                  Không còn là giáo viên
                </Badge>
              ) : null}
              {t.user.status === "locked" ? (
                <Badge size="sm" tone="danger">
                  Tài khoản bị khoá
                </Badge>
              ) : null}
              {t.show_on_homepage ? (
                t.homepage_status.visible ? (
                  <Badge size="sm" tone="primary" dot>
                    Đang hiện
                  </Badge>
                ) : (
                  <Badge size="sm" tone="warning" dot>
                    {`Chưa hiện: ${reasons.map(reasonText).join(", ")}`}
                  </Badge>
                )
              ) : reasons.length > 0 ? (
                <span className="text-xs text-ink-soft">Thiếu: {reasons.map(reasonText).join(", ")}</span>
              ) : null}
            </div>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-1 pl-9 sm:pl-0">
          {index !== undefined ? (
            <>
              <IconButton
                size="sm"
                className="max-sm:size-11"
                label={`Đưa ${t.user.name} lên`}
                icon={<IconChevronUp size={18} />}
                disabled={!canReorder || index === 0}
                onClick={() => move(index, -1)}
              />
              <IconButton
                size="sm"
                className="max-sm:size-11"
                label={`Đưa ${t.user.name} xuống`}
                icon={<IconChevronDown size={18} />}
                disabled={!canReorder || index === enabledRows.length - 1}
                onClick={() => move(index, 1)}
              />
            </>
          ) : null}
          <Switch
            checked={t.show_on_homepage}
            label={`Hiển thị ${t.user.name} trên trang chủ`}
            disabled={busy !== null || (!isTeacher && !t.show_on_homepage)}
            aria-busy={rowBusy}
            onCheckedChange={(next) => toggle(t, next)}
          />
          <Link href={`${HOMEPAGE_TEACHERS_PATH}/${t.user.id}`} className="focus-ring inline-flex min-h-11 items-center gap-1.5 rounded-control px-2 text-sm font-semibold text-primary hover:bg-primary-soft sm:h-9 sm:min-h-0" aria-label={`Sửa hồ sơ ${t.user.name}`}>
            <IconPencil size={16} />
            Sửa hồ sơ
          </Link>
        </div>
      </li>
    );
  };

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-title font-extrabold tracking-heading text-ink">Giáo viên trên trang chủ</h1>
        <p className="mt-1 max-w-3xl text-sm text-ink-soft">
          Trang chủ hiện tối đa {max} giáo viên theo thứ tự dưới đây, và chỉ những người đã tự đồng ý công khai, có ảnh, có phần giới thiệu, có khóa đang bán. Thay đổi hiện trên website sau tối đa 1 phút.
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Badge tone={enabledCount >= max ? "warning" : "primary"} size="md">
          <span data-testid="enabled-counter">{`Đang bật ${enabledCount}/${max}`}</span>
        </Badge>
        {enabledCount >= max ? <span className="text-sm text-ink-soft">Đã đủ {max} người. Tắt một người trước khi bật người khác.</span> : null}
      </div>

      <form
        role="search"
        aria-label="Tìm giáo viên"
        onSubmit={(e) => {
          e.preventDefault();
          const q = qInput.trim();
          committedQ.current = q;
          if (q !== query.q) navigate({ ...query, q, page: 1 });
        }}
        className="grid gap-3 rounded-card border border-line bg-surface p-3 sm:grid-cols-[minmax(14rem,2fr)_auto_auto] sm:items-center"
      >
        <label className="sr-only" htmlFor="f-q">
          Tìm theo tên
        </label>
        <TextInput id="f-q" size="sm" className="max-sm:h-11" type="search" name="q" value={qInput} maxLength={100} placeholder="Tìm theo tên giáo viên" autoComplete="off" leadingIcon={<IconSearch size={16} />} onChange={(e) => setQInput(e.target.value)} />
        <Checkbox id="f-homepage" name="homepage" label="Chỉ người đang bật" className="min-h-11 py-0" checked={query.onlyEnabled} onChange={(e) => navigate({ ...query, onlyEnabled: e.target.checked, page: 1 })} />
        <button type="submit" className="focus-ring h-9 rounded-control bg-primary px-4 text-sm font-semibold text-on-primary hover:bg-primary-hover max-sm:h-11">
          Tìm
        </button>
      </form>

      {error ? (
        <Alert tone="danger" title={error} role="alert">
          Trạng thái các giáo viên không thay đổi. Danh sách đã được tải lại.
        </Alert>
      ) : null}
      {query.q !== "" && enabledRows.length > 1 ? <p className="text-sm text-ink-soft">Đang lọc theo tên nên chưa đổi được thứ tự. Xoá ô tìm kiếm để đổi thứ tự.</p> : null}

      {loadError ? (
        <Alert
          tone="danger"
          title="Không tải được danh sách giáo viên"
          action={
            <Button size="sm" variant="secondary" leadingIcon={<IconRotateCcw size={16} />} onClick={reload}>
              Thử lại
            </Button>
          }
        >
          {profileActionError(loadError)}
        </Alert>
      ) : loading && !page ? (
        <LoadingRegion className="flex flex-col gap-3">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-24 w-full" />
        </LoadingRegion>
      ) : (
        <div aria-busy={loading || busy !== null} className="flex flex-col gap-6">
          {rows.length === 0 ? (
            <EmptyState
              icon={<IconUsers size={32} />}
              title={hasFilter ? "Không có giáo viên phù hợp với bộ lọc." : "Chưa có giáo viên nào"}
              description={hasFilter ? undefined : "Tài khoản giáo viên tạo ở màn Tài khoản staff sẽ hiện ở đây."}
              action={
                hasFilter ? (
                  <ButtonLink href={HOMEPAGE_TEACHERS_PATH} size="sm" variant="secondary">
                    Xoá bộ lọc
                  </ButtonLink>
                ) : undefined
              }
            />
          ) : (
            <>
              <section aria-labelledby="dang-bat" className="rounded-card border border-line bg-surface">
                <h2 id="dang-bat" className="border-b border-line px-4 py-3 text-sm font-semibold text-ink">
                  Đang bật — thứ tự trên trang chủ
                </h2>
                {enabledRows.length > 0 ? <ol>{enabledRows.map((t, i) => row(t, i))}</ol> : <p className="px-4 py-4 text-sm text-ink-soft">Chưa bật giáo viên nào. Khu vực giáo viên sẽ ẩn trên trang chủ.</p>}
              </section>
              {!query.onlyEnabled ? (
                <section aria-labelledby="khac" className="rounded-card border border-line bg-surface">
                  <h2 id="khac" className="border-b border-line px-4 py-3 text-sm font-semibold text-ink">
                    Giáo viên khác
                  </h2>
                  {otherRows.length > 0 ? <ul>{otherRows.map((t) => row(t))}</ul> : <p className="px-4 py-4 text-sm text-ink-soft">Không còn giáo viên nào khác ở trang này.</p>}
                </section>
              ) : null}
            </>
          )}
          <div className="flex flex-wrap items-center justify-end gap-2 text-sm text-ink-soft">
            <label htmlFor="f-per-page">Số dòng/trang</label>
            <div className="w-24">
              <Select id="f-per-page" size="sm" className="max-sm:h-11" name="per_page" value={query.perPage} onChange={(e) => navigate({ ...query, perPage: Number(e.target.value) === 50 ? 50 : 25, page: 1 })}>
                {PER_PAGE_OPTIONS.map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </Select>
            </div>
          </div>
          {(page?.meta.last_page ?? 1) > 1 ? (
            <Pagination currentPage={page?.meta.current_page ?? query.page} lastPage={page?.meta.last_page ?? 1} hrefFor={(n) => `${pathname}${profileQueryToSearch({ ...query, page: n })}`} />
          ) : null}
        </div>
      )}
    </div>
  );
}

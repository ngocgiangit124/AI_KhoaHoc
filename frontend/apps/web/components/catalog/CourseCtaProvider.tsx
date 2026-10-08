"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useToast } from "@vitaminvui/ui/v2";
import { AccountGateDialog, type GateKind } from "@/components/v2/auth/AccountGate";
import { authFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth/AuthProvider";
import { useGateLive } from "@/lib/auth/useGateLive";
import { mapFreeEnrollError, resolveCta, type CtaModel, type ViewerStatus } from "@/lib/catalog/cta";
import { viewerStateSchema, type ViewerState } from "@/lib/catalog/schemas";

export interface CtaCourse {
  id: number;
  slug: string;
  isFree: boolean;
  /** `paid_checkout_enabled` của `/config/public` (đọc phía server khi render trang). */
  paidCheckoutEnabled: boolean;
}

interface CtaContextValue {
  model: CtaModel;
  /** Trạng thái sở hữu để Outline biết bài nào mở được. */
  owned: boolean;
  resumeHref: string | null;
  /** Khách bấm "Đăng ký/Mua" -> đăng nhập rồi quay lại trang khóa này (`?next=`). */
  loginHref: string;
  /** Thông báo lỗi của lần đăng ký miễn phí gần nhất. */
  error: string | null;
  run: () => void;
}

const CtaContext = createContext<CtaContextValue | null>(null);

/**
 * Gom logic CTA của trang chi tiết (US-003 §5 `<CourseCtaButton>`): `viewer-state` gọi PHÍA CLIENT
 * bằng `authFetch` (no-store) và CHỈ khi đã có phiên (/auth/me), để `GET /courses/{slug}` công
 * khai vẫn cache được (S16). Phải nằm trong `<AuthProvider>`.
 */
export function CourseCtaProvider({ course, children }: { course: CtaCourse; children: ReactNode }) {
  const router = useRouter();
  const toast = useToast();
  const { state: auth, refresh } = useAuth();
  const [viewer, setViewer] = useState<ViewerState | null>(null);
  const [viewerStatus, setViewerStatus] = useState<ViewerStatus>("idle");
  const [enrolling, setEnrolling] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  // Màn chặn (design-system-v2 §12.8): hộp thoại mở tại chỗ, giữ ngữ cảnh khóa học. Giữ gắn sau lần mở đầu để đóng/mở mượt.
  const [gate, setGate] = useState<{ kind: GateKind; open: boolean } | null>(null);
  const gateLive = useGateLive();

  const isUser = auth.status === "user";

  const loadViewer = useCallback(async (): Promise<void> => {
    setViewerStatus("loading");
    try {
      const raw = await authFetch<unknown>(`/api/v1/courses/${encodeURIComponent(course.slug)}/viewer-state`);
      const parsed = viewerStateSchema.safeParse(raw);
      if (!parsed.success) throw new Error("viewer-state sai shape");
      setViewer(parsed.data);
      setViewerStatus("ready");
    } catch {
      setViewerStatus("error");
    }
  }, [course.slug]);

  useEffect(() => {
    if (!isUser) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- đồng bộ với hệ thống ngoài (API): bắt đầu tải khi có phiên.
    void loadViewer();
  }, [isUser, loadViewer, reloadKey]);

  const loginHref = `/dang-nhap?next=${encodeURIComponent(`/khoa-hoc/${course.slug}`)}`;

  const model = resolveCta({
    auth: auth.status,
    viewerStatus,
    viewer,
    isFree: course.isFree,
    courseId: course.id,
    enrolling,
    paidCheckoutEnabled: course.paidCheckoutEnabled,
  });

  const registerFree = useCallback(async () => {
    setEnrolling(true);
    setError(null);
    try {
      await authFetch<unknown>(`/api/v1/courses/${course.id}/free-enrollments`, { method: "POST" });
      setViewer({ viewer_state: "pending_approval", resume_lesson_id: null });
      toast.show({ tone: "success", title: "Đã gửi yêu cầu đăng ký", description: "Bạn sẽ nhận email khi giáo viên duyệt." });
    } catch (err) {
      const outcome = mapFreeEnrollError(err);
      switch (outcome.type) {
        case "verify_account":
          setGate({ kind: "verify", open: true });
          break;
        case "pending":
          setViewer({ viewer_state: "pending_approval", resume_lesson_id: null });
          break;
        case "owned":
          setReloadKey((k) => k + 1);
          break;
        case "refresh":
          setError(outcome.message);
          setReloadKey((k) => k + 1);
          break;
        case "message":
          setError(outcome.message);
          break;
      }
    } finally {
      setEnrolling(false);
    }
  }, [course.id, toast]);

  const run = useCallback(() => {
    switch (model.kind) {
      case "login":
        router.push(loginHref);
        break;
      case "retry":
        if (auth.status === "error") void refresh();
        else setReloadKey((k) => k + 1);
        break;
      case "register_free":
        if (!enrolling) void registerFree();
        break;
      default:
        break;
    }
  }, [model.kind, router, loginHref, auth.status, refresh, enrolling, registerFree]);

  const value = useMemo<CtaContextValue>(
    () => ({
      model,
      owned: model.kind === "owned",
      resumeHref: model.kind === "owned" ? model.href : null,
      loginHref,
      error,
      run,
    }),
    [model, error, loginHref, run],
  );

  return (
    <CtaContext.Provider value={value}>
      {children}
      {gate ? (
        <AccountGateDialog kind={gate.kind} open={gate.open} onClose={() => setGate({ ...gate, open: false })} live={gateLive} />
      ) : null}
    </CtaContext.Provider>
  );
}

export function useCourseCta(): CtaContextValue {
  const ctx = useContext(CtaContext);
  if (!ctx) throw new Error("useCourseCta phải nằm trong <CourseCtaProvider>");
  return ctx;
}

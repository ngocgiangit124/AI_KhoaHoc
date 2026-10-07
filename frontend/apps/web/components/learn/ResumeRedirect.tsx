"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button, ButtonLink, EmptyState, IconAlertTriangle, LoadingRegion, Spinner } from "@vitaminvui/ui/v2";
import { ApiError } from "@vitaminvui/api-client";
import { fetchLearnCourse } from "@/lib/learn/api";
import { courseRefFromError } from "@/lib/learn/errors";
import { routes } from "@/lib/routes";

type State = "loading" | "empty" | "forbidden" | "error";

/** `/hoc/{course}`: đưa học sinh tới `resume_lesson_id` (US-006 AC6) — bài gần nhất chưa xong, hoặc bài đầu khi chưa học. */
export function ResumeRedirect({ courseId }: { courseId: number }) {
  const router = useRouter();
  const [state, setState] = useState<State>("loading");
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;
    fetchLearnCourse(courseId).then(
      (course) => {
        if (cancelled) return;
        if (course.resume_lesson_id === null) setState("empty");
        else router.replace(routes.lesson(courseId, course.resume_lesson_id));
      },
      (err: unknown) => {
        if (cancelled) return;
        const ref = courseRefFromError(err);
        if (ref) {
          router.replace(routes.course(ref.slug)); // chưa sở hữu: về trang khóa học để đăng ký/mua
          return;
        }
        setState(err instanceof ApiError && (err.status === 403 || err.status === 404) ? "forbidden" : "error");
      },
    );
    return () => {
      cancelled = true;
    };
  }, [courseId, router, attempt]);

  function retry() {
    setState("loading");
    setAttempt((a) => a + 1);
  }

  if (state === "loading") {
    return (
      <LoadingRegion label="Đang mở bài học…" className="flex flex-1 items-center justify-center gap-3">
        <Spinner className="size-6" label={null} />
        <span>Đang mở bài học…</span>
      </LoadingRegion>
    );
  }
  const title = state === "empty" ? "Khóa học chưa có bài học" : state === "forbidden" ? "Bạn chưa sở hữu khóa học này" : "Không tải được khóa học";
  return (
    <main id="noi-dung" className="flex flex-1 items-center justify-center">
      <EmptyState
        headingLevel="h1"
        icon={<IconAlertTriangle size={40} />}
        title={title}
        description={state === "error" ? "Có thể do kết nối mạng chập chờn. Hãy thử lại." : undefined}
        action={state === "error" ? <Button onClick={retry}>Thử lại</Button> : <ButtonLink href={routes.catalog}>Xem danh sách khóa học</ButtonLink>}
      />
    </main>
  );
}

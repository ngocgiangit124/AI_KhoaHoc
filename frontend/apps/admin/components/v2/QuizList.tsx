import Link from "next/link";
import { Alert, Badge, Button, DataTable, EmptyState, IconArrowRight, IconListChecks, IconRotateCcw } from "@vitaminvui/ui/v2";
import type { QuizResource } from "@/lib/mock/v2/quizzes";
import { QuizSettingsDialog, type ParentOption } from "./QuizSettingsDialog";

/**
 * Tab "Bài tập" của màn sửa khóa học (FA5, GET /admin/courses/{c}/quizzes). Sắp theo `position`.
 * Bài tập chưa có câu → badge cảnh báo + câu giải thích (học sinh sẽ gặp "Bài kiểm tra chưa sẵn sàng").
 */
export function QuizList({
  courseId,
  quizzes,
  parents,
  roleQuery,
  state,
}: {
  courseId: number;
  quizzes: QuizResource[];
  parents: ParentOption[];
  roleQuery: string;
  state?: "dang-tai" | "loi";
}) {
  const editHref = (id: number) => `/v2/quan-tri/khoa-hoc/${courseId}/bai-tap/${id}${roleQuery}`;
  const empties = quizzes.filter((q) => q.questions_count === 0).length;

  if (state === "loi") {
    return (
      <Alert tone="danger" title="Không tải được danh sách bài tập" action={<Button size="sm" variant="secondary" leadingIcon={<IconRotateCcw size={16} />}>Thử lại</Button>}>
        Kiểm tra kết nối mạng rồi thử lại. Các phần khác của khóa học không bị ảnh hưởng.
      </Alert>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-2xl text-sm text-ink-soft">Mỗi bài tập đi kèm một chương (bài cuối chương) hoặc một bài học. Học sinh thấy bài tập ngay dưới chương/bài đó ở trang học.</p>
        <QuizSettingsDialog mode="create" parents={parents} />
      </div>
      {empties > 0 && !state ? (
        <Alert tone="warning" title={`${empties} bài tập chưa có câu hỏi`}>
          Học sinh mở bài tập chưa có câu sẽ thấy “Bài kiểm tra chưa sẵn sàng”. Hãy thêm câu hỏi hoặc xoá bài tập đó.
        </Alert>
      ) : null}
      <DataTable
        caption="Danh sách bài tập của khóa học"
        rowKey={(q) => q.id}
        rows={state === "dang-tai" ? [] : quizzes}
        loadingRows={state === "dang-tai" ? 4 : undefined}
        empty={
          <EmptyState
            size="inline"
            icon={<IconListChecks size={28} />}
            title="Khóa học chưa có bài tập"
            description="Tạo bài tập trắc nghiệm cho một chương hoặc một bài học. Câu hỏi viết được công thức Toán."
            action={<QuizSettingsDialog mode="create" parents={parents} />}
          />
        }
        columns={[
          {
            key: "title",
            header: "Bài tập",
            cell: (q) => (
              <div className="flex flex-col">
                <Link href={editHref(q.id)} className="focus-ring w-fit rounded font-semibold text-ink hover:text-primary">
                  {q.title}
                </Link>
                <span className="text-sm text-ink-soft xl:hidden">{q.parent_type === "chapter" ? "Cả chương · " : "Bài · "}{q.parent_title}</span>
              </div>
            ),
          },
          {
            key: "parent",
            header: "Gắn với",
            hideBelow: "xl",
            cell: (q) => (
              <span className="flex flex-col">
                <span className="text-ink">{q.parent_title}</span>
                <span className="text-xs text-ink-soft">{q.parent_type === "chapter" ? "Bài tập cuối chương" : "Bài tập của bài học"}</span>
              </span>
            ),
          },
          {
            key: "count",
            header: "Số câu",
            align: "right",
            cell: (q) =>
              q.questions_count === 0 ? (
                <Badge tone="warning" size="sm" dot>
                  Chưa có câu
                </Badge>
              ) : (
                <span className="num">{q.questions_count}</span>
              ),
          },
          {
            key: "time",
            header: "Thời gian",
            hideBelow: "md",
            cell: (q) => (q.time_limit_minutes ? <span className="num">{q.time_limit_minutes} phút</span> : <span className="text-ink-soft">Không giới hạn</span>),
          },
          {
            key: "act",
            header: <span className="sr-only">Thao tác</span>,
            align: "right",
            cell: (q) => (
              <Link href={editHref(q.id)} className="focus-ring inline-flex h-9 items-center gap-1 rounded-control px-2 font-semibold text-primary hover:bg-primary-soft">
                Soạn câu hỏi
                <span className="sr-only">: {q.title}</span>
                <IconArrowRight size={16} />
              </Link>
            ),
          },
        ]}
      />
    </div>
  );
}

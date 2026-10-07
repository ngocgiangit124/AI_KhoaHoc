import Link from "next/link";
import { notFound } from "next/navigation";
import {
  Alert,
  Badge,
  Breadcrumb,
  Button,
  ButtonLink,
  EmptyState,
  IconClock,
  IconLayers,
  IconListChecks,
  IconPencil,
  IconPlus,
  IconRotateCcw,
  MathText,
  Skeleton,
  cx,
} from "@vitaminvui/ui/v2";
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { QuizQuestionEditor, type EditorDemo } from "@/components/v2/QuizQuestionEditor";
import { QuizSettingsDialog } from "@/components/v2/QuizSettingsDialog";
import { ADMIN_COURSES, CHAPTERS } from "@/lib/mock/v2/data";
import { INVALID_DRAFT, QUESTIONS, QUIZ_LIMITS, getQuiz } from "@/lib/mock/v2/quizzes";

export const dynamic = "force-dynamic";

function one(v: string | string[] | undefined) {
  return Array.isArray(v) ? v[0] : v;
}

const LETTERS = ["A", "B", "C", "D"];

/**
 * Soạn quiz (FA5, US-007 phía quản trị): `?cau=` mở khung soạn một câu (`moi` = câu mới), không có `cau`
 * là danh sách câu. Quyền: `manageContent` (Admin/QLT mọi khóa, GV khóa mình phụ trách).
 */
export default async function QuizComposerPreview({ params, searchParams }: PageProps<"/v2/quan-tri/khoa-hoc/[id]/bai-tap/[quiz]">) {
  const { id, quiz: quizParam } = await params;
  const sp = await searchParams;
  const course = ADMIN_COURSES.find((c) => c.id === Number(id));
  const quiz = getQuiz(Number(quizParam));
  if (!course || !quiz || quiz.course_id !== course.id) notFound();

  const role = roleFrom(sp["vai-tro"]);
  const roleQ = role !== "admin" ? `vai-tro=${role}` : "";
  const state = one(sp["trang-thai"]);
  const cau = one(sp.cau);
  const base = `/v2/quan-tri/khoa-hoc/${course.id}/bai-tap/${quiz.id}`;
  const href = (extra: Record<string, string | undefined>) => {
    const p = new URLSearchParams(roleQ);
    for (const [k, v] of Object.entries(extra)) if (v) p.set(k, v);
    const s = p.toString();
    return s ? `${base}?${s}` : base;
  };
  const courseEdit = `/v2/quan-tri/khoa-hoc/${course.id}/sua?${roleQ ? `${roleQ}&` : ""}tab=bai-tap`;

  const questions = quiz.id === 502 && state !== "rong" ? QUESTIONS : [];
  const full = state === "day";
  const count = full ? QUIZ_LIMITS.questions : questions.length;
  const parents = CHAPTERS.map((c) => ({ id: c.id, title: c.title, lessons: c.lessons.map((l) => ({ id: l.id, title: l.title })) }));

  const editing = cau !== undefined;
  const idx = editing && cau !== "moi" ? questions.findIndex((q) => q.id === Number(cau)) : -1;
  const current = idx >= 0 ? questions[idx] : undefined;
  if (editing && cau !== "moi" && !current) notFound();
  const editorDemo: EditorDemo | undefined = state === "loi-luu" || state === "dang-luu" || state === "ban-moi" ? state : undefined;

  const listStates = [
    { label: "Có câu hỏi" },
    { key: "dang-tai", label: "Đang tải" },
    { key: "rong", label: "Chưa có câu" },
    { key: "day", label: "Đủ 200 câu" },
    { key: "loi", label: "Lỗi tải" },
  ];
  const editorStates = [
    { label: "Đang soạn" },
    { key: "loi-luu", label: "Lỗi khi lưu (422)" },
    { key: "dang-luu", label: "Đang lưu" },
    { key: "ban-moi", label: "Lưu thành bản mới" },
  ];

  return (
    <AdminPreviewShell
      role={role}
      current="courses"
      basePath={base}
      extraQuery={editing ? `cau=${cau}` : ""}
      states={editing ? editorStates : listStates}
      state={state}
    >
      <Breadcrumb
        items={[
          { label: role === "giao_vien" ? "Khóa học của tôi" : "Khóa học", href: `/v2/quan-tri/khoa-hoc${roleQ ? `?${roleQ}` : ""}` },
          { label: course.title, href: courseEdit },
          ...(editing ? [{ label: quiz.title, href: href({}) }, { label: current ? `Câu ${current.position}` : "Câu mới" }] : [{ label: quiz.title }]),
        ]}
      />

      <div className="mt-3 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <h1 className="text-title font-extrabold tracking-heading text-ink">{quiz.title}</h1>
          <ul className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-soft">
            <li className="inline-flex items-center gap-1.5">
              <IconLayers size={16} />
              {quiz.parent_type === "chapter" ? "Cuối chương: " : "Bài học: "}
              {quiz.parent_title}
            </li>
            <li className="inline-flex items-center gap-1.5">
              <IconClock size={16} />
              {quiz.time_limit_minutes ? `${quiz.time_limit_minutes} phút` : "Không giới hạn thời gian"}
            </li>
            <li className="num inline-flex items-center gap-1.5">
              <IconListChecks size={16} />
              {count}/{QUIZ_LIMITS.questions} câu
            </li>
          </ul>
        </div>
        {!editing ? (
          <div className="flex shrink-0 flex-wrap gap-2">
            <QuizSettingsDialog mode="edit" quiz={quiz} parents={parents} />
            {full ? (
              <Button size="sm" disabled leadingIcon={<IconPlus size={16} />}>
                Thêm câu hỏi
              </Button>
            ) : (
              <ButtonLink href={href({ cau: "moi" })} size="sm" leadingIcon={<IconPlus size={16} />}>
                Thêm câu hỏi
              </ButtonLink>
            )}
          </div>
        ) : null}
      </div>
      {full && !editing ? <p className="mt-2 text-sm text-ink-soft lg:text-right">Mỗi bài tập tối đa 200 câu. Hãy tạo bài tập mới cho phần còn lại.</p> : null}

      {editing ? (
        <>
          <nav aria-label="Chọn câu để soạn" className="mt-5 flex flex-wrap gap-1.5">
            {questions.map((q) => (
              <Link
                key={q.id}
                href={href({ cau: String(q.id) })}
                scroll={false}
                aria-current={q.id === current?.id ? "page" : undefined}
                className={cx(
                  "focus-ring num inline-flex size-9 items-center justify-center rounded-control border text-sm font-semibold",
                  q.id === current?.id ? "border-primary bg-primary text-on-primary" : "border-line-strong bg-surface text-ink hover:border-primary hover:text-primary",
                )}
              >
                <span className="sr-only">Câu </span>
                {q.position}
              </Link>
            ))}
            <Link
              href={href({ cau: "moi" })}
              scroll={false}
              aria-current={cau === "moi" ? "page" : undefined}
              className={cx(
                "focus-ring inline-flex h-9 items-center gap-1 rounded-control border border-dashed px-2.5 text-sm font-semibold",
                cau === "moi" ? "border-primary bg-primary-soft text-primary" : "border-line-strong text-primary hover:border-primary",
              )}
            >
              <IconPlus size={16} /> Câu mới
            </Link>
          </nav>
          <div className="mt-5">
            <QuizQuestionEditor
              key={`${cau}-${state ?? ""}`}
              question={state === "loi-luu" && !current ? INVALID_DRAFT : current ? current : null}
              position={current ? current.position : questions.length + 1}
              isNew={!current}
              prevHref={idx > 0 ? href({ cau: String(questions[idx - 1]?.id) }) : undefined}
              nextHref={idx >= 0 && idx < questions.length - 1 ? href({ cau: String(questions[idx + 1]?.id) }) : undefined}
              listHref={href({})}
              demo={editorDemo}
            />
          </div>
        </>
      ) : state === "loi" ? (
        <Alert className="mt-6" tone="danger" title="Không tải được câu hỏi" action={<Button size="sm" variant="secondary" leadingIcon={<IconRotateCcw size={16} />}>Thử lại</Button>}>
          Kiểm tra kết nối mạng rồi thử lại.
        </Alert>
      ) : state === "dang-tai" ? (
        <ol aria-busy="true" aria-label="Đang tải câu hỏi" className="mt-6 flex flex-col gap-3">
          {[0, 1, 2].map((i) => (
            <li key={i} className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
              <Skeleton className="h-4 w-16" />
              <Skeleton className="h-5 w-full" />
              <Skeleton className="h-5 w-2/3" />
            </li>
          ))}
        </ol>
      ) : questions.length === 0 ? (
        <div className="mt-6 rounded-card border border-line bg-surface">
          <EmptyState
            icon={<IconListChecks size={36} />}
            title="Bài tập chưa có câu hỏi"
            description="Học sinh mở bài tập này sẽ thấy “Bài kiểm tra chưa sẵn sàng”. Thêm ít nhất một câu trắc nghiệm 4 đáp án."
            action={<ButtonLink href={href({ cau: "moi" })} leadingIcon={<IconPlus size={18} />}>Thêm câu hỏi đầu tiên</ButtonLink>}
          />
        </div>
      ) : (
        <>
          <p className="mt-5 text-sm text-ink-soft">Câu hiện theo thứ tự học sinh sẽ làm. Chưa đổi được thứ tự câu (ngoài phạm vi MVP) — câu mới luôn thêm vào cuối.</p>
          <ol className="mt-3 flex flex-col gap-3">
            {(full ? questions.slice(0, 3) : questions).map((q) => {
              const right = q.options.find((o) => o.is_correct);
              const letter = right ? LETTERS[right.position - 1] : "?";
              return (
                <li key={q.id} className="rounded-card border border-line bg-surface p-4 hover:border-primary">
                  <div className="flex items-start gap-3">
                    <span className="num mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-sunken text-sm font-extrabold text-ink" aria-hidden="true">
                      {q.position}
                    </span>
                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                      <h2 className="sr-only">Câu {q.position}</h2>
                      <MathText content={q.content} className="text-base text-ink" />
                      <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink">
                        <span className="font-semibold text-success">Đáp án đúng {letter}:</span>
                        {right ? <MathText as="span" content={right.content} /> : null}
                        {q.explanation ? (
                          <Badge size="sm" tone="neutral">
                            Có lời giải
                          </Badge>
                        ) : (
                          <Badge size="sm" tone="neutral">
                            Chưa có lời giải
                          </Badge>
                        )}
                      </p>
                    </div>
                    <Link
                      href={href({ cau: String(q.id) })}
                      className="focus-ring inline-flex h-9 shrink-0 items-center gap-1 rounded-control px-2 text-sm font-semibold text-primary hover:bg-primary-soft"
                    >
                      <IconPencil size={16} />
                      Sửa<span className="sr-only"> câu {q.position}</span>
                    </Link>
                  </div>
                </li>
              );
            })}
          </ol>
          {full ? <p className="mt-3 text-sm text-ink-soft">… và 197 câu khác (dữ liệu mẫu).</p> : null}
        </>
      )}
    </AdminPreviewShell>
  );
}

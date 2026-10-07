import { Badge, type BadgeTone } from "@vitaminvui/ui/v2";
import { COURSE_STATUS_LABELS, type CourseStatus } from "@/lib/courses/types";

const TONES: Record<CourseStatus, BadgeTone> = { draft: "neutral", published: "success", unpublished: "warning" };

/** Trạng thái khóa: chấm + chữ (không chỉ màu), cùng hình thức `components/v2/CourseStatusBadge` của bản xem trước. */
export function CourseStatusBadge({ status }: { status: CourseStatus }) {
  return (
    <Badge tone={TONES[status] ?? "neutral"} dot size="sm">
      {COURSE_STATUS_LABELS[status] ?? status}
    </Badge>
  );
}

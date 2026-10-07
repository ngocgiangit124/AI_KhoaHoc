import { Badge, IconCheckCircle, IconHourglass, IconUpload, IconAlertCircle, IconVideo, IconExternalLink } from "@vitaminvui/ui/v2";
import { STATUS_LABEL, type AdminLesson, type CourseStatus } from "@/lib/mock/v2/data";

/** Trạng thái khóa (StatusPill): chấm + chữ, không chỉ màu. */
export function CourseStatusBadge({ status }: { status: CourseStatus }) {
  const tone = status === "published" ? "success" : status === "unpublished" ? "warning" : "neutral";
  return (
    <Badge tone={tone} dot size="sm">
      {STATUS_LABEL[status]}
    </Badge>
  );
}

/**
 * VideoStatusBadge (US-009 §2.3): trạng thái video của bài, từ `video_source` + `video_status`.
 * Đang tải lên hiện % (từ tus-js-client), lỗi hiện đỏ; bài link ngoài ghi rõ nguồn.
 */
export function VideoStatusBadge({ lesson }: { lesson: Pick<AdminLesson, "video_source" | "video_status" | "external_provider" | "upload_percent"> }) {
  if (lesson.video_source === "external_link") {
    return (
      <Badge tone="info" size="sm" icon={<IconExternalLink size={12} />}>
        {lesson.external_provider === "vimeo" ? "Vimeo" : "YouTube"}
      </Badge>
    );
  }
  if (lesson.video_source === "none" || lesson.video_status === null) {
    return (
      <Badge size="sm" icon={<IconVideo size={12} />}>
        Chưa có video
      </Badge>
    );
  }
  switch (lesson.video_status) {
    case "ready":
      return (
        <Badge tone="success" size="sm" icon={<IconCheckCircle size={12} />}>
          Sẵn sàng
        </Badge>
      );
    case "processing":
      return (
        <Badge tone="warning" size="sm" icon={<IconHourglass size={12} />}>
          Đang xử lý
        </Badge>
      );
    case "uploading":
    case "created":
      return (
        <Badge tone="info" size="sm" icon={<IconUpload size={12} />}>
          {`Đang tải lên${lesson.upload_percent !== undefined ? ` ${lesson.upload_percent}%` : "…"}`}
        </Badge>
      );
    case "failed":
      return (
        <Badge tone="danger" size="sm" icon={<IconAlertCircle size={12} />}>
          Lỗi video
        </Badge>
      );
  }
}

import type { PaginatedResponse } from "@vitaminvui/api-client";

export const REQUEST_STATUSES = ["pending_approval", "active", "rejected"] as const;
export type RequestStatus = (typeof REQUEST_STATUSES)[number];

export const STATUS_LABELS: Record<RequestStatus, string> = {
  pending_approval: "Chờ duyệt",
  active: "Đã duyệt",
  rejected: "Đã từ chối",
};

export const PER_PAGE_OPTIONS = [25, 50] as const;
export type PerPage = (typeof PER_PAGE_OPTIONS)[number];
export const REASON_MAX = 1000;

/** Item của `GET /admin/enrollment-requests` (api-contract, T14): chỉ những gì API trả, email/SĐT đã che. */
export interface EnrollmentRequest {
  id: number;
  status: RequestStatus;
  requested_at: string | null;
  approved_at: string | null;
  rejection_reason: string | null;
  course: { id: number; title: string; slug: string };
  student: { id: number; name: string; grade_level: number | null; email_masked: string | null; phone_masked: string | null };
}

export type EnrollmentRequestPage = PaginatedResponse<EnrollmentRequest>;

/** Bộ lọc trên URL của `/quan-tri/duyet-dang-ky`. */
export interface RequestQuery {
  status: RequestStatus;
  courseId: number | null;
  page: number;
  perPage: PerPage;
}

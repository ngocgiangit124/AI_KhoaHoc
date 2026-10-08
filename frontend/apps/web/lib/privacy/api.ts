import { ApiError, NetworkError, clearCsrfToken, dispatchAuthEventIfNeeded, getCsrfToken, getDeviceId, publicFetch as publicFetchBase } from "@vitaminvui/api-client";
import { authFetch } from "@/lib/api";
import { env } from "@/env";
import { filenameFromDisposition } from "./download";
import {
  consentsResponseSchema,
  dataExportStatusSchema,
  deleteOtpResponseSchema,
  parentContactSchema,
  type ConsentsResponse,
  type DataExportStatus,
  type DeleteOtpResponse,
  type ParentContact,
} from "./schemas";

const JSON_HEADERS = { "Content-Type": "application/json" } as const;

function parseOrThrow<T>(parse: { safeParse: (v: unknown) => { success: true; data: T } | { success: false } }, raw: unknown): T {
  const result = parse.safeParse(raw);
  if (!result.success) throw new Error("Dữ liệu máy chủ trả về không đúng định dạng.");
  return result.data;
}

export async function fetchConsents(signal?: AbortSignal): Promise<ConsentsResponse> {
  return parseOrThrow(consentsResponseSchema, await authFetch<unknown>("/api/v1/me/consents", { signal }));
}

/** `POST /me/consents/accept` -> 200 (shape như GET). 409 `CONSENT_VERSION_CHANGED` + `errors.current_version`. */
export async function acceptConsents(policyVersion: string): Promise<ConsentsResponse> {
  const raw = await authFetch<unknown>("/api/v1/me/consents/accept", {
    method: "POST",
    headers: JSON_HEADERS,
    body: JSON.stringify({ policy_version: policyVersion, accept_terms: true, accept_privacy: true }),
  });
  return parseOrThrow(consentsResponseSchema, raw);
}

export async function fetchParentContact(signal?: AbortSignal): Promise<ParentContact> {
  return parseOrThrow(parentContactSchema, await authFetch<unknown>("/api/v1/me/parent-contact", { signal }));
}

export interface ParentContactUpdate {
  current_password: string;
  /** Thiếu key = giữ nguyên · `null` = xoá · chuỗi = thay. */
  parent_email?: string | null;
  parent_phone?: string | null;
}

export async function updateParentContact(body: ParentContactUpdate): Promise<ParentContact> {
  const raw = await authFetch<unknown>("/api/v1/me/parent-contact", { method: "PUT", headers: JSON_HEADERS, body: JSON.stringify(body) });
  return parseOrThrow(parentContactSchema, raw);
}

export async function fetchExportStatus(signal?: AbortSignal): Promise<DataExportStatus> {
  return parseOrThrow(dataExportStatusSchema, await authFetch<unknown>("/api/v1/me/data-export", { signal }));
}

function retryAfter(res: Response): number | undefined {
  const raw = res.headers.get("Retry-After");
  return raw && /^\d+$/.test(raw.trim()) ? Number(raw.trim()) : undefined;
}

/**
 * `POST /me/data-export` trả FILE (không phải JSON envelope) nên không dùng được `authFetch`: tự gắn CSRF/Device-Id như `authFetch`,
 * thử lại 1 lần khi 419, lỗi -> `ApiError` (phát sự kiện mất phiên nếu có). Trả blob + tên file lấy từ `Content-Disposition`.
 */
export async function downloadDataExport(currentPassword: string, _retried = false): Promise<{ blob: Blob; filename: string }> {
  const base = env.NEXT_PUBLIC_API_URL;
  let res: Response;
  try {
    res = await fetch(`${base}/api/v1/me/data-export`, {
      method: "POST",
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json", "Content-Type": "application/json", "X-Device-Id": getDeviceId(), "X-CSRF-TOKEN": await getCsrfToken(base) },
      body: JSON.stringify({ current_password: currentPassword }),
    });
  } catch (cause) {
    throw new NetworkError(cause);
  }
  if (res.status === 419 && !_retried) {
    clearCsrfToken(base);
    return downloadDataExport(currentPassword, true);
  }
  if (!res.ok) {
    const body = (await res.json().catch(() => ({}))) as ConstructorParameters<typeof ApiError>[1];
    const err = new ApiError(res.status, body ?? { message: "" }, retryAfter(res));
    dispatchAuthEventIfNeeded(err.code);
    throw err;
  }
  return { blob: await res.blob(), filename: filenameFromDisposition(res.headers.get("Content-Disposition")) };
}

/** `POST /me/account/delete/otp` -> 202. */
export async function sendDeleteOtp(): Promise<DeleteOtpResponse> {
  const raw = await authFetch<unknown>("/api/v1/me/account/delete/otp", { method: "POST", headers: JSON_HEADERS, body: "{}" });
  return parseOrThrow(deleteOtpResponseSchema, raw);
}

/** `POST /me/account/delete` -> 200. Phiên hiện tại bị huỷ trong cùng response nên bỏ cache CSRF cũ. */
export async function confirmAccountDeletion(code: string): Promise<void> {
  await authFetch<unknown>("/api/v1/me/account/delete", { method: "POST", headers: JSON_HEADERS, body: JSON.stringify({ code }) });
  clearCsrfToken(env.NEXT_PUBLIC_API_URL);
}

/**
 * `POST /parent-notices/unsubscribe {token}` — công khai: không cookie (`credentials: 'omit'`), không CSRF. Mọi 2xx đều như nhau
 * (server không lộ token đúng hay sai); lỗi chỉ gồm 422 (thiếu/sai dạng token), 429, mạng.
 */
export async function unsubscribeParentNotice(token: string): Promise<void> {
  await publicFetchBase<unknown>(env.NEXT_PUBLIC_API_URL, "/api/v1/parent-notices/unsubscribe", {
    method: "POST",
    headers: JSON_HEADERS,
    body: JSON.stringify({ token }),
    revalidate: false,
  });
}

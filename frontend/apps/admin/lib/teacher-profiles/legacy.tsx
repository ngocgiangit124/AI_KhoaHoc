"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import type { StaffRole } from "@/lib/auth/types";
import { getProfile } from "./api";
import type { TeacherProfile } from "./types";

/** Còn dữ liệu công khai cần tự gỡ: đồng ý đang bật hoặc còn ảnh (FA11-1). */
export function hasLegacyData(p: TeacherProfile | null): boolean {
  return p !== null && (p.consent.given || p.avatar_url !== null);
}

interface LegacyState {
  /** Người không còn là giáo viên mà vẫn còn dữ liệu hồ sơ → hiện mục menu. */
  hasData: boolean;
  /** Màn "Hồ sơ giáo viên cũ" cập nhật sau khi rút đồng ý/xoá ảnh để menu ẩn ngay, không gọi lại API. */
  report: (profile: TeacherProfile | null) => void;
}

const Ctx = createContext<LegacyState>({ hasData: false, report: () => undefined });
export const useLegacyProfile = () => useContext(Ctx);

/**
 * Hỏi `GET /admin/me/teacher-profile` đúng 1 lần mỗi phiên/người dùng (shell không remount khi chuyển trang) cho người
 * KHÔNG phải giáo viên. 403 (chưa từng có hồ sơ) hoặc lỗi khác → không hiện lối vào. Giáo viên không gọi.
 */
export function LegacyProfileProvider({ role, userId, children }: { role: StaffRole; userId: number | string | null; children: ReactNode }) {
  const [hasData, setHasData] = useState(false);

  useEffect(() => {
    if (role === "giao_vien") return;
    const controller = new AbortController();
    getProfile({ kind: "me" }, controller.signal)
      .then((p) => setHasData(hasLegacyData(p)))
      .catch(() => {
        if (!controller.signal.aborted) setHasData(false);
      });
    return () => controller.abort();
  }, [role, userId]);

  const isTeacher = role === "giao_vien";
  const report = useCallback((p: TeacherProfile | null) => setHasData(hasLegacyData(p)), []);
  const value = useMemo(() => ({ hasData: hasData && !isTeacher, report }), [hasData, isTeacher, report]);
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

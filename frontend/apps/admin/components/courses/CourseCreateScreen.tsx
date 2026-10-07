"use client";

import { useRouter } from "next/navigation";
import { useCallback } from "react";
import { Breadcrumb, useToast } from "@vitaminvui/ui/v2";
import { useSession } from "@/lib/auth/SessionProvider";
import { isCourseStaff } from "@/lib/courses/permissions";
import { COURSES_PATH } from "@/lib/courses/query";
import type { CourseDetail } from "@/lib/courses/types";
import { CourseForm } from "./CourseForm";

/** `/quan-tri/khoa-hoc/tao` — tạo khóa học (luôn là bản nháp), xong chuyển sang trang sửa. */
export function CourseCreateScreen() {
  const router = useRouter();
  const toast = useToast();
  const { state } = useSession();
  const onSaved = useCallback(
    (course: CourseDetail | null) => {
      // TODO(FA4): thêm description "Tiếp theo: thêm chương và bài học." khi có màn Chương & bài.
      toast.show({ tone: "success", title: "Đã tạo khóa học (nháp)" });
      router.push(course ? `${COURSES_PATH}/${course.id}/sua` : COURSES_PATH);
    },
    [router, toast],
  );
  if (state.kind !== "staff") return null;
  const isStaff = isCourseStaff(state.user);
  return (
    <div className="flex flex-col">
      <Breadcrumb items={[{ label: isStaff ? "Khóa học" : "Khóa học của tôi", href: COURSES_PATH }, { label: "Tạo khóa học" }]} />
      <h1 className="mt-3 text-title font-extrabold tracking-heading text-ink">Tạo khóa học</h1>
      <p className="mt-1 text-sm text-ink-soft">Điền thông tin chung trước; chương, bài học và video thêm ở bước sau.</p>
      <div className="mt-6">
        <CourseForm mode="create" isStaff={isStaff} onSaved={onSaved} />
      </div>
    </div>
  );
}

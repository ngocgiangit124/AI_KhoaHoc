"use client";

import { useState } from "react";
import Image from "next/image";
import { CourseCover } from "@vitaminvui/ui/v2";

/** Ảnh nhỏ trong bảng; không có ảnh/lỗi tải → bìa dựng sẵn theo chuyên đề (ảnh tĩnh chưa chắc phục vụ được ở môi trường dev). */
export function CourseThumb({ url, title, gradeLevel, subjectSlug }: { url: string | null; title: string; gradeLevel: number; subjectSlug?: string }) {
  const [broken, setBroken] = useState(false);
  const image =
    url && !broken ? <Image src={url} alt="" fill sizes="80px" unoptimized onError={() => setBroken(true)} className="object-cover" /> : undefined;
  return <CourseCover title={title} gradeLevel={gradeLevel} subjectSlug={subjectSlug} size="thumb" image={image} />;
}

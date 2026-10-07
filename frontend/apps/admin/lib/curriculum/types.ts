export type VideoSource = "none" | "upload" | "external_link";
export type VideoStatus = "created" | "uploading" | "processing" | "ready" | "failed" | null;

/** `LessonResource` (T09 + T11): GET/POST/PUT lesson và phần tử của cây chương. */
export interface Lesson {
  id: number;
  course_id: number;
  chapter_id: number;
  title: string;
  position: number;
  is_preview: boolean;
  video_source: VideoSource;
  duration_seconds: number | null;
  external_provider: "youtube" | "vimeo" | null;
  external_video_id: string | null;
  external_embed_url: string | null;
  has_video_asset: boolean;
  video_status: VideoStatus;
  created_at?: string | null;
  updated_at?: string | null;
}

/** `ChapterResource` kèm các bài. */
export interface Chapter {
  id: number;
  course_id: number;
  title: string;
  position: number;
  lessons: Lesson[];
  created_at?: string | null;
  updated_at?: string | null;
}

/** `GET /admin/courses/{id}/chapters` và `PUT .../curriculum/order`. */
export interface CurriculumResponse {
  course_id: number;
  chapters: Chapter[];
}

/** `GET .../lessons/{lesson}/video` (T11). */
export interface LessonVideoInfo {
  lesson_id: number;
  video_source: VideoSource;
  has_video_asset: boolean;
  video_asset_id: number | string | null;
  status: VideoStatus;
  duration_seconds: number | null;
  original_filename: string | null;
  error_message: string | null;
  updated_at?: string | null;
}

/** `POST .../video-uploads` → 201. `headers` gửi nguyên cho tus-js-client. */
export interface VideoUploadSession {
  video_asset_id: number | string;
  status: "uploading";
  upload: {
    protocol: "tus";
    tus_endpoint: string;
    headers: Record<string, string>;
    expires_at: string;
  };
}

export interface LessonPayload {
  title?: string;
  is_preview?: boolean;
  video_source?: VideoSource;
  external_url?: string;
}

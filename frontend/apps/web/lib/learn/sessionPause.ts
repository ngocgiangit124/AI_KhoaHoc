import { FORCED_LOGOUT_EVENT, LOGIN_REQUIRED_EVENT } from "@vitaminvui/api-client";

/**
 * `SessionEndedGate` mở hộp thoại (modal làm nền `inert` nhưng KHÔNG dừng video/âm thanh) khi `api-client` phát hai sự kiện này.
 * Màn học nghe cùng sự kiện để `pause()` player và ngừng heartbeat/làm mới link (board: "hộp thoại mất phiên phải pause() player").
 * Trả về hàm huỷ đăng ký.
 */
export function onSessionEnded(handler: () => void, target: Pick<Window, "addEventListener" | "removeEventListener"> = window): () => void {
  target.addEventListener(FORCED_LOGOUT_EVENT, handler);
  target.addEventListener(LOGIN_REQUIRED_EVENT, handler);
  return () => {
    target.removeEventListener(FORCED_LOGOUT_EVENT, handler);
    target.removeEventListener(LOGIN_REQUIRED_EVENT, handler);
  };
}

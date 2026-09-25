// Rule dùng chung cho cả apps/web và apps/admin (ADR-004 §2.6, api-contract §4):
// - Cấm `dangerouslySetInnerHTML` ở mọi nơi, trừ file được allowlist tường minh trong
//   eslint.config.mjs của từng app bằng một block `{ files: [...], rules: { 'no-restricted-syntax': 'off' } }`
//   khi đã chạy qua DOMPurify (chỉ `courses.description` — S8).
//
// Cách dùng trong apps/*/eslint.config.mjs:
//   import { dangerouslySetInnerHtmlBan, ignores } from "@vitaminvui/config/eslint/base.mjs";
//   export default defineConfig([...nextVitals, ...nextTs, dangerouslySetInnerHtmlBan, ignores]);

/** @type {import("eslint").Linter.Config} */
export const dangerouslySetInnerHtmlBan = {
  rules: {
    "no-restricted-syntax": [
      "error",
      {
        selector: 'JSXAttribute[name.name="dangerouslySetInnerHTML"]',
        message:
          "Cấm dangerouslySetInnerHTML (S8). Chỉ dùng cho courses.description đã qua DOMPurify — " +
          "thêm override cho đúng file trong eslint.config.mjs của app nếu thật sự cần.",
      },
    ],
  },
};

/** @type {import("eslint").Linter.Config} */
export const ignores = {
  ignores: [".next/**", "out/**", "build/**", "next-env.d.ts", "coverage/**", "playwright-report/**", "test-results/**"],
};

export default [dangerouslySetInnerHtmlBan, ignores];

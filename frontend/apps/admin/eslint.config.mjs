import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";
import { dangerouslySetInnerHtmlBan } from "@vitaminvui/config/eslint/base.mjs";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Cấm dangerouslySetInnerHTML trừ file allowlist (S8, api-contract §4).
  dangerouslySetInnerHtmlBan,
  // Allowlist (S8): MathText của quiz (FA5) chỉ nhận chuỗi HTML do katex.renderToString (trust:false) sinh ra; chữ thường đi qua React.
  { files: ["components/quiz/MathText.tsx"], rules: { "no-restricted-syntax": "off" } },
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    ".next-*/**", // build kiểm tra/e2e vào thư mục riêng (NEXT_DIST_DIR)
    "out/**",
    "build/**",
    "next-env.d.ts",
    "playwright-report/**",
    "test-results/**",
    "coverage/**",
  ]),
]);

export default eslintConfig;

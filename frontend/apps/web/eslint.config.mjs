import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";
import { dangerouslySetInnerHtmlBan } from "@vitaminvui/config/eslint/base.mjs";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Cấm dangerouslySetInnerHTML trừ file allowlist (S8, api-contract §4). Khi FW2 thêm
  // component render courses.description qua DOMPurify, thêm 1 block ở đây:
  //   { files: ["app/**/CourseDescription.tsx"], rules: { "no-restricted-syntax": "off" } }
  dangerouslySetInnerHtmlBan,
  // Allowlist (S8): đúng 1 component render courses.description (qua DOMPurify) ...
  { files: ["components/catalog/CourseDescription.tsx"], rules: { "no-restricted-syntax": "off" } },
  // ... và JsonLd (chỉ nhận object, serialize bằng jsonLd() có escape "<"; không nhận chuỗi HTML).
  { files: ["components/seo/JsonLd.tsx"], rules: { "no-restricted-syntax": "off" } },
  // ... và MathText của quiz (FW5): chỉ nhận chuỗi HTML do katex.renderToString (trust:false) sinh ra, chữ thường đi qua React.
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
    // Script k6 (không phải code ứng dụng; dùng global của k6: __ENV, __ITER, open).
    "loadtest/**",
  ]),
]);

export default eslintConfig;

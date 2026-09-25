import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";
import { dangerouslySetInnerHtmlBan } from "@vitaminvui/config/eslint/base.mjs";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Cấm dangerouslySetInnerHTML trừ file allowlist (S8, api-contract §4).
  dangerouslySetInnerHtmlBan,
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
    "playwright-report/**",
    "test-results/**",
    "coverage/**",
  ]),
]);

export default eslintConfig;

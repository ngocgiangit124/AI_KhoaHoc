---
name: feedback-vitaminvui-policy-after-validation
description: VitaminVui convention "FormRequest::authorize() always true, Policy called in controller" means validation runs before authorization — check the ordering consequence whenever a task adds the first real Policy for an endpoint
metadata:
  type: feedback
---

VitaminVui's documented convention (api-contract §1.3, quoted in `Controller.php` docblock added at T06) is: every Form Request's `authorize()` returns `true`; the controller explicitly calls `$this->authorize(...)` inside the action body. This is intentional, not a Dev mistake — but it has a side effect nobody had exercised until T06 added the project's first real `Policy` class: Laravel runs `FormRequest` validation (`rules()`) *before* the controller body executes, so it runs *before* the `$this->authorize()` call. A caller who is allowed past the coarse `role:` route middleware but denied by the fine-grained Policy (e.g. Giáo Viên hitting `PUT /admin/subjects/{id}`) gets **422** instead of **403** if their payload happens to fail validation (e.g. a duplicate/empty name) — only get 403 if the payload happens to be valid.

**Why:** Found in T06 (US-011, 2026-09-28) review. `SubjectRequest::authorize()` returns `true`; `SubjectController` calls `$this->authorize('update', $subject)` after Laravel already validated `name` uniqueness/format. The existing test only sent valid payloads as the teacher, so it always observed 403 and never exposed this ordering. The story's edge case ("Giáo viên cố gọi API tạo/sửa/xóa chuyên đề trực tiếp → bị từ chối quyền (403)") reads as an unconditional 403, which this ordering does not guarantee.

**How to apply:** Whenever a task adds the *first* Policy-backed endpoint in a given area (or reuses a FormRequest across create+update with route-model-binding available), check: does an authorized-by-middleware-but-Policy-denied role get a clean 403 even with an invalid payload, or does validation run first and leak a 422? If the story/AC implies an unconditional 403 for that role, recommend either moving the check into `FormRequest::authorize()` (route-bound model is already resolved by the time `authorize()` runs) or adding a regression test for the invalid-payload case. Don't blame the Dev for following the project's own `authorize()=true` convention — flag as SHOULD and suggest the general policy get a one-time decision from Architect/PO (ADR-004 or api-contract §1.3), since every future Policy-backed endpoint will hit the same question otherwise.

Related: [[feedback-laravel-conventions]] (project conventions that look like bugs at first glance) and [[feedback-vitaminvui-review-findings]] (checking consequences of an established pattern in a new context, not just the pattern itself).

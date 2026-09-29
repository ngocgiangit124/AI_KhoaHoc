---
name: feedback-vitaminvui-shared-test-infra-collision
description: When two parallel-worktree tasks both override the same shared test hook (e.g. Tests\TestCase::actingAs()), flag it explicitly as a merge-time collision even though each branch's tests pass independently
metadata:
  type: feedback
---

VitaminVui runs independent tasks in separate git worktrees against a shared `main`/base commit, each
with its own DB and CI run (see [[project_vitaminvui]]). When two of those parallel tasks both need to
change the *same shared test infrastructure hook* — observed twice now: T05 (ADR-003, single-student-
session) and T28 (staff login/MFA) both override `public function actingAs(...)` in
`backend/tests/TestCase.php` — each branch's own `composer ci` passes cleanly (356/pest+phpstan/pint all
green on each worktree in isolation), which gives false confidence that there's no problem. There IS a
real problem: `actingAs()` can only be defined once per class, so a straight git merge of the second
branch will either hard-conflict on that method, or (worse, if resolved carelessly by picking one side)
silently drop the other side's setup logic — causing that side's whole test suite to regress with
misleading failures (e.g. legitimate staff logging in via `actingAs()` suddenly gets treated as an
idle/not-yet-MFA session, or a student `actingAs()` suddenly fails `student.single_session` again) that
show up much later, disconnected from the actual cause.

**Why:** This was almost missed because "did the diff review find a bug in the diff" is the wrong
question here — each diff is individually correct. The bug is a *combination* problem that only exists
at merge time, invisible to any single-branch `git diff <base>...<branch>` or CI run.

**How to apply:** Whenever reviewing a task that changes `tests/TestCase.php` (or any other file every
worktree necessarily starts from the same base commit of — shared bootstrap/config touched by multiple
in-flight tasks), explicitly check `git diff <base>...<other-active-branch> -- <same file>` for every
other task currently in flight (ask the coordinator/board which ones, or check `docs/board.md` /
`.claude/worktrees/*`). If two branches both add logic to the same method/hook, write it into the review
report as its own finding (not a blocker for the branch under review, since parallelism is intentional
project policy — see [[project_vitaminvui]] — but a SHOULD for "whoever merges second must reconcile"),
and propose the concrete merged version of the hook so the merging dev doesn't have to reverse-engineer
both branches' intent from scratch. Recommend the merger add one new test that exercises *both* branches'
side effects in the same test run as a regression guard for the merge itself.

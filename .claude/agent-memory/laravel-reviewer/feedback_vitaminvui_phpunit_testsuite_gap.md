---
name: feedback-vitaminvui-phpunit-testsuite-gap
description: Always check backend/phpunit.xml <testsuites> matches every group Pest.php ->in()'s into — a directory Pest is configured for but phpunit.xml never lists is silently never run
metadata:
  type: feedback
---

`backend/tests/Pest.php` does `pest()->extend(TestCase::class)->in('Arch')` (and similarly for
`Feature`), but that alone does NOT make PHPUnit/Pest execute the directory — it only binds
which base TestCase those tests get *if* they run. Whether they run at all is controlled by
`<testsuites>` in `backend/phpunit.xml`. `tests/Arch/*` existed for a while (ModelsTest,
ControllersTest, NoApiTokensTest — S17/S24 checks) but had **zero** `<testsuite>` entry pointing
at `tests/Arch`, so `composer ci` (`vendor/bin/pest` with no `--testsuite` flag) silently skipped
all of it. `NoApiTokensTest` also had a live false-positive bug (matched `createToken(` inside
`App\Models\User`'s own docblock) that only surfaced once someone ran it directly with
`--filter`.

**Why:** this is a "CI xanh giả" trap — the test suite reports green while a whole security-
relevant Arch test group (mass-assignment guard, no-token-issuance S24) never actually executes.
Nobody caught it because `composer ci` genuinely passes; you have to compare the directories
`tests/Pest.php` binds against the directories actually listed in `phpunit.xml`'s `<testsuites>`
to notice the gap.

**How to apply:** when reviewing any change that touches `backend/phpunit.xml`,
`backend/tests/Pest.php`, or adds a new top-level `tests/<Group>/` directory, diff the set of
`->in('X')` groups in `Pest.php` against the `<testsuite><directory>` entries in `phpunit.xml`.
Any mismatch means a test group is either not running (false green) or running under the wrong
TestCase base class. Also worth spot-checking: `.claude/worktrees/*/backend/phpunit.xml` are
separate copies per task worktree and do NOT auto-inherit fixes made on `main` — when those
branches eventually merge, re-verify the merged `phpunit.xml` still has every testsuite (see
[[project_vitaminvui]]).

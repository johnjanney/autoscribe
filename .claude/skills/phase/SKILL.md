---
name: phase
description: Load the brief and plan one numbered phase of the AutoScribe plugin, then hand over a /goal condition to execute it. Use only when the user types /phase with a number.
argument-hint: [phase-number]
disable-model-invocation: true
allowed-tools: Read Grep Glob Bash(git status *) Bash(git log *) Bash(ls *)
---

# Plan phase $ARGUMENTS

## Source of truth

Read `docs/PROJECT-BRIEF.md` before anything else. It is the specification.
Section 11 lists the phases. Plan phase $ARGUMENTS only.

Section 2 contains four corrections that change the architecture. Follow them. Do not
design around them.

## What this skill does

This skill plans. It does not build. The build runs under `/goal` after I approve the plan.

1. Read the brief: section 2, section 11, and every section phase $ARGUMENTS depends on.
2. Read the existing code. Earlier phases may already be complete. Do not rewrite working code.
3. Write the plan. List every file you will create or change, and say why.
4. Name anything in the brief that is wrong, unclear, or self-contradicting. The brief is a
   draft, not a contract. Section 14 lists items already known to need verification.
5. Print the `/goal` condition for this phase from the table below, ready to paste.
6. Stop. Write no code. Wait for me.

## Goal conditions

Print the line for phase $ARGUMENTS verbatim, inside a code block, and tell me to paste it
once I approve the plan.

**Phase 1**
```
/goal ./vendor/bin/phpcs exits 0 with zero errors and zero warnings, and `wp-env run cli wp plugin activate autoscribe` then `wp plugin deactivate autoscribe` both succeed with no PHP notice, warning, or fatal in debug.log. Paste the full unedited output of all three commands each time you run them. Do not weaken phpcs.xml.dist or add ignore annotations to make phpcs pass. Stop after 25 turns and report what remains.
```

**Phase 2**
```
/goal ./vendor/bin/phpunit exits 0, the suite contains at least one test per provider adapter asserting the outgoing request shape, and one test per adapter asserting that a 401 response returns a WP_Error rather than a fatal or an uncaught exception. Every HTTP call is mocked; no test contacts a live API. Test count must not decrease from its current baseline. Paste the full phpunit output and the test count each run. Stop after 30 turns and report what remains.
```

**Phase 3**
```
/goal a single WP-CLI invocation runs one prompt end to end against a mocked provider and produces a published post that has a title, sanitized body HTML, and a featured image attachment with alt text. Prove it by pasting the CLI output and the output of `wp post get <id> --field=post_title` and `wp post meta get <id> _thumbnail_id`. wp_kses_post must be applied to the body before insert; show the line. ./vendor/bin/phpcs still exits 0. Stop after 30 turns and report what remains.
```

**Phase 4**
```
/goal ./vendor/bin/phpunit exits 0 and the Next_Run_Calculator suite covers all six schedule types plus: the second Tuesday of a month, the last weekday of a month, 31 January rolling forward, a leap day, both DST transitions in America/Chicago, and a schedule whose stored next run is in the past. Every case asserts an exact expected timestamp, not a range. Paste the full phpunit output and the calculator test count each run. Do not delete or skip a failing test to reach green. Stop after 40 turns and report what remains.
```

**Phase 5**
```
/goal ./vendor/bin/phpunit exits 0 and the suite proves three things by assertion: the budget guard blocks a run before any provider call when the month-to-date total meets the cap, a proposed topic exceeding the similarity threshold against an existing post is rejected before the body call, and each SEO adapter writes to the meta keys verified in section 7.3. Paste the full phpunit output each run. Test count must not decrease. Stop after 35 turns and report what remains.
```

**Phase 6**
```
/goal the GitHub Actions workflow runs phpcs and phpunit on PHP 8.1, 8.2, and 8.3 and passes on all three; README.md contains the third-party service disclosure required by section 8.3 naming every provider endpoint and the data sent to it; languages/autoscribe.pot exists and is non-empty; and the build script produces an installable zip. Paste the workflow run conclusion and the zip contents listing. Stop after 25 turns and report what remains.
```

## Standing rules for the goal run

These apply to every turn while the goal is active. State them back to me in the plan so I
know you have them.

- Never make a check pass by weakening the check. Do not delete tests, add skips, loosen the
  phpcs ruleset, or add suppression annotations.
- Paste real command output. The evaluator can only see what reaches the transcript. Claiming
  a pass without showing it either stalls the goal or ends it wrongly.
- Never hard-code an AI model ID where a model retirement breaks the plugin. See section 2.2.
- Sanitize every model output before it reaches the database. See section 5.2.
- Follow the WordPress Coding Standards. Escape on output. Sanitize on input. Nonce every form.
- Mock every HTTP call in tests. Never call a live provider API from a test.
- Stop and ask before adding a Composer dependency, and before doing anything the brief does
  not cover. An unanswered question is not a licence to guess.

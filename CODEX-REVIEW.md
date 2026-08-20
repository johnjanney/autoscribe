# AutoScribe code quality and security audit

## Fresh verification review — version 1.3.0

**Review date:** 19 August 2026 (America/Chicago)

**Reviewed revision:** `01f272a34a4232e52f90b45135f160d88d103580` on
`main`, tag `v1.3.0`

**Change range:** `cedb422..01f272a`

**Response reviewed:** “Response to the fifth Codex review” in
[`CODEX-REVIEW-RESPONSE.md`](CODEX-REVIEW-RESPONSE.md#L1715)

**Release reviewed:** [AutoScribe 1.3.0 on GitHub](https://github.com/johnjanney/autoscribe/releases/tag/v1.3.0)

**Current result:** **Conditional fail. Keep human review and a provider-side
spending limit enabled. Do not rely on the plugin alone for unattended automatic
publication until F130-01 is fixed.**

**Current quality score:** **8.3/10**

### Executive result

Version 1.3.0 correctly fixes the cost floor across a successful restart, adds
atomic usage counters, verifies taxonomy by reading it back, separates preview
recovery from scheduled-run recovery, and removes the repeated queue read from
the normal database-store sweep. The new tests cover the examples from the fifth
review, and all 350 tests pass.

The response is not fully correct when it says that all six findings are fixed.
Two prior findings are only partly fixed:

- F120-02 asked for every claimed write to require the current **status and
  claim**. Version 1.3.0 requires only the claim marker. A terminal sweep leaves
  that marker unchanged. The worker that the sweep closed can therefore still
  write the payload, article, post link, cost, and completed step. It can also
  pass the WordPress side-effect checks. See F130-01.
- F120-06 asked for every prompt persistence path to enforce the cross-field
  rules. The new validator observes added and updated metadata, but it does not
  observe deleted metadata. A programmatic deletion can therefore leave
  `fallback` mode with no fallback image. See F130-03.

I also found two lower-risk boundary defects. Preview recovery has no liveness
signal for the synchronous preview request, and the bulk queue query does not
verify that the database table belongs to the active Action Scheduler store. See
F130-02 and F130-04.

The response is correct about F120-01, F120-03, and the normal database-store
case of F120-05. The Action Scheduler 3.9.3 to 4.1.0 deferral is also reasonable:
there is no known advisory for 3.9.3, and a queue major-version change needs its
own dispatch and recovery tests.

I found no verified unauthenticated code execution, SQL injection, stored XSS,
capability bypass, CSRF defect, secret disclosure, or provider-URL SSRF in this
revision. The main remaining risk is recovery integrity: a terminally closed
worker is not fully fenced from later state changes or WordPress side effects.

### Verification results

| Check | Result | Evidence |
|---|---:|---|
| Revision and worktree | Pass | `HEAD`, `main`, `origin/main`, and `v1.3.0` resolve to `01f272a`. The worktree was clean before this review file changed. |
| Fifth response and changelog | Pass | The stated files contain the fifth response and the 1.3.0 change record ([response](CODEX-REVIEW-RESPONSE.md#L1715), [changelog](CHANGELOG.md#L90)). |
| Composer manifest | Pass | `composer validate --no-check-publish` reports a valid manifest ([manifest](composer.json)). |
| Dependency advisories | Pass | `composer audit --locked` reports no known advisory ([lock file](composer.lock)). GitHub reports no [open Dependabot alert](https://github.com/johnjanney/autoscribe/security/dependabot?query=is%3Aopen). |
| WordPress coding standards | Pass | PHPCS checked 125 PHP files with no error or warning ([rules](phpcs.xml.dist)). |
| Local PHPUnit | Pass | 350 tests and 1,441 assertions passed in the WordPress test container ([configuration](phpunit.xml.dist), [bootstrap](tests/bootstrap.php)). |
| Release CI | Pass | PHP 8.1, 8.2, and 8.3 passed in [CI run 32318769879](https://github.com/johnjanney/autoscribe/actions/runs/32318769879). |
| Code scanning | Pass | [CodeQL run 32318770378](https://github.com/johnjanney/autoscribe/actions/runs/32318770378) passed, and GitHub reports no [open code-scanning alert](https://github.com/johnjanney/autoscribe/security/code-scanning?query=is%3Aopen). |
| Release state | Pass | GitHub reports a published release that is not a draft or prerelease. Tag `v1.3.0` resolves to `01f272a` ([release](https://github.com/johnjanney/autoscribe/releases/tag/v1.3.0)). |
| Release asset | Pass | The uploaded and local archives are both 490,115 bytes and are byte-identical. Both have SHA-256 `2643445d2e8098d5630b89bf4ddbc7786ce6f868fd09d5a164c9254a0ad31b2d`. `unzip -t` passed, and the packaged production code and documentation match the reviewed tree ([asset](https://github.com/johnjanney/autoscribe/releases/download/v1.3.0/autoscribe-1.3.0.zip), [build script](bin/build.sh)). |
| Terminal-close fencing probe | Fail | A focused diagnostic closed a claimed run through `Run::fail(..., $expected_step)`. The original worker still reported `lost_claim() === false`, accepted `record_article()`, and changed the closed row. The temporary probe was removed after verification. See F130-01. |
| Deleted-meta validation probe | Fail | A focused WP-CLI diagnostic deleted `_autoscribe_fallback_image_id`, ran pending validation, and read back `image_mode = fallback` with an empty fallback ID. The temporary probe and its test data were removed. See F130-03. |
| Live provider call | Not run | No funded key was supplied. No prompt, site content, or secret was sent to a provider. |
| Real Action Scheduler dispatch | Not covered | **Not found in documents:** an automated test that lets Action Scheduler dispatch a complete chain ([documented limit](README.md#L252)). |
| Two-connection concurrency | Not covered | The concurrency tests simulate interleavings in one process. **Not found in documents:** a test that uses two independent database connections ([stale-worker test](tests/Pipeline/Stale_WorkerTest.php#L21)). |

### Fifth-response verdict by prior finding

| Prior finding | Verification verdict | Version 1.3.0 status |
|---|---|---|
| F120-01 — recovered paid step can settle below cost | **Fixed** | `release_claim()` raises `cost_floor` in the same conditional write, and all settlements apply it ([release](src/Pipeline/Run.php#L1046), [measurement](src/Pipeline/Run.php#L1824), [restart test](tests/Pipeline/Interrupted_ChargeTest.php#L105)). |
| F120-02 — claim does not fence all state changes | **Partly fixed** | Atomic usage increments and the replacement-worker tests are correct. Claimed writes do not require the row to remain running, so a terminally closed worker still passes the fence. See F130-01. |
| F120-03 — taxonomy success is not read back | **Fixed** | Categories are compared by ID and tags by slug after the write. Both refused relationships and deleted categories are covered ([implementation](src/Content/Taxonomy_Applier.php#L139), [tests](tests/Pipeline/Recorded_WritesTest.php#L152)). |
| F120-04 — preview is outside snapshot and recovery contracts | **Fixed for the reported malfunction** | Preview uses the model/rate snapshot, records its kind, does not enter normal finalisation, and does not re-arm the prompt. The new liveness boundary is F130-02 ([open](src/Pipeline/Generator.php#L421), [recovery](src/Pipeline/Stall_Sweeper.php#L338)). |
| F120-05 — every sweep page reads every active action | **Fixed for the active database store** | The normal path reads the active set once. Store detection is incomplete for a custom or hybrid store when the standard table still exists. See F130-04. |
| F120-06 — programmatic prompt writes bypass validation | **Partly fixed** | Added and updated meta are corrected outside the editor. Deleted meta is not observed. See F130-03. |

## Version 1.3.0 findings

### F130-01 — High — A terminally closed worker still passes the claim fence

**Category:** Concurrency, recovery, publication safety, accounting integrity

**Verified facts**

- A terminal sweep closes a run with `status = failed` and the position it
  observed, but it does not replace the claim marker
  ([terminal close](src/Pipeline/Stall_Sweeper.php#L514),
  [conditional close](src/Pipeline/Run.php#L892)).
- `holds_claim()` and `lost_claim()` compare only `runs.step` with the in-memory
  claim. They do not inspect `runs.status`
  ([claim state](src/Pipeline/Run.php#L378)).
- `record_step()`, payload writes, and generic claimed updates require `id` and
  `step`. They do not require `status = running`
  ([position](src/Pipeline/Run.php#L339),
  [payload](src/Pipeline/Run.php#L640),
  [generic updates](src/Pipeline/Run.php#L2022)).
- Finalisation claims the row and then changes the WordPress post status before
  the terminal close is known
  ([finalisation](src/Pipeline/Generator.php#L219)).
- The 1.3.0 stale-worker tests release the old claim and let a replacement take a
  new token. They do not close the run while the old token remains in `step`
  ([fixture](tests/Pipeline/Stale_WorkerTest.php#L220)).
- A focused database diagnostic reproduced the missing condition. After another
  `Run` object closed the row at the observed claim, the original object reported
  that it had not lost the claim, accepted `record_article()`, and changed the
  failed row.

**Confirmed failure sequence**

1. A worker claims a step.
2. Action Scheduler no longer reports its action as pending or running, but the
   PHP worker continues. This is the stale-worker condition that the fencing
   design already accepts as possible.
3. The run reaches its recovery limit, or its prompt is removed or disabled.
   The sweeper closes the run at the claim marker and concludes the failure.
4. The worker returns. Its claim token still equals `runs.step`, so
   `lost_claim()` returns false.
5. Its claimed writes still match the row. If it is in assembly, image handling,
   or finalisation, its WordPress side effects can also continue. In automatic
   mode, the final status write can publish a post after the run was reported as
   failed.

**Impact**

A run that recovery marked terminal can change afterwards. The run log can no
longer be treated as an immutable outcome, and a post can be created, changed,
or published after the failure path concluded. This breaks the purpose of the
claim fence and the human-review safety boundary.

**Required fix**

- Define ownership as one atomic predicate: `id = run`, `status = running`, and
  `step = claim`.
- Add `status = running` to every claimed `UPDATE`, including `record_step()`,
  payload writes, and the generic update path.
- Make `holds_claim()` and `lost_claim()` test both status and token. Prefer one
  query for the complete predicate so separate reads cannot disagree.
- Re-check the complete predicate immediately before each WordPress side effect,
  including the final post-status transition. Keep the documented compare-and-
  swap or generation-token design as the complete fix for the remaining
  check-to-write window.
- Add regression tests for terminal close followed by each stale action: run-row
  write, payload write, completed-step write, assembly, image attachment, cost
  settlement, and final publication.

### F130-02 — Low — Preview recovery cannot distinguish an abandoned preview from a live one

**Category:** Recovery, observability, configuration boundary

**Verified facts**

- Preview runs execute synchronously and never have a step action in Action
  Scheduler ([preview driver](src/Admin/Actions.php#L282)).
- The sweeper uses the absence of a pending or running step action as its liveness
  test for ordinary runs ([sweep](src/Pipeline/Stall_Sweeper.php#L227)).
- Preview recovery bypasses that per-run action check and closes the preview when
  its row is old enough ([preview branch](src/Pipeline/Stall_Sweeper.php#L330)).
- The default threshold is 15 minutes, but the public filter can reduce it to two
  minutes ([threshold](src/Pipeline/Stall_Sweeper.php#L205)).
- One preview can make two topic calls and two body calls. Each generation call
  can use the 120-second timeout ([topic loop](src/Pipeline/Step_Propose_Topic.php#L137),
  [body repair](src/Pipeline/Step_Generate_Body.php#L217),
  [timeout](src/Providers/Http.php#L34)).

**Inference**

With the threshold filter below the possible preview duration, a sweep cannot
tell a live preview from an abandoned one. It can mark the live row failed while
the request continues. The user can still receive the article, but the run log
reports a failure and later unfenced preview writes can change its cost.

**Recommended fix**

- Give previews a durable lease or request marker that the synchronous request
  clears in a `finally` block, and recover only an expired lease.
- As a smaller fix, use a separate preview threshold that is higher than the
  maximum complete preview duration. Do not apply the two-minute queued-step
  minimum to previews.
- Add a test for a live preview older than the configured queued-run threshold.

### F130-03 — Low — Deleted prompt metadata bypasses the new validator

**Category:** Configuration integrity, programmatic persistence paths

**Verified facts**

- `Prompt_Validator::register()` observes `added_post_meta` and
  `updated_post_meta`, but it does not observe `deleted_post_meta`
  ([registration](src/Prompts/Prompt_Validator.php#L104)).
- WordPress fires `deleted_post_meta` after a successful post-meta deletion
  ([WordPress `delete_metadata()` reference](https://developer.wordpress.org/reference/functions/delete_metadata/)).
- A focused WP-CLI diagnostic set `image_mode = fallback`, deleted the fallback
  ID, invoked pending validation, and read back `fallback` with an empty ID.
- Runtime image handling still fails safely and leaves the post as a draft. This
  is not an automatic-publication bypass
  ([fallback runtime](src/Pipeline/Step_Generate_Image.php#L250)).

**Impact**

WP-CLI, an importer, or another plugin can delete a watched key and leave a
stored prompt that the new save-time invariant says must not exist. Runtime
safety prevents publication without the required image, but the prompt fails
later instead of being corrected at persistence time.

**Recommended fix**

- Register `deleted_post_meta` with four accepted arguments and route its object
  ID and meta key through `note_meta_write()`.
- Add deletion tests for `fallback_image_id`, `image_mode`, `text_provider`, and
  `grounding_enabled`. The fallback-ID case must prove the correction.

### F130-04 — Low — Bulk queue detection can query a table that is not the active store

**Category:** Performance, Action Scheduler compatibility

**Verified facts**

- `active_step_runs()` decides that it can use direct SQL only from the existence
  of `actionscheduler_actions`. It does not inspect the active Action Scheduler
  store ([store decision](src/Scheduling/Scheduler.php#L298)).
- Its own documentation says that a replaced or legacy store must return `null`
  and use the public API fallback ([contract](src/Scheduling/Scheduler.php#L280)).
- Action Scheduler selects its store through `action_scheduler_store_class`, so
  the standard table can exist while another store is active
  ([locked 3.9.3 store factory](https://github.com/woocommerce/action-scheduler/blob/3.9.3/classes/abstracts/ActionScheduler_Store.php#L494-L507)).
- An ordinary enabled-run candidate omitted from the direct result is re-checked
  through the public API before recovery, so that path does not create a verified
  false recovery
  ([per-run check](src/Pipeline/Stall_Sweeper.php#L387),
  [public API](https://actionscheduler.org/api/#as_get_scheduled_actions)).

**Impact**

With a custom or hybrid store and a leftover standard table, the bulk result can
be incomplete. Correctness for an ordinary enabled run survives because
`recover()` re-checks it, but a healthy backlog can cause up to the 2,000 per-run
API reads that the bulk optimization was designed to remove. Preview and removed
or disabled-prompt branches occur before that re-check; F130-01 is the control
needed to stop their closed workers from continuing.

**Recommended fix**

- Use direct SQL only when `ActionScheduler::store()` is the compatible database
  store class. Treat hybrid, legacy, and custom stores as fallback cases.
- Add a test in which the standard table exists but the active store is not the
  database store.

### Version 1.3.0 conclusion

The 1.3.0 changes improve accounting, taxonomy integrity, preview isolation, and
normal sweep performance. The release artifact and release claims are verified,
and the standard quality and security checks pass. The remaining high finding is
not a theoretical extension of the stated residual: the database accepted a
stale write after terminal close in a focused reproduction. Fix F130-01 before
unattended automatic publication. F130-02 through F130-04 are smaller hardening
and compatibility tasks.

## Fresh verification review — version 1.2.0

**Review date:** 19 August 2026 (America/Chicago)

**Reviewed revision:** `cedb42250a31a9503ce040ff74aa11926e0d5fed` on
`main`, tag `v1.2.0`

**Change range:** `88aefb3..cedb422`

**Response reviewed:** “Response to the fourth Codex review” in
[`CODEX-REVIEW-RESPONSE.md`](CODEX-REVIEW-RESPONSE.md#L1444)

**Release reviewed:** [AutoScribe 1.2.0 on GitHub](https://github.com/johnjanney/autoscribe/releases/tag/v1.2.0)

**Current result:** **Conditional fail. Keep human review and a provider-side
spending limit enabled. Do not rely on the plugin alone for unattended automatic
publication until F120-01 through F120-04 are fixed.**

**Current quality score:** **8.0/10**

### Executive result

Version 1.2.0 makes important and correct changes. `Close_Result` separates a
lost close race from a refused database write. The stall count now has its own
column and uses a conditional update. Claimed workers protect payload and
position writes. Fallback mode now fails safely. Normal queued runs record their
models and rates. Assembly verifies most required writes. Finalisation also has
a claim. The direct documentation contradictions from the previous review are
fixed ([response](CODEX-REVIEW-RESPONSE.md#L1444),
[changelog](CHANGELOG.md#L90)).

The response is not fully correct when it says that all seven confirmed findings
are fixed. Four important gaps remain:

- A paid step that is interrupted and then restarted can still settle below its
  actual provider cost. The reservation floor is used only when the sweeper gives
  up while it can still see a claim. It is not stored for a later successful
  restart. See F120-01.
- The claim token protects `payload` and `step`, but it does not protect all run
  columns or WordPress side effects. A replaced worker can still write usage,
  post state, taxonomy, SEO data, or an image after it loses its claim. See
  F120-02.
- Taxonomy code trusts an array return as proof that relationships exist.
  WordPress core can return that array after an unchecked relationship insert.
  See F120-03.
- Preview runs do not use the new snapshot contract and do not have a safe stall
  recovery path. See F120-04.

The current Google evidence supports Claude's VR-04 rejection. The official
catalog lists `gemini-3.7-flash` as a stable model, and the current migration
guide says that it is generally available and ready for production use
([Google model catalog](https://ai.google.dev/gemini-api/docs/models),
[Google migration guide](https://ai.google.dev/gemini-api/docs/latest-model)).
The earlier VR-04 conclusion is therefore retracted. The page could have changed
between retrievals, but the current first-party evidence is clear.

I found no verified unauthenticated code execution, SQL injection, stored XSS,
capability bypass, CSRF defect, secret disclosure, or provider-URL SSRF in this
revision. The main remaining risks are accounting integrity, incomplete worker
fencing, recovery behavior, and silent taxonomy loss.

### Verification results

| Check | Result | Evidence |
|---|---:|---|
| Revision and worktree | Pass | `HEAD`, `main`, `origin/main`, and `v1.2.0` resolve to `cedb422`. The worktree was clean before this review file changed. |
| Fourth response and changelog | Pass | The stated files contain the fourth response and the 1.2.0 change record ([response](CODEX-REVIEW-RESPONSE.md#L1444), [changelog](CHANGELOG.md#L90)). |
| Composer manifest | Pass | `composer validate --no-check-publish` reports a valid manifest ([manifest](composer.json)). |
| Dependency advisories | Pass | `composer audit --locked` reports no known advisory ([lock file](composer.lock)). GitHub reports no [open Dependabot alert](https://github.com/johnjanney/autoscribe/security/dependabot?query=is%3Aopen) for this repository. |
| WordPress coding standards | Pass | PHPCS checked 120 PHP files with no error or warning ([rules](phpcs.xml.dist)). |
| Local PHPUnit | Pass | 327 tests and 1,228 assertions passed in the WordPress test container ([configuration](phpunit.xml.dist), [bootstrap](tests/bootstrap.php)). |
| Release CI | Pass | The release commit passed PHP 8.1, 8.2, and 8.3 jobs in [CI run 32315886465](https://github.com/johnjanney/autoscribe/actions/runs/32315886465). |
| Code scanning | Pass | [CodeQL run 32315885952](https://github.com/johnjanney/autoscribe/actions/runs/32315885952) passed, and GitHub reports no [open code-scanning alert](https://github.com/johnjanney/autoscribe/security/code-scanning?query=is%3Aopen). |
| Release state | Pass | The GitHub release is published, is not a prerelease, and points to tag `v1.2.0` at `cedb422` ([release](https://github.com/johnjanney/autoscribe/releases/tag/v1.2.0)). |
| Release asset | Pass | The uploaded asset and local archive are both 479,395 bytes. Both report SHA-256 `27e5b232fa739d22f6a8a1be255be0b3235764de633743a48ba4f3847c9a3efe`. `unzip -t` passed, no development test tool is present, and the non-vendor production files match the reviewed tree ([build script](bin/build.sh)). |
| Google default model | Pass by current document inspection | The two first-party pages identify `gemini-3.7-flash` as stable and generally available ([catalog](https://ai.google.dev/gemini-api/docs/models), [migration guide](https://ai.google.dev/gemini-api/docs/latest-model), [adapter](src/Providers/Text/Google.php#L71)). |
| Live provider call | Not run | No funded key was supplied. No prompt, site content, or secret was sent to a provider. |
| Real Action Scheduler dispatch | Not covered | The project documents this limit. **Not found in documents:** an automated test that lets Action Scheduler dispatch a complete chain ([README](README.md#L192)). |
| Two-connection concurrency | Not covered | The new tests simulate interleavings in one process. **Not found in documents:** a test that uses two independent database connections ([concurrency test](tests/Pipeline/Concurrent_StateTest.php#L21)). |
| Programmatic prompt-save validation | Not covered | **Not found in documents:** a test that writes prompt configuration through WP-CLI or an importer and then verifies cross-field correction. See F120-06. |

`composer outdated --direct --locked` also reports that the release uses Action
Scheduler 3.9.3 while 4.1.0 exists. There is no known security advisory for the
locked version. This is a maintenance item, not a verified defect. A major
dependency update needs its own compatibility test before use
([constraint](composer.json), [locked package](composer.lock),
[package releases](https://packagist.org/packages/woocommerce/action-scheduler)).

### Fourth-response verdict by prior finding

| Prior finding | Verification verdict | Version 1.2.0 status |
|---|---|---|
| VR-01 — failed terminal transitions and lost usage | **Partly fixed** | The three-state close and refused-close handling are correct. An interrupted charge can still be lost after a successful restart. See F120-01. |
| VR-02 — concurrent sweeper payload overwrite | **Partly fixed** | The separate counter, sweep claim, and payload/position conditions are correct. The same claim does not fence all run writes or WordPress side effects. See F120-02. |
| VR-03 — fallback can publish with no image | **Fixed for publication safety** | Runtime failure is safe and the editor corrects invalid input. The response overstates which programmatic save paths use that editor validation. See F120-06. |
| VR-04 — Google suggestion absent from catalog | **Rejected correctly on current evidence** | Current first-party pages support `gemini-3.7-flash`. The dated adapter comment and release check are useful controls. |
| VR-05 — resolved model and rate snapshot | **Partly fixed** | Normal runs opened by `Generator::open()` use the snapshot. Preview creates its run directly and uses live rates. See F120-04. |
| VR-06 — ignored assembly writes | **Partly fixed** | Run meta, run fields, and SEO writes are now checked. Taxonomy relationships are not read back. See F120-03. |
| VR-07 — finalisation has no claim | **Fixed for ordinary duplicate delivery** | The claim prevents two normal finalisers. F120-02 still applies if a worker loses its claim while it continues. |
| VR-08 — documentation contradictions | **Fixed** | The installation text, one-call claim, limitation count, and grounding warning now agree with the implementation ([README](README.md#L72), [D-09b](DECISIONS.md#L355)). |

## Version 1.2.0 findings

### F120-01 — High — A recovered paid step can still settle below its actual cost

**Category:** Financial integrity, recovery, monthly-cap enforcement

**Verified facts**

- The sweeper keeps the reservation as a floor only when it gives up while the
  observed position is still a claim
  ([give-up path](src/Pipeline/Stall_Sweeper.php#L458)).
- When the run has restart capacity, the sweeper releases the old claim, records
  only a numeric sweep count, and schedules a replacement
  ([recovery path](src/Pipeline/Stall_Sweeper.php#L385),
  [counter](src/Pipeline/Run.php#L1306)).
- No column or payload key records that the released claim may contain an
  unrecorded charge.
- A later successful finalisation calculates cost from persisted usage and
  replaces the reservation with that measured value. It does not retain a floor
  because the run was interrupted earlier
  ([measurement](src/Pipeline/Run.php#L1654),
  [settlement](src/Pipeline/Run.php#L1687)).
- The new interrupted-claim test covers only the path that has already reached
  the restart limit and is given up. It does not cover an interrupted claim whose
  replacement succeeds
  ([test](tests/Pipeline/Concurrent_StateTest.php#L195)).

**Confirmed failure sequence**

1. The budget step records the full reservation.
2. A worker claims a paid step and sends the provider request.
3. The provider processes the request, but the worker ends before it records the
   usage. The claim remains on the run.
4. The sweeper releases that claim and schedules a replacement. It does not
   store that the cost is now uncertain.
5. The replacement succeeds and records only its own usage.
6. Finalisation replaces the reservation with the replacement's measured cost.
   The first provider request is absent from the month total.

**Inference**

A provider can bill a request even when the local PHP worker ends before it
stores the response. The code and the response already use this assumption to
justify the reservation floor. The missing part is to keep that fact after a
restart succeeds.

**Impact**

The monthly total and local cap can be lower than provider billing. The cap is
therefore not conservative on the recovery path that most users want to
succeed.

**Required fix**

- Store an `usage_uncertain` or `reservation_floor` marker in the same guarded
  operation that releases an interrupted claim.
- Make every later settlement keep that floor, including successful settlement.
  A more exact implementation can store a conservative allowance for the
  interrupted step instead of the complete run estimate.
- Add a test that interrupts a paid claim, restarts it, finishes successfully,
  and verifies that settlement cannot fall below the stored floor.

### F120-02 — High — The worker claim does not fence all state changes

**Category:** Concurrency, idempotency, accounting, WordPress side effects

**Verified facts**

- Claim-aware conditions exist for payload writes and the completed-step write
  ([payload condition](src/Pipeline/Run.php#L564),
  [position condition](src/Pipeline/Run.php#L309)).
- Other run writes use `Run::update()`, whose only condition is the run ID
  ([generic update](src/Pipeline/Run.php#L1833)). This includes text usage, image
  usage, the post ID, the article identity, and settled cost
  ([text usage](src/Pipeline/Run.php#L385),
  [image usage](src/Pipeline/Run.php#L440),
  [post](src/Pipeline/Run.php#L710),
  [article](src/Pipeline/Run.php#L366),
  [cost](src/Pipeline/Run.php#L1614)).
- Step-owned terminal writes are also not tied to the claim. For example, a
  duplicate-topic result calls `skip()` inside the claimed step, and `skip()`
  closes any still-running row by ID and status
  ([duplicate close](src/Pipeline/Step_Propose_Topic.php#L189),
  [terminal transition](src/Pipeline/Run.php#L1626)).
- The image step can generate, sideload an attachment, and change the featured
  image before its final payload write detects a lost claim
  ([image step](src/Pipeline/Step_Generate_Image.php#L148)).
- The assembly step can update post content, meta, SEO fields, and taxonomy
  without checking the claim before each side effect
  ([assembly](src/Pipeline/Step_Assemble_Post.php#L141)).
- `Pipeline::advance()` checks `holds_claim()` only after the step returns. That
  check stops later pipeline work, but it cannot undo writes the stale worker
  already made ([post-step check](src/Pipeline/Pipeline.php#L199)).
- Finalisation also calls an unqualified `fail()` if the post status update
  fails. It does not first confirm that its finalisation claim still belongs to
  this worker ([finalisation failure](src/Pipeline/Generator.php#L235)).
- The new concurrency test verifies only `merge_payload()` and `record_step()`.
  It does not call usage, image, post, SEO, or taxonomy writes after claim loss
  ([test](tests/Pipeline/Concurrent_StateTest.php#L122)).
- The bundled Action Scheduler can mark an action as failed after its running
  timeout. Its default cleanup timeout is 300 seconds
  ([queue cleaner](vendor/woocommerce/action-scheduler/classes/ActionScheduler_QueueCleaner.php#L197),
  [runner cleanup](vendor/woocommerce/action-scheduler/classes/abstracts/ActionScheduler_Abstract_QueueRunner.php#L238)).

**Inference**

Most actions finish before the running timeout. The risk needs a slow worker, a
timeout or queue-state change, and a sweep. If that overlap occurs, the old
worker can continue after the replacement owns the claim. The implementation
itself treats this overlap as possible, but only two database writes use the
claim as a fencing token.

**Impact**

A stale and a replacement worker can race on token totals, image count, the
featured image, attachment creation, post content, taxonomy, or SEO state. Two
image calls can still be represented by `image_count = 1`. A stale whole-counter
write can also replace a concurrent value. A stale worker can even close the run
owned by its replacement. The final payload condition can stop later work, but
it cannot remove the duplicate provider cost or external side effects.

**Required fix**

- Make every run-row mutation from a claimed step conditional on the current
  claim token, including skip, failure, and cost transitions. Use atomic
  increments for token and image usage instead of a read-modify-write of complete
  counters.
- Check the claim again after every blocking provider or download call and before
  any WordPress side effect.
- Where a WordPress write cannot include the claim in the same SQL statement,
  use a durable fencing design. At minimum, record a monotonic worker generation
  and refuse stale post or media commits.
- Add stale-worker tests for text usage, image usage, image sideload, post
  assembly, final settlement, and taxonomy. Add a true two-connection test.

### F120-03 — Medium — Taxonomy success is not verified by reading the relationships

**Category:** Content integrity, auditability, database failure handling

**Verified facts**

- `Taxonomy_Applier::set_terms()` treats any array returned by
  `wp_set_post_terms()` as success. It does not read the assigned terms back
  ([taxonomy helper](src/Content/Taxonomy_Applier.php#L121)).
- WordPress documents an array as the normal success return
  ([WordPress reference](https://developer.wordpress.org/reference/functions/wp_set_post_terms/)).
- In WordPress core, `wp_set_object_terms()` calls `$wpdb->insert()` for a new
  relationship without checking that return. It later returns the term-taxonomy
  ID array. Therefore, a relationship insert can fail while the caller still
  receives an array
  ([official WordPress source](https://developer.wordpress.org/reference/functions/wp_set_object_terms/)).
- The same WordPress function skips a non-existent integer term ID and continues.
  It can therefore return an empty or partial array when a configured category
  was deleted after the prompt was saved. The plugin still treats that result as
  complete success
  ([official WordPress source](https://developer.wordpress.org/reference/functions/wp_set_object_terms/),
  [plugin check](src/Content/Taxonomy_Applier.php#L135)).
- The response acknowledges that this database refusal was not tested. The test
  instead makes term creation fail, which verifies the `WP_Error` branch but not
  the silent relationship-insert branch
  ([response](CODEX-REVIEW-RESPONSE.md#L1675),
  [recorded-write tests](tests/Pipeline/Recorded_WritesTest.php)).

**Impact**

The plugin can publish a post without required categories or tags while the run
reports success. This is the remaining part of VR-06.

**Required fix**

- After `wp_set_post_terms()`, clear or bypass the relationship cache and read the
  assigned term IDs back.
- Compare the actual set with the required set. Return an error if any required
  relationship is missing.
- Add a fault-injection test that refuses the relationship insert and proves that
  an array return alone is not accepted. Add a second test for a configured
  category that was deleted before the run.

### F120-04 — Medium — Preview runs are outside the new snapshot and recovery contract

**Category:** State-machine correctness, cost consistency, purpose

**Verified facts**

- Normal generation uses `Generator::open()`, which records the configuration,
  resolved models, provider slugs, and rate snapshot
  ([open](src/Pipeline/Generator.php#L336),
  [snapshot](src/Pipeline/Generator.php#L399)).
- Preview calls `Run::start()` directly. It does not record that snapshot or a
  run kind ([preview](src/Admin/Actions.php#L265)).
- Preview calls the budget, topic, and body steps directly rather than through
  the claim-owning `Pipeline`. It settles with a new live `Pricing_Table`, then
  ignores the result of `succeed()`
  ([preview sequence](src/Admin/Actions.php#L291)).
- Preview records the step name `preview`. `Pipeline::next_step()` treats an
  unknown step as finished
  ([preview position](src/Admin/Actions.php#L319),
  [unknown position](src/Pipeline/Pipeline.php#L149)).
- The stall sweeper does not distinguish preview rows from queued publication
  rows. It schedules the normal step handler, which sees no next step and enters
  normal finalisation
  ([sweeper](src/Pipeline/Stall_Sweeper.php#L321),
  [queue finish](src/Pipeline/Queued_Run_Handler.php#L273)).

**Confirmed failure sequence**

1. Preview generates an article and records `step = preview`.
2. Cost settlement or the final success write fails. Preview returns an error or
   returns the article while the run stays open.
3. The sweeper later schedules the normal queued step handler.
4. The handler treats `preview` as the end of the publication pipeline and calls
   finalisation with no post ID.
5. The result can be a spurious failure, success, notice, retry decision, or
   schedule re-arm. It is not preview recovery.

**Impact**

A database fault during Preview can change scheduled-run state and produce an
incorrect run outcome. A concurrent pricing edit can also make Preview check one
rate table and settle with another.

**Required fix**

- Record a run kind such as `preview` and include previews in the same model and
  rate snapshot contract.
- Give Preview a terminal recovery rule that only settles or closes the preview.
  Never route it into post finalisation or schedule re-arming.
- Inspect and report the `succeed()` result.
- Add tests for refused preview settlement, refused preview close, a later sweep,
  and a pricing edit during preview.

### F120-05 — Medium — Each sweeper page reads every active step action

**Category:** Performance, queue scalability

**Verified facts**

- The sweeper can scan 20 pages per pass and calls
  `runs_with_step_actions()` once for each page
  ([page loop](src/Pipeline/Stall_Sweeper.php#L227)).
- The direct Action Scheduler query selects every pending or running row for the
  AutoScribe step hook. It does not restrict the query to the page's run IDs.
  Candidate filtering happens later in PHP
  ([queue query](src/Scheduling/Scheduler.php#L243)).
- Therefore, if `A` step actions are active and `P` pages are scanned, one sweep
  can fetch and decode about `P × A` action rows. The current bounds allow up to
  20 reads of the same active action set.

**Impact**

CR-08's per-candidate query problem is fixed, but a large healthy queue can still
make the five-minute recovery task repeatedly read a large action set. This can
raise database, JSON-decoding, and memory costs on the busy sites for which the
paged sweeper exists.

**Recommended fix**

- Read the active AutoScribe action set once per sweep and reuse it for all pages,
  or store the scheduled action ID on the run and query only IDs for the current
  page.
- Include the Action Scheduler group in the direct query.
- Add a load test with 2,000 candidate runs and a large active-action set. Assert
  query count and returned-row count, not only functional results.

### F120-06 — Low — The save-time fallback guard does not cover the programmatic paths named in the response

**Category:** Documentation accuracy, configuration validation

**Verified facts**

- `Prompt_Meta_Box::save()` returns before any field or cross-field validation
  unless the editor nonce is present and valid
  ([save guard](src/Admin/Prompt_Meta_Box.php#L499)).
- `enforce_fallback_image()` runs only after that nonce-protected editor loop
  ([fallback enforcement](src/Admin/Prompt_Meta_Box.php#L572)).
- The prompt post type is not exposed through the WordPress REST API
  ([post type](src/Prompts/Prompt_Post_Type.php#L68)).
- A direct WP-CLI meta command updates one meta field and does not submit this
  editor nonce. The response's statement that REST API, WP-CLI, and imports all
  reach the save validation is therefore not accurate
  ([WP-CLI command reference](https://developer.wordpress.org/cli/commands/post/meta/update/),
  [response](CODEX-REVIEW-RESPONSE.md#L1588)).
- Runtime image handling still fails safely if programmatic data creates an
  invalid fallback configuration
  ([runtime fallback](src/Pipeline/Step_Generate_Image.php#L204)).

**Impact**

This does not reopen the publication-safety defect because runtime enforcement
is correct. It means the stored configuration can still be invalid when a tool
writes meta directly, and the response overstates the save-time guarantee.

**Recommended fix**

- Move cross-field validation into a shared prompt validator used by the editor
  and every supported programmatic write path, or document that direct meta
  writes are unsupported and rely on runtime validation.
- Correct the response record so future reviews do not treat the editor hook as
  a global meta-validation layer.

## Version 1.2.0 security, quality, performance, and purpose assessment

### Security

The security baseline remains strong. Admin mutations use nonces and the custom
capability. Provider output is validated and sanitized before rendering. HTTP
responses have size limits. Provider-supplied image URLs use
`wp_safe_remote_get()`. SQL values use prepared statements, and dynamic table or
column names use WordPress identifier placeholders. API keys use the existing
key-storage controls
([admin authorization](src/Admin/Actions.php#L396),
[content sanitizer](src/Security/Content_Sanitizer.php),
[HTTP policy](src/Providers/Http.php),
[image download](src/Media/Image_Sideloader.php#L220),
[key store](src/Security/Key_Store.php)).

No current dependency advisory, CodeQL alert, or Dependabot alert was found.
This does not prove that the code has no vulnerability. It is supporting evidence
for the manual review result. The remaining security-adjacent issue is grounded
prompt injection. The README now gives the correct residual control: use human
review for grounded prompts ([README](README.md#L256),
[instructions](INSTRUCTIONS.md#L212)).

### Code quality

The new code is readable and explains the purpose of difficult state decisions.
`Close_Result`, the separate sweep counter, the rate snapshot, `Meta_Writer`, and
the finalisation claim are good abstractions. The new fault-injection traits and
focused test classes make exceptional paths easier to verify.

The main quality weakness is the boundary of the claim abstraction. It protects
two writes, while the comments describe it as ownership of the complete step.
This mismatch makes local code look safe even when nearby run and WordPress
writes are not fenced. Preview also duplicates orchestration outside the main
state machine, which is why the new snapshot and recovery guarantees do not
apply to it.

### Performance

Normal-path work is bounded and appropriate for the plugin's purpose. Provider
response and image sizes are capped. The runs table has indexes for status,
topic, prompt/time, and month scans. Stall recovery has page and action limits.
The separate `sweeps` column removes a large JSON rewrite and the old per-run
queue lookup is gone
([schema](src/Activation.php#L188),
[sweeper bounds](src/Pipeline/Stall_Sweeper.php#L86)).

F120-05 is the main current performance issue. Also, a topic or body action can
still make two 120-second provider calls. This is documented and remains a host
compatibility limit, not a hidden defect
([README](README.md#L192), [D-09b](DECISIONS.md#L355)).

### Purpose and requirement fit

AutoScribe substantially meets its main purpose. It stores prompts, schedules
runs, uses multiple providers, validates generated content, applies images and
SEO data, records audit state, enforces local budget estimates, and supports
review or automatic publication. The 1.2.0 release is materially safer than
1.1.3.

The remaining high findings affect the two controls that unattended operation
depends on: one paid step must not be counted as less than it cost, and one
claimed worker must be the only worker that can commit its results. Review mode
reduces publication risk, but it does not correct undercounted usage or duplicate
provider and media side effects. A provider-side spending limit remains the hard
financial control.

## Version 1.2.0 remediation order

1. Fix F120-01. Persist uncertainty when an interrupted paid claim is released,
   and carry its floor through a successful restart.
2. Fix F120-02. Extend fencing to every claimed write and external side effect,
   and use atomic usage increments.
3. Fix F120-03 and F120-04. Read taxonomy back and give Preview its own snapshot
   and recovery state.
4. Fix F120-05. Read active action state once per sweep or query only the current
   candidate IDs.
5. Clarify or centralize programmatic prompt validation for F120-06.
6. Add true two-connection, real Action Scheduler dispatch, MariaDB, and live
   release-smoke coverage. Keep live provider checks outside the ordinary unit
   suite.

## Version 1.2.0 conclusion

Claude's response correctly resolves VR-03, VR-04, VR-07 in the ordinary
duplicate-delivery case, and VR-08. It also fixes large parts of VR-01, VR-02,
VR-05, and VR-06. The release, asset, CI result, CodeQL result, model evidence,
and test counts are verified.

The statement that the fourth review round is closed is premature. F120-01 and
F120-02 keep the financial and concurrency parts open. F120-03 and F120-04 keep
required-write and recovery behavior open. Use version 1.2.0 with human review
and a provider-side spending limit until those findings are fixed and verified.

---

## Verification review — version 1.1.3

**Review date:** 19 August 2026

**Reviewed revision:** `88aefb38c7d342d55e10f41b9b7ac4fa131adbee` on
`main`, tag `v1.1.3`

**Response reviewed:** the “Response to the third Codex review” and the
1.1.2/1.1.3 follow-up in
[`CODEX-REVIEW-RESPONSE.md`](CODEX-REVIEW-RESPONSE.md#L1109)

**Release records reviewed:** versions 1.1.1 through 1.1.3 in
[`CHANGELOG.md`](CHANGELOG.md#L90)

**Current result:** **Conditional fail. Do not enable unattended automatic
publication until VR-01 through VR-05 are fixed.**

**Current quality score:** **7.4/10**

### Executive result

The response correctly fixed important parts of CR-01 through CR-06 and CR-08
through CR-10. The database claim now prevents two workers from buying the same
step in the ordinary duplicate-delivery case. The code checks the required-image
result. It also checks attachment metadata, protects force review across an open
run, batches the queue lookup, and uses an atomic monthly-warning claim.

The response did not fully fix all confirmed findings. Failure paths still ignore
some terminal-write results. A failed usage write followed by a failed terminal
write can still lose a paid charge. Two sweepers can also write the complete JSON
payload without a compare-and-swap. That write can remove state that a worker
stored after the sweeper read the payload. The fallback image mode can still
publish with no fallback image.

The rejection of CR-07 is not reproducible. During this review, Google's current
model catalog lists `gemini-3.6-flash` as the latest stable Flash model. It does
not list `gemini-3.7-flash`. **Not found in documents.** The plugin still selects
`gemini-3.7-flash` first when the prompt and site default are blank
([Google adapter](src/Providers/Text/Google.php#L71),
[Google model catalog](https://ai.google.dev/gemini-api/docs/models),
[latest-model guide](https://ai.google.dev/gemini-api/docs/latest-model)).

I found no verified unauthenticated code execution, SQL injection, stored XSS,
capability bypass, CSRF defect, or provider-URL SSRF in the reviewed code. The
main risks are financial-accounting integrity, concurrent state loss, publication
without a configured fallback image, and provider-contract drift.

### Verification results

| Check | Result | Evidence |
|---|---:|---|
| Git revision and worktree | Pass | `main`, `origin/main`, and `v1.1.3` resolve to `88aefb3`. The worktree was clean before this review file changed. |
| Composer manifest | Pass | `composer validate --no-check-publish` reports a valid manifest ([manifest](composer.json)). |
| Dependency advisories | Pass | `composer audit --locked` reports no known advisory for the locked dependencies ([lock file](composer.lock)). |
| WordPress coding standards | Pass | PHPCS checked 111 PHP files with no error or warning ([rules](phpcs.xml.dist)). |
| PHPUnit | Pass | 300 tests and 1,118 assertions passed in the WordPress test container ([test configuration](phpunit.xml.dist), [bootstrap](tests/bootstrap.php)). |
| Release archive | Pass | `unzip -t build/autoscribe-1.1.3.zip` passed. The non-vendor production files match the reviewed tree ([build script](bin/build.sh)). |
| Google structured-output shape | Pass by document inspection | The adapter's top-level `response_format` object matches Google's current Interactions API contract ([adapter](src/Providers/Text/Google.php#L158), [Interactions API](https://ai.google.dev/api/interactions-api-v1), [structured-output guide](https://ai.google.dev/gemini-api/docs/structured-output)). |
| Live provider calls | Not run | No funded key was supplied. No prompt or site content was sent to a provider. |
| Real Action Scheduler dispatch | Not covered | The tests call scheduling and handler code, but no test lets the bundled scheduler dispatch the complete chain ([documented gap](README.md#L247), [pipeline test plan](docs/PIPELINE-SPLIT.md#L221)). |
| True concurrent workers | Not covered | The claim tests execute interleavings in one PHP process. They do not run two database connections at the same time ([claim test](tests/Pipeline/Write_FailureTest.php#L129), [sweeper tests](tests/Pipeline/Stall_SweeperTest.php)). |

### Response verdict by prior finding

| Prior finding | Verification verdict | Current status |
|---|---|---|
| CR-01 — paid usage can be lost | **Partly fixed** | An isolated usage-write failure stops and settles the run. A second failure of the terminal write still loses the in-memory usage. See VR-01. |
| CR-02 — two provider calls per request | **Clarified, not fixed** | The release documents the two-call behavior. `DECISIONS.md` still states the old one-call claim. See VR-08. |
| CR-03 — mutable global settings | **Partly fixed** | Site default models and force review are covered. Resolved fallback models and pricing are not stored in the run snapshot. See VR-05. |
| CR-04 — image success without a thumbnail | **Partly fixed** | Required mode and metadata verification are fixed. Fallback mode still degrades to no image if its fallback cannot attach. See VR-03. |
| CR-05 — unchecked terminal writes | **Partly fixed** | The successful finalization path checks `succeed()`. Several failure and skip paths ignore the returned value and still conclude the run. See VR-01. |
| CR-06 — no atomic step claim | **Fixed for ordinary duplicate step delivery** | `claim_step()` is a database compare-and-swap. Claim tokens protect release, and the terminal sweep close is tied to the observed position. The new sweeper payload write has a separate concurrency defect. See VR-02. |
| CR-07 — Google default model | **Response rejected on current evidence** | Google's current first-party catalog does not list `gemini-3.7-flash`. See VR-04. |
| CR-08 — one queue query per candidate | **Fixed** | The scheduler performs one action-table query per page and has a compatibility fallback ([scheduler](src/Scheduling/Scheduler.php#L243)). |
| CR-09 — duplicate monthly warning | **Fixed** | The month-specific `add_option()` insert is the claim ([budget guard](src/Cost/Budget_Guard.php#L267)). |
| CR-10 — documentation contradictions | **Partly fixed** | The README version table and main pipeline text are corrected. Other direct contradictions remain. See VR-08. |

The 1.1.2 and 1.1.3 follow-up fixes are correct for the defects that they name.
`close_at()` now compares the observed step in the same terminal update, and its
`COALESCE( step, '' )` comparison accepts both SQL `NULL` and an empty initial
position ([conditional close](src/Pipeline/Run.php#L706)). The regression test
releases a first-step claim and then closes the run at its restart limit
([regression test](tests/Pipeline/Write_FailureTest.php#L616)).

## Version 1.1.3 findings

### VR-01 — High — Failed terminal transitions still trigger downstream actions and can lose paid usage

**Category:** Financial integrity, state-machine correctness, database failure
handling

**Verified facts**

- `Run::record_text_usage()` and `Run::record_image()` now return a result and
  keep the new usage in the current object. This correctly handles an isolated
  usage-write failure when the later terminal write succeeds
  ([text usage](src/Pipeline/Run.php#L324),
  [image usage](src/Pipeline/Run.php#L379)).
- `Run::fail()`, `Run::skip()`, and `Run::succeed()` now return whether they
  closed the row. This is also correct ([skip](src/Pipeline/Run.php#L1387),
  [success](src/Pipeline/Run.php#L1468),
  [failure](src/Pipeline/Run.php#L1546)).
- The queue handler ignores the failure result when a prompt disappears, when
  the configuration changes, and when a step fails. It can then cancel,
  retry, notify, or re-arm as if the run closed
  ([disabled prompt](src/Pipeline/Queued_Run_Handler.php#L146),
  [changed configuration](src/Pipeline/Queued_Run_Handler.php#L164),
  [step error](src/Pipeline/Queued_Run_Handler.php#L184)).
- `Generator::close_failed()` also discards the `fail()` result
  ([generator](src/Pipeline/Generator.php#L434)).
- The budget step ignores the return from its budget skip and reservation-failure
  close ([budget step](src/Pipeline/Step_Budget_Check.php#L88)). The duplicate
  topic step also ignores its skip result
  ([topic step](src/Pipeline/Step_Propose_Topic.php#L176)).
- The new tests make one selected write fail. The usage test lets the later
  terminal write succeed, and the terminal test covers only the synchronous
  success close. There is no test that rejects both the usage write and the
  terminal write, or one that rejects a queued failure close
  ([usage test](tests/Pipeline/Write_FailureTest.php#L70),
  [success-close test](tests/Pipeline/Write_FailureTest.php#L97)).

**Confirmed failure sequence**

1. A provider returns a successful response and charges the account.
2. The usage update fails. The step returns `autoscribe_usage_not_recorded`.
3. The handler calls `fail()`. A continuing database fault makes this write fail
   too.
4. The handler does not inspect that result. By default, it sends the final
   failure notice and arms the next occurrence. A site filter can also classify
   the code as transient and cause a retry.
5. The run remains open. The in-memory usage disappears at the end of the PHP
   request.
6. A later sweep reads only the older persisted counters. If it closes the run,
   it replaces the reservation with a cost that omits the charged call.

**Impact**

The month total and cap can understate provider charges. The queue can also
announce and retry a run that did not make its terminal transition. This directly
contradicts the response statement that nothing is announced until the transition
succeeds ([response](CODEX-REVIEW-RESPONSE.md#L1233)).

**Required fix**

- Return a three-state close result: `closed`, `already_closed`, or
  `write_failed`. Do not collapse a database error and a lost race into the same
  Boolean.
- Make `Generator::close()` return that result. Call `conclude()` only after
  `closed`. Stand down after `already_closed`. Leave the run recoverable and
  report an operational error after `write_failed`.
- When a stalled claim can contain unrecorded paid work, do not settle it below
  its reservation unless the accounting state is known complete. A conservative
  reservation is safer than an unverified zero.
- Add fault-injection tests for each queued ending. Add one test where the usage
  update and the terminal update both fail.

### VR-02 — High — Concurrent sweepers can overwrite run payload state

**Category:** Concurrency, paid-work idempotency, provenance integrity

**Verified facts**

- `Run::merge_payload()` reads the complete JSON document, merges one key in PHP,
  and writes the complete document back. It has no version check and no step or
  claim condition ([payload merge](src/Pipeline/Run.php#L442)).
- `record_sweep()` stores the restart count through that method
  ([sweep count](src/Pipeline/Run.php#L1173)).
- Two sweepers can pass the no-action check for the same run. The code has no
  recovery claim before it calls `record_sweep()`
  ([recovery path](src/Pipeline/Stall_Sweeper.php#L321)).
- One sweeper can schedule a worker while the second sweeper still holds an old
  payload snapshot. The step claim prevents duplicate provider work, but it does
  not prevent the second sweeper from writing its old JSON document
  ([step claim](src/Pipeline/Run.php#L754),
  [schedule after payload write](src/Pipeline/Stall_Sweeper.php#L399)).

**Confirmed interleaving**

1. Sweepers A and B read payload `P` for the same idle run.
2. A records the sweep and schedules a step.
3. The worker claims the step and writes `topic`, `article`, `sources`, or
   `image` into payload `P2`.
4. B writes its old payload `P` plus its `sweeps` value.
5. The worker's new state is removed even though its write succeeded.

**Impact**

The next action can repeat a paid call, lose source provenance, lose the stored
configuration fingerprint, or fail because required intermediate state is gone.
The same read-modify-write race can also undercount restarts.

**Required fix**

- Do not keep the concurrency counter in the shared JSON document. Add a
  `sweeps` column and increment it atomically, or use a single guarded JSON update
  that changes only that key.
- Claim recovery with one conditional database update before any sweeper changes
  payload or schedules a worker.
- Make step payload writes conditional on the claim token that the worker owns.
- Add a two-connection database test for a sweeper/worker interleaving and for
  two concurrent sweepers.

### VR-03 — Medium — Fallback image mode still publishes when the fallback cannot attach

**Category:** Functional correctness, publication policy

**Verified facts**

- The project brief defines fallback mode as “Attach `fallback_image_id`. Continue
  and publish” ([brief](docs/PROJECT-BRIEF.md#L368)).
- The code correctly verifies the generated thumbnail and tries the configured
  fallback after a generation or attachment failure
  ([image step](src/Pipeline/Step_Generate_Image.php#L149)).
- If `fallback_image_id` is zero, missing, deleted, or refused by WordPress,
  `set_thumbnail()` returns false. The code then records attachment ID zero and
  continues ([fallback branch](src/Pipeline/Step_Generate_Image.php#L194),
  [verification](src/Pipeline/Step_Generate_Image.php#L249)).
- The prompt save path permits fallback mode with ID zero. It sanitizes the
  integer but performs no cross-field validation
  ([field declaration](src/Prompts/Prompt_Fields.php#L242),
  [save loop](src/Admin/Prompt_Meta_Box.php#L499)).
- The only refused-thumbnail test uses `required` mode. It does not test a refused
  fallback thumbnail ([test](tests/Pipeline/Write_FailureTest.php#L270)).

**Impact**

A prompt that explicitly requires a fallback can publish with no featured image.
The response fixed the required-mode half of CR-04, but not the fallback half.

**Required fix**

- Reject or disable fallback mode unless the ID names a readable image
  attachment.
- If the fallback cannot attach at run time, return a distinct error and leave
  the post as a draft. Do not treat fallback mode as optional mode.
- Add tests for ID zero, a deleted attachment, and a refused `_thumbnail_id`
  write.

### VR-04 — Medium — The first Google model suggestion is not in the current catalog

**Category:** Provider contract, default configuration, release accuracy

**Verified facts**

- The adapter selects `gemini-3.7-flash` first
  ([Google adapter](src/Providers/Text/Google.php#L71)).
- With blank prompt and site model values, `Model_Resolver` returns the first
  adapter suggestion. It does not try the second suggestion after a provider
  rejection ([resolver test](tests/Providers/Model_ResolverTest.php#L73)).
- Google's current catalog lists `gemini-3.6-flash` as stable and does not list
  `gemini-3.7-flash`. **Not found in documents.** Google's current migration
  guide also tells clients to migrate to `gemini-3.6-flash`
  ([model catalog](https://ai.google.dev/gemini-api/docs/models),
  [latest-model guide](https://ai.google.dev/gemini-api/docs/latest-model)).
- The response says that the same catalog lists 3.7 as “New Stable”
  ([response](CODEX-REVIEW-RESPONSE.md#L1322)). That claim does not match the page
  retrieved for this review.
- No funded live model-list request was run in either review.

**Inference**

Google can change its catalog quickly. The response may describe a transient
page state. The current release still needs a current, reproducible default. A
hard-coded first suggestion that is absent from the current catalog is not safe
as the automatic fallback.

**Required fix**

- Put `gemini-3.6-flash` first now, or require the administrator to select a model
  before Google generation.
- Add a release-time model-catalog smoke check that uses a funded test account.
  Do not run it in the ordinary offline unit suite.
- Record the retrieval date and the exact first-party URL when a model suggestion
  changes.

### VR-05 — Medium — The run configuration snapshot still excludes resolved fallbacks and pricing

**Category:** Cost consistency, configuration integrity

**Verified facts**

- The fingerprint now includes prompt fields and the two site default model
  values. This fixes the specific site-default change from CR-03
  ([fingerprint](src/Prompts/Prompt.php#L388)).
- A blank prompt model and blank site default still resolve through the adapter's
  mutable suggestion list at every paid step
  ([topic resolution](src/Pipeline/Step_Propose_Topic.php#L101),
  [body resolution](src/Pipeline/Step_Generate_Body.php#L103),
  [image resolution](src/Pipeline/Step_Generate_Image.php#L98)).
- The fingerprint does not store the resolved model IDs. A plugin upgrade can
  change an adapter suggestion without changing the fingerprint.
- The fingerprint also excludes the pricing table. The budget check uses the
  table at the first step, and finalization constructs a new table later
  ([estimate](src/Cost/Budget_Guard.php#L312),
  [finalization](src/Pipeline/Queued_Run_Handler.php#L258)).
- The original required fix asked for resolved models and relevant pricing
  rates in the run snapshot ([original CR-03](CODEX-REVIEW.md#L1446)). The response
  discusses only site defaults and force review
  ([response](CODEX-REVIEW-RESPONSE.md#L1202)).

**Impact**

An upgrade or pricing edit can make later steps use a model or rate set that the
run did not use at its budget check. This can mix models in one article and can
change settlement under an open reservation.

**Required fix**

- At run open, store the resolved text model, resolved image model, provider
  slugs, and the rate rows used for the estimate.
- Use those stored model IDs for all paid steps.
- Settle with the same stored rate snapshot. Apply new rates to the next run.
- Add tests that change adapter suggestions and the pricing option between
  queued actions.

### VR-06 — Medium — Post assembly still ignores required audit and taxonomy writes

**Category:** Auditability, content integrity, write-failure handling

**Verified facts**

- The brief requires `_autoscribe_run_id` on every generated post because it
  links the post to its run ([brief](docs/PROJECT-BRIEF.md#L587)).
- Assembly writes `_autoscribe_run_id` and `_autoscribe_topic_key` but does not
  inspect or verify either result
  ([assembly](src/Pipeline/Step_Assemble_Post.php#L200)).
- `Run::record_article()` also returns `void` and discards its database update
  result ([run log](src/Pipeline/Run.php#L305)). Assembly does not check it
  ([caller](src/Pipeline/Step_Assemble_Post.php#L243)).
- Category and tag assignment discards `wp_set_post_terms()` errors
  ([taxonomy](src/Content/Taxonomy_Applier.php#L39)). SEO adapters also return
  `void` after unverified meta writes
  ([SEO contract](src/SEO/SEO_Adapter_Interface.php#L67)).

**Impact**

The plugin can publish a post with no run link, no exact duplicate key, missing
taxonomy, or missing SEO metadata while the run reports success. The missing run
link breaks the audit trail that the feature exists to provide.

**Required fix**

- Read back the run ID and topic key after each meta write. Treat a missing run
  ID as terminal and leave the post in draft.
- Make `record_article()`, taxonomy application, and SEO application return a
  result. Define which failures are terminal and which are warnings.
- Add focused write-refusal tests for post meta, terms, and each SEO adapter.

### VR-07 — Low — Finalization has no claim

**Category:** Concurrency, duplicate WordPress side effects

**Verified facts**

- Every pipeline step has a position claim, but the finalization tail runs after
  `Pipeline::next_step()` returns null. It takes no claim
  ([pipeline](src/Pipeline/Pipeline.php#L181),
  [queue finish](src/Pipeline/Queued_Run_Handler.php#L249)).
- Two final actions can both call `wp_update_post()` and `settle_cost()` before
  one wins the final `succeed()` transition
  ([finalize](src/Pipeline/Generator.php#L184)).
- The final status transition prevents duplicate review mail and duplicate
  scheduling. It does not prevent duplicate `wp_update_post()` hooks or duplicate
  cost writes before that transition.

**Impact**

No second provider charge occurs. However, plugins that react to post updates or
publication can run twice, and the losing finalizer can overwrite the cost before
it loses the close race.

**Recommended fix**

- Add a finalization claim or include finalization as a claimed pipeline step.
- Add a duplicate-finalizer test that counts post-transition hooks, settlement
  writes, notices, and re-arms.

### VR-08 — Low — Release documentation still contains direct contradictions

**Category:** Documentation accuracy, security disclosure

**Verified facts**

- The README version table says 1.1.3, but the installation section still tells
  users to download `autoscribe-1.0.0.zip`
  ([version](README.md#L14), [installation](README.md#L73)).
- `DECISIONS.md` still says each queued request has at most one provider call
  ([D-09b](DECISIONS.md#L355)). The README and pipeline document correctly say a
  step can make two calls ([README](README.md#L226),
  [pipeline document](docs/PIPELINE-SPLIT.md#L240)).
- The README says only two brief requirements are knowingly unmet. The same
  section also records that Run now does not stream and that the similarity
  default differs from the brief ([README](README.md#L192)).
- `Untrusted_Block` says the README recommends review mode for grounding
  ([code comment](src/Security/Untrusted_Block.php#L31)). The actual warning and
  recommendation are in `INSTRUCTIONS.md`, not the README
  ([instructions](INSTRUCTIONS.md#L212)).

**Impact**

Users can download an old release, maintainers can rely on a false performance
bound, and the main repository page does not contain the grounding-specific
prompt-injection warning that the code says it contains.

**Recommended fix**

- Change the installation filename to 1.1.3 or remove the version from the link.
- Correct D-09b.
- Replace “two requirements” with a complete deviation list.
- Put the grounding residual-risk warning in the README and link to the longer
  instructions.

## Security, quality, performance, and purpose assessment

### Security

The security baseline is good. Admin handlers use nonces and the custom
capability ([admin actions](src/Admin/Actions.php#L396)). Model HTML passes through
a narrow allowlist after executable blocks and dangerous attributes are removed
([sanitizer](src/Security/Content_Sanitizer.php#L60)). Provider JSON and image
downloads have response limits, and the image URL uses `wp_safe_remote_get()`
([HTTP policy](src/Providers/Http.php#L44),
[image download](src/Media/Image_Sideloader.php#L193)). The CI actions use fixed
commit hashes ([CI](.github/workflows/ci.yml#L39)).

The remaining security-related risks are business-logic risks. VR-01 can
understate paid usage. VR-02 can remove provenance and idempotency state.
Grounded provider search remains a prompt-injection surface because the plugin
does not see retrieved page text before the model reads it. Human review is the
effective residual control ([instructions](INSTRUCTIONS.md#L212)).

### Code quality

The code is readable and has unusually clear reasons for difficult decisions.
The database claim, monotonic review rule, compatibility fallback for Action
Scheduler stores, and read-back verification for WordPress's ambiguous Boolean
APIs are sound ideas.

The main weakness is inconsistent state-transition discipline. Some writes have
strong compare-and-swap rules, while nearby writes still return `void` or have
their result discarded. The 1.1.1 response correctly identifies this pattern,
but the audit did not apply it to every terminal path, payload writer, post meta
write, taxonomy write, and SEO write.

### Performance

CR-08 is fixed. A sweep uses one queue-table query per page instead of one query
per candidate ([scheduler](src/Scheduling/Scheduler.php#L243)). The scan and
recovery batch are bounded ([sweeper](src/Pipeline/Stall_Sweeper.php#L86)).

The main performance limit is documented: a topic or body step can make two
120-second calls in one request ([README](README.md#L226)). VR-02 can add repeat
calls after state loss. The Action Scheduler fallback still performs one lookup
per run on a legacy or substituted store; this is a deliberate compatibility
trade-off, not a new defect.

### Purpose and requirement fit

AutoScribe substantially implements its stated purpose. It schedules prompts,
generates and validates content, handles review or publication, adds images,
records runs, applies budgets, and provides provider and admin controls. The
normal path is strong and the automated unit/integration suite is broad.

The release is not ready for unattended automatic publication because the
remaining defects are on exceptional paths where money, state, and publication
policy meet. Review mode reduces content risk, but it does not correct missing
usage, overwritten run state, or a missing configured fallback image.

## Remediation order

1. Fix VR-01. Make every terminal transition result authoritative before retry,
   notification, or re-arm.
2. Fix VR-02. Remove the sweep counter from whole-document payload updates and
   add a recovery claim.
3. Fix VR-03 and VR-04. Enforce fallback-image policy and replace the unsupported
   Google default.
4. Fix VR-05. Store resolved models and rate rows at run open.
5. Fix VR-06 and VR-07. Verify post metadata and claim finalization.
6. Fix VR-08 and add the missing concurrent, terminal-failure, fallback-image,
   live provider, archive-activation, MariaDB, and real Action Scheduler tests.

## Version 1.1.3 conclusion

The response made material improvements, and versions 1.1.2 and 1.1.3 corrected
two real defects in the new concurrency guard. However, the statement that all
nine confirmed findings are fixed is not accurate for the current tree. CR-01,
CR-03, CR-04, CR-05, and CR-10 remain partly open. CR-07 must also be reopened
against the current first-party Google catalog.

Use version 1.1.3 only with human review and a provider-side spending limit until
VR-01 through VR-05 are fixed and verified with concurrent and failure-injection
tests.

---

**Audit date:** 17 August 2026  
**Reviewed revision:** `97e2e3e` on `main`  
**Release under review:** 1.0.0  
**Audit result:** **Conditional fail. Do not treat this release as production-ready.**

> **Follow-up:** The section below reviews revision `6f844ce`, tagged and
> published as version 1.0.1. It supersedes the original executive result where
> the two sections differ. The original 1.0.0 audit remains below as a historical
> record.

> **Fresh review:** The next section reviews revision `076b6dd`, tagged as
> version 1.1.0. It supersedes both earlier reviews where the results differ.

## Fresh review — version 1.1.0

**Review date:** 19 August 2026

**Reviewed revision:** `076b6dd70ea3091780137639683d4a5ea86b2c47` on
`main`, tag `v1.1.0`

**Documents reviewed:** `docs/PROJECT-BRIEF.md`, `docs/PIPELINE-SPLIT.md`,
`CHANGELOG.md`, `CODEX-REVIEW-RESPONSE.md`, `README.md`, `INSTRUCTIONS.md`,
`DECISIONS.md`, the production code, the tests, and the 1.1.0 release archive

**Current result:** **Conditional fail. Do not use version 1.1.0 for unattended
automatic publication until CR-01 through CR-06 are fixed.**

**Current quality score:** **7.2/10**

### Executive assessment

Version 1.1.0 is a large and useful improvement. Scheduled runs now persist
their intermediate state and advance through separate Action Scheduler actions.
The new stall sweeper can recover a run after a terminated request. The named
database lock fixes the main concurrent budget-reservation race from version
1.0.1. Draft adoption is much safer. The response-size, image-size, key-storage,
content-sanitization, nonce, capability, and HTTP controls remain good.

The release does not yet meet all of its main claims. A topic action can make
two provider calls. A body action can also make two provider calls. Therefore,
the release claim that each queued request makes at most one provider call is
false. A 30-second host can still terminate a request during either 120-second
call. The stall sweeper limits the damage, but it can repeat a paid call after
the termination.

The most important new security-related defect is in cost recording. The code
still ignores failed database writes for text-token and image usage. A provider
can charge for a successful call, a later payload write can succeed, and the
next action can finish the run without the missing usage. The monthly spend
total and budget warning then understate the charge.

The final result for the project brief is:

| Question | Assessment |
|---|---|
| Does the plugin achieve the brief? | **Substantially, but not completely.** The major features exist. The queue split now exists. Run now still does not stream, the next-run display is not live, and some pipeline and threshold choices intentionally differ from the brief. |
| How well does it achieve the brief? | **Good structure, good routine-path coverage, and weak failure-path assurance.** The normal path is well designed. Database-write failures, duplicate queue delivery, live provider contracts, and the real Action Scheduler path need more work. |
| Is there drift? | **Yes.** The one-call-per-request claim is false. Changelog and README text about Run now conflict with the code and with `DECISIONS.md`. The README version is still 1.0.0. The current Google model documents also conflict with the first suggested Google model. |
| Are there security vulnerabilities? | **Yes, but no critical remote exploit was found.** CR-01 can bypass financial accounting after an isolated database-write failure. CR-03 can apply a changed global model after the budget check. CR-06 permits duplicate paid effects if the same run step executes concurrently. Prompt injection remains a residual content-integrity risk when automatic publication is enabled. |

### Verification results

| Check | Result | Evidence |
|---|---:|---|
| Git state | Pass | `main`, `origin/main`, and tag `v1.1.0` resolve to `076b6dd`. The worktree was clean before this review file changed. |
| Composer manifest | Pass | `composer validate --no-check-publish` reports a valid manifest. |
| Dependency advisories | Pass | `composer audit --locked` reports no known security advisory. |
| WordPress coding standards | Pass | PHPCS checked 110 files with no error. |
| PHPUnit | Pass | 286 tests and 1,066 assertions passed with PHP 8.1, WordPress, and MySQL. |
| Release archive | Pass | `build/autoscribe-1.1.0.zip` passed `unzip -t`. Its production source matched the reviewed source. Its Composer autoload files correctly differ because the archive contains production dependencies only. |
| Live provider calls | Not run | No funded provider key was supplied. No prompt or site data was sent to a provider. |
| Real Action Scheduler dispatch | Not covered | Tests call handlers and scheduling wrappers with mocks. They do not execute the complete bundled queue path. `docs/PIPELINE-SPLIT.md` also records this gap ([pipeline plan](docs/PIPELINE-SPLIT.md#L234)). |
| Concurrent duplicate step | Not covered | No test starts two workers on the same `run_id`. See CR-06. |
| Database-write fault injection | Not covered | No test makes only the usage or terminal-state update fail. See CR-01 and CR-05. |

### Security finding order

There is no verified critical vulnerability. I found no unauthenticated remote
code execution, SQL injection, stored cross-site scripting, privilege bypass,
or CSRF defect in the reviewed code. The findings below use security severity
when confidentiality, integrity, availability, or financial control is at risk.
They use quality severity for requirement, reliability, and documentation drift.

1. **High — CR-01:** paid usage can be lost after a database-write failure.
2. **High — CR-03:** mutable global model settings are not part of the run
   configuration snapshot and can invalidate the budget check.
3. **Medium — CR-06:** queued steps have no atomic run claim, so concurrent
   duplicate actions can repeat paid effects.
4. **Residual risk:** provider search results and local titles can still contain
   prompt-injection text. The delimiter reduces the risk but cannot remove it.
   Review mode remains the correct default.

## Fresh findings

### CR-01 — High — A successful paid call can be omitted from cost accounting

**Category:** Security business logic, financial integrity, database failure
handling

**Verified facts**

- `Run::record_text_usage()` returns `void` and discards the result of the
  database update ([Run](src/Pipeline/Run.php#L319)).
- `Run::record_image()` does the same ([Run](src/Pipeline/Run.php#L359)).
- The topic step, both body calls, and the image step call these methods without
  a result that they can check ([topic step](src/Pipeline/Step_Propose_Topic.php#L148),
  [body step](src/Pipeline/Step_Generate_Body.php#L159),
  [body repair](src/Pipeline/Step_Generate_Body.php#L232),
  [image step](src/Pipeline/Step_Generate_Image.php#L115)).
- The same steps then write payload state. Those later writes do return an error
  on failure. An isolated usage-write failure does not prevent a later payload
  write from succeeding.
- A new `Run` object is loaded for the next queued action. It reads usage from
  the database. It cannot recover usage that existed only in the earlier PHP
  object.
- `Run::update()` explicitly states that only the reservation caller acts on a
  failed update ([Run](src/Pipeline/Run.php#L1260)).

**Confirmed failure sequence**

1. The provider returns a successful topic, body, repair, or image response.
2. The provider has charged the account.
3. The usage-column update returns `false`.
4. The payload and `runs.step` updates succeed.
5. The next queue action loads the run. It sees the persisted output but not the
   missing tokens or image count.
6. Settlement uses the incomplete usage and records too little cost.

**Impact**

The run log, month-to-date spend, 80-percent warning, and later cap checks can
all understate real provider charges. This is a fail-open financial control.
The condition requires a database update to fail in isolation. It is not a
verified remote attack path, but the result directly breaks the cap's integrity.

**Required fix**

- Make `record_text_usage()` and `record_image()` return `bool` or `WP_Error`.
- Stop the step when the usage write fails. Close the run in the same request so
  settlement can use the in-memory usage that the provider already charged.
- If the terminal write also fails, preserve a durable recovery record or keep
  the run open for the sweeper. Do not report success.
- Add fault-injection tests for the proposal, first body, repair, and image
  usage writes. Assert that each charge remains in `cost_cents`.

### CR-02 — High — The queue split does not provide one provider call per request

**Category:** Objective completion, performance, timeout resilience, drift

**Verified facts**

- The 1.1.0 changelog says that each request carries at most one provider call
  and that a 30-second host no longer kills an article part-way
  ([changelog](CHANGELOG.md#L97)).
- The pipeline design says the same ([pipeline plan](docs/PIPELINE-SPLIT.md#L242)).
- The topic action contains a loop that can call the provider twice
  ([topic step](src/Pipeline/Step_Propose_Topic.php#L133)).
- The body action makes an initial call and can make a repair call in the same
  request ([body step](src/Pipeline/Step_Generate_Body.php#L153),
  [repair call](src/Pipeline/Step_Generate_Body.php#L215)).
- Each provider call has a 120-second HTTP timeout. One topic or body action can
  therefore spend up to approximately 240 seconds in provider calls, before
  local work.
- `INSTRUCTIONS.md` correctly says that a hard 30-second limit can still
  terminate a step ([instructions](INSTRUCTIONS.md#L335)). This contradicts the
  changelog.

**Impact**

The main version 1.1.0 objective is only partly complete. The split reduces the
size of a failure, but it does not give the documented request bound. A host can
terminate the action after the provider has charged it and before the result is
stored. The sweeper can then repeat the call. The same action can also occupy a
queue worker for four minutes.

**Required fix**

- Persist proposal-attempt and repair-attempt state as separate pipeline
  positions. Schedule the second proposal or repair as a new action.
- Set a per-action provider-call count of one and test it.
- Do not claim that a 30-second limit is safe while the provider timeout is 120
  seconds. State that the sweeper recovers the run after a termination.
- If the current two-call actions are intentional, revise the objective and all
  release text. That is a documented deviation, not completion of the current
  design claim.

### CR-03 — High — The run fingerprint excludes global settings that control cost and publication

**Category:** Financial integrity, publication safety, configuration consistency

**Verified facts**

- The fingerprint contains only prompt fields
  ([Prompt](src/Prompts/Prompt.php#L387)).
- A blank prompt model resolves through the mutable site default
  ([Model Resolver](src/Providers/Model_Resolver.php#L42)).
- The budget estimate and each later provider step resolve that default again.
  The run does not store the resolved model IDs.
- The final publication status reads the current global `force_review` value
  ([Generator](src/Pipeline/Generator.php#L552)). It does not retain the value
  from the start of the run.
- The queue handler compares only the prompt fingerprint before each step
  ([Queued Run Handler](src/Pipeline/Queued_Run_Handler.php#L190)).

**Confirmed behavior**

If an administrator changes a site default model after `budget_check`, a later
topic, body, or image step can use the new model. The estimate was made for the
old model. The run can also contain output from different model settings.

If `force_review` was on when the run started and an administrator turns it off
before finalization, an `auto` prompt can publish. The prompt fingerprint does
not detect the change. This does not bypass the current value of the switch,
because an administrator changed it. It does violate the code's stated rule
that a queued run must not finish under settings that were not checked.

**Required fix**

- Store a run configuration snapshot that contains the resolved text model,
  resolved image model, provider slugs, relevant pricing version or rates, and
  the initial review policy.
- Use the stored model IDs for all later calls, or abort when a relevant global
  setting changes.
- Make the review rule monotonic for an open run: hold the post as a draft if
  force review was on at run start **or** is on at finalization.
- Add tests that change global defaults and force-review state between actions.

### CR-04 — Medium — Required and fallback image modes can report success without a featured image

**Category:** Functional correctness, content integrity

**Verified facts**

- The generated-image path ignores the result of `set_post_thumbnail()` and
  then records the attachment as settled
  ([image step](src/Pipeline/Step_Generate_Image.php#L169)).
- The fallback path also ignores the result
  ([image step](src/Pipeline/Step_Generate_Image.php#L186)).
- Re-entry ignores the result a third time
  ([image step](src/Pipeline/Step_Generate_Image.php#L236)).
- WordPress documents that `set_post_thumbnail()` returns `false` on failure.
  It can also return `false` when the same value is already present
  ([WordPress `set_post_thumbnail()` reference](https://developer.wordpress.org/reference/functions/set_post_thumbnail/)).
- `Image_Sideloader` also ignores the result of attachment metadata generation
  and update ([Image Sideloader](src/Media/Image_Sideloader.php#L142)).

**Impact**

A prompt in `required` mode can continue and publish even when WordPress did not
set the featured image. A fallback prompt can also continue without its fallback
image. The payload then prevents a later action from trying again.

**Required fix**

- Check metadata generation and update results.
- After each thumbnail write, verify that `get_post_thumbnail_id( $post_id )`
  equals the required attachment ID. This handles the valid "already equal"
  return case.
- Return an error for required mode when verification fails. Apply the stated
  fallback or optional policy only after a verified failure.
- Add tests for metadata failure, thumbnail-write failure, an invalid fallback
  ID, and the idempotent already-equal case.

### CR-05 — Medium — Finalization and terminal run writes can fail while the queue reports completion

**Category:** State integrity, recovery, notification correctness

**Verified facts**

- `settle_cost()` returns the calculated amount even if `record_cost()` fails
  ([Run](src/Pipeline/Run.php#L1187)).
- `succeed()` discards its database result
  ([Run](src/Pipeline/Run.php#L1202)).
- `fail()` and `skip()` also discard their results
  ([Run](src/Pipeline/Run.php#L1228), [Run](src/Pipeline/Run.php#L1119)).
- `Generator::finalise()` calls settlement and success, then sends notices and
  returns a success array without verifying either write
  ([Generator](src/Pipeline/Generator.php#L204)).
- The queue handler then concludes the run and arms the next occurrence.

**Impact**

A post can publish while its run remains `running`. The stall sweeper can later
restart that row and finalise it again. Review mail, budget mail, and schedule
changes can occur more than once. A failed settlement can leave the original
reservation in place. A failed `fail()` or `skip()` can also leave an open row
while the handler acts as if it is closed.

**Required fix**

- Make every terminal transition return a checked result.
- Use one conditional database update for cost, status, error, and
  `finished_at`. Require the current status to be `running`.
- Do not send notices or re-arm the prompt until the terminal state is durable.
- Add one-write-failure tests for success, failure, skip, and settlement.

### CR-06 — Medium — Step idempotency is not atomic under duplicate delivery

**Category:** Financial integrity, queue concurrency, idempotency

**Verified facts**

- `schedule_step()` does not request a unique Action Scheduler action. The
  Action Scheduler API defaults the `unique` parameter to `false`
  ([Scheduler](src/Scheduling/Scheduler.php#L194),
  [Action Scheduler API](https://actionscheduler.org/api/)).
- The handler checks `status`, then reads the current step, performs the work,
  and writes the new step. There is no per-run lease, lock, or compare-and-swap
  claim ([Queued Run Handler](src/Pipeline/Queued_Run_Handler.php#L119)).
- Each idempotency guard is a read followed later by a provider or post side
  effect. Two workers can both read the same missing payload key before either
  writes it.
- The test suite re-enters steps sequentially. It does not execute the same
  `run_id` concurrently.

**Inference from the verified code**

If two pending actions exist for one run, both workers can pass the status and
payload checks. They can make duplicate provider calls. They can also race on
usage totals and payload merges. Action Scheduler normally prevents two workers
from claiming one action row, but it does not make two separate action rows for
the same run into one row. The scheduler wrapper currently permits those rows.

**Required fix**

- Request unique step scheduling as a first guard.
- Add an atomic run-step claim. Store a lease or use a conditional update that
  succeeds for only one worker at the expected step version.
- Make usage accumulation atomic in SQL, or keep it under the same run claim.
- Add a two-worker integration test for topic, body, image, and finalization.

### CR-07 — Medium — The first Google model suggestion is not in the current model catalog

**Category:** Provider-contract drift, operational reliability

**Verified facts**

- The first Google suggestion is `gemini-3.7-flash`
  ([Google adapter](src/Providers/Text/Google.php#L82)). A blank prompt model and
  blank site default select this first suggestion.
- On 19 August 2026, Google's current model catalog lists `gemini-3.6-flash`,
  `gemini-3.5-flash`, and `gemini-3.5-flash-lite` as stable text models. It does
  not list `gemini-3.7-flash`
  ([Google Gemini model catalog](https://ai.google.dev/gemini-api/docs/models)).
- Google's current deprecation table also does not list 3.7
  ([Google Gemini deprecations](https://ai.google.dev/gemini-api/docs/deprecations)).
- A different first-party migration page still contains 3.7 in examples
  ([Google Interactions breaking-change guide](https://ai.google.dev/gemini-api/docs/interactions-breaking-changes-may-2026)).
- `CODEX-REVIEW-RESPONSE.md` says that the latest-model and model-catalog pages
  listed 3.7 when retrieved on 18 August. Those pages have changed since that
  response.
- No live Models API request with a funded key was run.

**Assessment**

Google's current documents conflict. Therefore, this review does not claim that
the model endpoint is definitively removed. Its status is **not found in the
current model catalog**. It is not safe to present it as the current first
generally available choice without a live capability check.

**Required fix**

- Put `gemini-3.6-flash` first until the current model endpoint confirms 3.7.
- Treat suggestions as updateable release data. Add a release check against
  each provider's current first-party model catalog.
- Keep the user-editable model field and connection test. They are good controls
  for this fast-moving dependency.

### CR-08 — Medium — The stall sweep can issue about 2,000 Action Scheduler queries every five minutes

**Category:** Performance, database load, scalability

**Verified facts**

- One sweep can read 20 pages of 100 runs
  ([Stall Sweeper](src/Pipeline/Stall_Sweeper.php#L102)).
- For every candidate, it calls `has_step_action()`
  ([Stall Sweeper](src/Pipeline/Stall_Sweeper.php#L244)).
- `has_step_action()` performs a separate Action Scheduler query
  ([Scheduler](src/Scheduling/Scheduler.php#L243)).
- A sweep runs every five minutes
  ([Stall Sweeper](src/Pipeline/Stall_Sweeper.php#L59)).

**Impact**

On a busy or backed-up site, one sweep can make approximately 2,000 queue-store
queries plus its run-page queries. This is an N+1 query design. The code comment
calls the bounded sweep a short request, but 2,000 database round trips can be
slow enough to compete with the queue that it monitors.

**Required fix**

- Fetch pending and running step actions for a page of run IDs in one query, or
  use a small recovery table or heartbeat column that can be joined in bulk.
- Add a composite run index suitable for the sweep query, after checking the
  real MySQL query plan.
- Add a query-count performance test for a full page and for the maximum scan.

### CR-09 — Low — The monthly warning is not exactly once under concurrency

**Category:** Notification correctness, concurrency

**Verified facts**

- `should_send_warning()` reads the option, updates it, and then returns `true`
  ([Budget Guard](src/Cost/Budget_Guard.php#L277)).
- The read and update are not atomic. Concurrent finalizers can both read the old
  month before either writes the new month.

**Impact**

Two concurrent runs can send more than the one monthly warning required by the
brief. This does not change the spend cap.

**Required fix**

Claim the month with an atomic insert or compare-and-swap operation. Send mail
only for the worker that acquired the claim. Add a concurrent test.

### CR-10 — Medium — Release documentation contains direct contradictions

**Category:** Release quality, user guidance, requirements drift

**Verified facts**

- The README still reports version 1.0.0 while the plugin and tag are 1.1.0
  ([README](README.md#L14)).
- The changelog says Run now and Preview both answer in the request that asked
  ([changelog](CHANGELOG.md#L103)). The code queues Run now and redirects
  ([Actions](src/Admin/Actions.php#L221)).
- A later changelog entry says Run now keeps the synchronous path
  ([changelog](CHANGELOG.md#L306)). It does not.
- `DECISIONS.md` and `INSTRUCTIONS.md` correctly state that Run now queues
  ([decisions](DECISIONS.md#L377), [instructions](INSTRUCTIONS.md#L343)).
- README also says that Run now and Preview both answer in the same request
  ([README](README.md#L214)).
- The one-provider-call claim conflicts with CR-02.

**Required fix**

- Change the README version to 1.1.0.
- State that Preview is synchronous and Run now is asynchronous.
- Replace the one-call claim with the actual current maximum, or split the
  second calls into new actions.
- Keep the known deviations in one current section. Do not make users reconcile
  README, changelog, decisions, and instructions themselves.

## Current requirements drift

The following deviations remain after version 1.1.0. Most are already disclosed
in `DECISIONS.md` or README. Disclosure makes the project honest, but it does not
make the brief complete.

| Brief item | Current state | Assessment |
|---|---|---|
| One short Action Scheduler request per generation step | Steps are queued separately, but topic and body can each make two provider calls. | **Partial; CR-02** |
| Step order includes Gather Context, then image, then assembly | No separate Gather Context step exists. Assembly intentionally precedes image so required-image failure leaves a draft. | **Documented design change** |
| Run now queues and streams the result | Run now queues and redirects to the editor. It does not stream progress or the result. | **Open, documented in D-19** |
| Live next-run readout | The readout shows saved state and does not update while controls change. | **Open, documented** |
| Duplicate threshold defaults to 82 percent | The code defaults to 78 percent. | **Intentional drift** |
| README screenshot | **Not found in documents.** README states that no screenshot is present. | **Open** |
| Plain clone is directly usable | Composer production dependencies must be installed, or the release archive must be used. | **Documented installation constraint** |
| Action Scheduler integration test | Handler and wrapper tests exist. No test dispatches the bundled queue end to end. | **Open test gap** |

## Code quality assessment for version 1.1.0

### Strengths

- The new `Pipeline` class gives both drivers one ordered step definition.
- Queue state is persisted in the run row. A fresh handler can resume it.
- Payload writes now merge top-level keys and check most state failures.
- The stall sweeper distinguishes a slow run from a run with no pending or
  running step action.
- The named MySQL lock correctly serializes the normal budget check and
  reservation path.
- Draft adoption has strict attempt, ownership, status, and modification checks.
- The HTTP layer limits JSON responses. The media layer limits bytes and decoded
  pixels and uses `wp_safe_remote_get()` for provider URLs.
- Provider content is validated and post HTML is sanitized before insertion.
- Admin mutation paths use nonces and the custom management capability.
- Code comments explain complex state decisions. PHPCS is clean.
- The test count increased from 200 to 286, with good new coverage for
  sequential step resume and stall recovery.

### Weaknesses

- The code checks some critical database writes but still ignores usage and
  terminal-state writes. This creates inconsistent failure policy.
- Idempotency guards are sequential guards, not atomic concurrency controls.
- The queue split moved retry state into payload, but it did not split all
  provider calls into separate requests.
- Several comments and release claims state a stronger property than the code
  supplies.
- Global mutable dependencies are resolved repeatedly instead of being stored
  on the run.
- The stall sweep favors completeness over database efficiency and creates an
  N+1 query pattern.
- Provider contract tests use mocks only. They cannot detect a model removal or
  a first-party response-schema change by themselves.

## Performance assessment for version 1.1.0

| Area | Rating | Assessment |
|---|---:|---|
| Scheduled pipeline | **Improved, fair** | Normal steps are separate queue requests. Topic and body can still make two serial 120-second calls. |
| Stall recovery | **Functionally good, performance weak** | Recovery is bounded and paged, but the maximum scan has about 2,000 queue queries. |
| Budget check | **Good normal path** | The named lock encloses a small check-and-reserve section. The 10-second wait is acceptable for a rare collision. |
| Run payload | **Fair** | The full article JSON is read and rewritten as one long-text payload. The size is bounded indirectly by provider and validator limits. |
| Run-log and spend queries | **Fair** | Pagination and date indexes exist. The sweep needs an index and query-plan review for `(status, started_at, id)`. |
| Media handling | **Good limits, incomplete errors** | Byte, pixel, MIME, and SSRF controls are good. Metadata and thumbnail results need checks. |
| Retention | **Good** | Run pruning and UI pagination bound long-term storage and display work. |

## Test assessment and required additions

The passing suite gives good confidence in normal single-worker behavior. It
does not prove the highest-risk properties of this release.

Add these tests in priority order:

1. Fail each usage update after a successful provider response. Assert that the
   run stops and records the charge.
2. Execute topic and body through the queue and assert one provider call per
   dispatched action.
3. Start two workers for the same run and assert one step claim, one provider
   call, one image, one finalization, and one notification.
4. Fail cost settlement and every terminal-status update independently.
5. Change default models and force-review state between queue actions.
6. Fail attachment metadata and thumbnail assignment in all image modes.
7. Dispatch the actual bundled Action Scheduler action from scheduling through
   completion. Test activation, deactivation, and recurring sweep actions.
8. Measure sweep query count at 100 and 2,000 candidate rows.
9. Add MariaDB CI and a built-archive activation smoke test.
10. Add an explicit release check for current first-party provider model IDs and
    response fixtures. Do not use production keys in ordinary unit tests.

## Required remediation order

1. Fix usage-write and terminal-write error handling.
2. Make each queued action perform no more than one provider call, or correct
   the objective and documentation.
3. Snapshot resolved global configuration and preserve review safety for open
   runs.
4. Verify image metadata and featured-image assignment.
5. Add an atomic per-run step claim and unique scheduling.
6. Correct the Google suggestion after a current live capability check.
7. Replace the stall-sweeper N+1 query pattern.
8. Make the monthly warning claim atomic.
9. Correct the README and changelog contradictions.
10. Add the missing concurrent, failure-injection, and real-queue tests.

## Fresh review conclusion

AutoScribe 1.1.0 is closer to the project brief and is materially safer than
1.0.1. The pipeline is now resumable, the main budget race is fixed, and the
test suite is strong on the routine path. These are substantial improvements.

The release is not ready for unattended automatic publication. A database
failure can remove paid usage from the cap. The new queue split still permits
two provider calls in one action. Global model changes can occur after the
budget check. Image and terminal-state failures can be reported as success. The
queue also lacks an atomic run claim for duplicate delivery.

After CR-01 through CR-06 are fixed and tested with real concurrency and
failure injection, the project should be reviewed again for production use.

## Follow-up review — version 1.0.1

**Follow-up date:** 18 August 2026

**Reviewed revision:** `6f844ce` on `main`, tag `v1.0.1`

**Documents reviewed:** `CODEX-REVIEW-RESPONSE.md`, `CHANGELOG.md`, `README.md`,
`INSTRUCTIONS.md`, `DECISIONS.md`, and the 1.0.1 code and tests

**Current result:** **Conditional fail. Version 1.0.1 is improved, but it is not
production-ready.**

**Current quality score:** **6.5/10**

### Follow-up conclusion

The 1.0.1 work fixes many real defects. It fixes the Google structured-output
field, accumulated token accounting, visible Preview output, review and failure
mail, grounding capability checks, weak-salt checks for new key writes, several
write-error paths, and CI action pinning. The full test suite passes.

The response and changelog overstate the result. Four release blockers remain:

1. The budget reservation is still not concurrency-safe. The stated one-run
   overshoot bound is false.
2. Draft adoption can overwrite an old or human-edited draft on any later run,
   not only on the retry that created it.
3. `gemini-3.7-flash` remains the first Google default, but it is not in the
   current first-party Google model documents reviewed for this follow-up.
4. The full generation pipeline still runs in one long queue request.

There is no evidence of a critical unauthenticated RCE, SQL injection, stored
XSS, or CSRF defect in the changed code. The highest security issue remains the
budget-control failure. The main content-integrity risk remains prompt injection
when grounding and automatic publication are both enabled.

### Follow-up verification

| Check | Result | Evidence |
|---|---:|---|
| Git revision | Pass | `main`, `origin/main`, and annotated tag `v1.0.1` resolve to `6f844ce`. |
| Composer manifest | Pass | `composer validate --no-check-publish` reports a valid manifest. |
| Dependency advisories | Pass | `composer audit --locked` reports no known advisory. |
| WordPress coding standards | Pass | PHPCS checks 98 files with no error. |
| PHPUnit | Pass | 200 tests and 741 assertions pass on PHP 8.1 with WordPress and MySQL 8. |
| Release archive | Pass | `build/autoscribe-1.0.1.zip` passes `unzip -t`, contains runtime autoload files, and excludes the review files, tests, project docs, and root Composer files. |
| Live provider calls | Not run | No funded provider key was supplied. No project or prompt data was sent to a provider. |
| Google documentation | Partial fail | The corrected `response_format` shape matches current Google documentation. The suggested `gemini-3.7-flash` identifier was not found. See FR-03. |
| Action Scheduler dispatch | Not covered | Tests call the queue handler directly. They do not dispatch the bundled queue. |
| Concurrent reservation | Not covered | No multi-process test exists. The current algorithm fails by inspection. See FR-01. |

The GitHub release is published as a normal release. It is not marked as a
pre-release ([AutoScribe 1.0.1 release](https://github.com/johnjanney/autoscribe/releases/tag/v1.0.1)).

### Corrections to the audit response

The response correctly identifies one error in the original audit, but two of
its own rebuttals are not supported.

1. **Original Google test statement — correction accepted.** The original audit
   said that the old Google test asserted the obsolete structured-output shape.
   That was wrong. The test did not assert those fields. The defect survived
   because the contract was not tested. The new test now asserts the correct
   top-level `response_format` ([GoogleTest](tests/Providers/GoogleTest.php#L99)).
2. **`gemini-3.7-flash` rebuttal — not verified.** The response says that Google
   documents this model as generally available, but it gives no URL. **Not found
   in documents.** Google's current latest-model page identifies
   `gemini-3.6-flash` and `gemini-3.5-flash-lite` as the current GA Flash models,
   and its current Interactions migration examples use `gemini-3.6-flash`
   ([Google latest models](https://ai.google.dev/gemini-api/docs/latest-model),
   [Google Interactions migration](https://ai.google.dev/gemini-api/docs/migrate-to-interactions)).
   No live-key model-list request was run, so this conclusion is limited to
   current first-party documents.
3. **Similarity-threshold rebuttal — rejected.** The response says that the brief
   requires only a filterable threshold. The brief says that similarity must
   exceed 82 percent and then says to make “the 82 percent threshold” filterable
   ([project brief](docs/PROJECT-BRIEF.md#L441)). The code default is 78
   ([Topic_Deduplicator](src/Content/Topic_Deduplicator.php#L38)). A reasoned and
   filterable change is still a deviation. `DECISIONS.md` also continues to call
   it 82 percent ([DECISIONS](DECISIONS.md#L228)).
4. **Release-candidate status — contradicted by release state.** The response and
   README call 1.0.1 a release candidate. The repository has a stable-form
   `v1.0.1` tag, the changelog has a dated release section, and GitHub marks the
   release as neither draft nor pre-release. This is a normal published release,
   not a release candidate.

### Follow-up status of the original findings

| Finding | Follow-up status | Assessment |
|---|---|---|
| AS-01 Google contract | **Partial** | The structured-output fields are fixed and tested. The first suggested model is still unsupported by the first-party documents reviewed. |
| AS-02 single-action pipeline | **Open** | It remains a deliberate deviation and a release blocker. Documentation does not satisfy the requirement or remove the timeout risk. |
| AS-03 cost and cap | **Partial; high risk remains** | Token accumulation and several settlement defects are fixed. Concurrency, image estimation, update-failure handling, and some grounded-call accounting remain wrong. |
| AS-04 retries and duplicate drafts | **Partial; regression added** | More errors are permanent and attempt numbers are stored. Draft adoption can overwrite unrelated prior work. Step resume is absent. |
| AS-05 defaults and connection tests | **Partial** | The paths are reachable, but one slug-keyed default cannot represent separate text and image defaults for OpenAI or Google. The image connection path is not reachable for those slugs. |
| AS-06 Preview | **Mostly fixed** | Output is visible and sanitized. Preview remains synchronous and reserves image cost even though it creates no image. |
| AS-07 notifications | **Fixed in code, untested** | Draft and final-failure mail paths exist. Their return values and complete delivery paths have no automated coverage. |
| AS-08 grounding capability | **Fixed** | The client, save, and run-time paths now enforce the provider capability. |
| AS-09 release and requirements drift | **Open** | The documents are more honest, but they still contradict the brief and the published release state. |
| SEC-01 budget cap | **Open, High** | The new confirmation algorithm does not close the race. |
| SEC-02 prompt injection | **Partial** | Local titles are fenced in the proposal call. Generated proposal and repair data are still reintroduced as instructions. Grounded data remains outside plugin control. |
| SEC-03 response limits | **Partial** | JSON and inline image responses are bounded. URL image downloads are bounded only after the full file is downloaded and read into memory. |
| SEC-04 weak key derivation | **Partial** | New weak-salt writes are refused. Existing 1.0.0 ciphertext made under weak salts remains readable and stored. |
| SEC-05 mutable CI actions | **Fixed** | Both actions are pinned to existing full commit SHAs. |

## Follow-up findings in criticality order

### FR-01 — High — The budget cap still has a concurrency bypass and can omit image cost

**Category:** Security business logic, financial control, correctness

**Verified facts**

- `Step_Budget_Check` still performs a read, an independent reservation update,
  and a second read ([Step_Budget_Check](src/Pipeline/Step_Budget_Check.php#L59)).
- `confirm_reservation()` counts rows whose ID is less than or equal to the
  current run ID ([Budget_Guard](src/Cost/Budget_Guard.php#L192)). Run IDs are
  assigned when the run row is inserted, not when the reservation is written.
- The reservation update and all other `Run` updates ignore `$wpdb->update()`
  failure ([Run](src/Pipeline/Run.php#L595), [Run](src/Pipeline/Run.php#L742)).
- The estimate resolves model settings without passing provider suggestions
  ([Budget_Guard](src/Cost/Budget_Guard.php#L305)). Generation does pass those
  suggestions ([Step_Generate_Image](src/Pipeline/Step_Generate_Image.php#L81)).
- If the image model and site default are blank, `cost_cents()` prices the image
  under the text model ([Pricing_Table](src/Cost/Pricing_Table.php#L180)). The
  seeded Claude text models have a zero image rate. A prompt with an explicit or
  site-default Claude text model and a blank OpenAI image model/default can
  therefore reserve no image cost.
- No test calls `confirm_reservation()`. No test covers an image provider with a
  blank prompt model and blank site default.

**Demonstrable race**

Create run A with the lower ID and run B with the higher ID. Let both initial
checks pass. Pause A. Let B write its reservation and confirm before A writes.
B sees only its own reservation and passes. Then let A write and confirm. A's
`id <= A` query excludes B, so A also sees only its own reservation and passes.
The same reverse-ID order can let more than two workers pass. The overshoot is
therefore not bounded to one run, contrary to the README
([README](README.md#L209)) and the changelog claim
([CHANGELOG](CHANGELOG.md#L117)).

**Impact**

Unattended work can exceed both the per-prompt and global caps. A failed
reservation update can also let a run proceed with no visible reservation. An
image can be generated without its estimated cost appearing in the preflight
amount.

**Required correction**

- Use a transaction and row lock, or use one atomic conditional update against
  a monthly ledger row.
- Make reservation writes return an error and stop before the first provider
  call if the write fails.
- Resolve the actual image adapter suggestion when the prompt and site defaults
  are blank.
- Add a multi-process concurrency test and an image-estimate regression test.
- Remove the one-run overshoot claim until the bound is proved.

### FR-02 — High — Draft adoption can overwrite old or human-edited content

**Category:** Data integrity, retry correctness, regression

**Verified facts**

- Every generation run calls `adoptable_draft()`. The call is not limited to
  attempts greater than one ([Generator](src/Pipeline/Generator.php#L173)).
- The query selects the newest failed run for the prompt that has a post ID. It
  has no retry-series, time, immediately-previous-run, or attempt constraint
  ([Run](src/Pipeline/Run.php#L386)).
- The safety check requires only that the post is still a draft and that its run
  metadata is non-empty. It does not verify that the metadata matches the failed
  row. It does not detect a human edit.
- `Step_Assemble_Post` updates the adopted draft with newly generated title,
  body, excerpt, SEO data, and taxonomy ([Step_Assemble_Post](src/Pipeline/Step_Assemble_Post.php#L140)).
- No test covers `adoptable_draft()` or an image failure followed by a retry.

**Impact**

After retries are exhausted, the next normal scheduled occurrence can overwrite
the old failed draft with a different article. A reviewer can edit a failed
draft and leave it in draft status; a later run can overwrite those edits. Two
overlapping runs can also adopt the same draft.

**Required correction**

- Adopt only on `attempt > 1` and only from the immediately preceding attempt in
  the same retry series.
- Store a retry-series ID or previous-run ID.
- Record a content hash and modification timestamp. Refuse adoption after a
  human edit.
- Add integration tests for exhausted retries, the next scheduled occurrence,
  human edits, and overlapping runs.

### FR-03 — High — The Google structured-output fix is valid, but the default model remains unverified

**Category:** Provider compatibility, release blocker

The new top-level `response_format` object matches Google's current Interactions
contract ([Google migration guide](https://ai.google.dev/gemini-api/docs/interactions-breaking-changes-may-2026)).
That part of AS-01 is fixed.

The first suggestion remains `gemini-3.7-flash`
([Google adapter](src/Providers/Text/Google.php#L82)). **Not found in documents.**
Current first-party Google material identifies `gemini-3.6-flash` as the GA
Flash model and uses it in current REST examples
([Google latest models](https://ai.google.dev/gemini-api/docs/latest-model)). A
blank prompt and blank site default select the first suggestion, so the
unverified identifier is an operational default, not an optional example.

Replace the first suggestion with a documented current model, or prove the ID
with a first-party model page and a live model-list smoke test. Do not describe
the model as GA without a source.

### FR-04 — Medium — Text and image defaults collide for OpenAI and Google

**Category:** Functional correctness, admin design

**Verified facts**

- Settings store defaults by provider slug only
  ([Settings](src/Admin/Settings.php#L117)).
- OpenAI and Google use the same slug for their text and image adapters.
- The settings provider map writes image adapters after text adapters, so image
  labels replace text labels for those slugs
  ([Settings_Page](src/Admin/Settings_Page.php#L503)).
- Text and image generation both read the same default through `Model_Resolver`.
- The connection test selects the text adapter first. The image adapter is never
  tested for a slug that also has a text adapter
  ([Actions](src/Admin/Actions.php#L358)).

**Impact**

One OpenAI default cannot be both a text model such as a GPT model and an image
model such as `gpt-image-2`. Setting either value can make the other capability
fail when its prompt field is blank. A row labelled as an image provider can run
the text connection test.

**Required correction**

Store separate text and image defaults, such as `text:openai` and
`image:openai`. Render separate controls and test the selected capability. Add
OpenAI and Google tests that leave both prompt model fields blank.

### FR-05 — High — The single-action pipeline remains a release blocker

The response confirms AS-02 and deliberately does not fix it
([audit response](CODEX-REVIEW-RESPONSE.md#L110)). This is transparent, but it
does not change the result. The brief requires separate queue steps
([project brief](docs/PROJECT-BRIEF.md#L303)). One request can still contain
several provider calls with 120-second timeouts, post writes, image download,
and image processing. There is no saved step state. A retry repeats paid work.

This remains a high performance and reliability risk on shared hosting. Treat
it as planned architecture work, but do not call the plugin brief-complete or
production-ready before it is done.

### FR-06 — Medium — Retry classification is still open by default

The class comment says that only transient transport failures are retried
([Retry_Policy](src/Pipeline/Retry_Policy.php#L14)). The implementation does the
opposite: it retries every error code that is not in a permanent denylist
([Retry_Policy](src/Pipeline/Retry_Policy.php#L97)). New permanent errors are
retryable until a developer remembers to add them. Current examples include
invalid or oversized image results and several local write failures.

Use a retry allowlist for known transient codes, such as transport failure,
rate limit, and provider unavailability. Treat unknown codes as permanent. Add
a test that proves an unknown code is not retried.

### FR-07 — Medium — Prompt-injection mitigation is useful but incomplete

The new labelled JSON block is a useful control for locally supplied titles
([Step_Propose_Topic](src/Pipeline/Step_Propose_Topic.php#L230)). It does not make
SEC-02 fixed.

- A compromised proposal can put instruction-like text in its `title`. The body
  step inserts that title into plain instruction text
  ([Step_Generate_Body](src/Pipeline/Step_Generate_Body.php#L227)).
- A failed model response is inserted into the repair prompt as plain text
  ([Step_Generate_Body](src/Pipeline/Step_Generate_Body.php#L240)).
- The plugin cannot inspect or delimit server-side search results before the
  provider model reads them. The updated instructions now state this correctly
  ([INSTRUCTIONS](INSTRUCTIONS.md#L211)).

The likely impact remains unwanted titles, claims, or links in automatically
published content. It is not direct server code execution. Put proposal and
repair data in labelled structured blocks, add system-level untrusted-data
rules to both calls, and keep review mode on for grounded content.

### FR-08 — Low — The URL image byte limit is applied after the unbounded download

`download_url()` writes the complete response to a temporary file. The plugin
then reads the complete file into memory and only after that checks
`MAX_IMAGE_BYTES` ([Image_Sideloader](src/Media/Image_Sideloader.php#L174)). The
20 MB check therefore protects the uploads directory and later image processing,
but it does not bound temporary disk use, download bandwidth, or the memory used
by `get_contents()`. WordPress documents `download_url()` as a streamed download
with a timeout, but it has no caller-supplied maximum byte argument
([WordPress `download_url()`](https://developer.wordpress.org/reference/functions/download_url/)).

Use a safe streamed request with `limit_response_size`, or reject by content
length and stop the stream at the limit. Keep the existing file-type and pixel
checks.

### FR-09 — Low — Existing weak-salt key records are not remediated on upgrade

`Key_Store::set()` now refuses new storage when the salts are unusable. That is
good. `source()` and `get()` do not make the same check
([Key_Store](src/Security/Key_Store.php#L94)). A key stored by 1.0.0 under missing
or placeholder salts remains in the database and remains decryptable under the
predictable key after upgrade.

Refuse reads while salts are unusable. Mark existing records as unsafe and ask
the administrator to replace them after valid salts are installed. Do not claim
the old exposure is fixed until the upgrade path handles existing ciphertext.

### FR-10 — Low — “Queue last processed” reports the scheduled time

`Scheduler::last_processed()` orders completed actions by `date` and returns
`get_schedule()->get_date()` ([Scheduler](src/Scheduling/Scheduler.php#L198)). In
the bundled Action Scheduler, `date` orders by `scheduled_date_gmt`, and
`get_date()` returns the scheduled date
([Action Scheduler DB store](vendor/woocommerce/action-scheduler/classes/data-stores/ActionScheduler_DBStore.php#L589),
[schedule object](vendor/woocommerce/action-scheduler/classes/abstracts/ActionScheduler_Abstract_Schedule.php#L61)).
It does not return completion time.

A job due one week ago but processed now will be shown as last processed one week
ago. Query by the last-attempt or modified time, or read the completion log.

### FR-11 — Medium — Changelog and release-status claims remain inaccurate

The 1.0.1 changelog says the concurrency issue is fixed, response and image size
limits are fixed, and the release documentation is corrected
([CHANGELOG](CHANGELOG.md#L90)). FR-01 and FR-08 show that those statements are
too broad.

Other verified drift remains:

- README says only one brief requirement is knowingly unmet, but the same section
  lists the required screenshot as missing ([README](README.md#L192)).
- The live next-run readout, streamed Run now result, plain-clone installation,
  and default 82-percent threshold remain deviations
  ([project brief](docs/PROJECT-BRIEF.md#L560),
  [project brief](docs/PROJECT-BRIEF.md#L656)).
- The response calls 1.0.1 a release candidate, but the public GitHub release is
  not marked as a pre-release.

Add a correction notice to the published 1.0.1 entry. Put the remaining fixes
under `Unreleased`, and publish a corrected patch only after the release blockers
and regression tests are complete. If a build is a release candidate, use an
explicit pre-release version and mark the GitHub release as a pre-release.

### Follow-up security order

1. **High:** FR-01, budget-cap bypass and omitted reservations or image cost.
2. **Medium:** FR-07, residual prompt-injection and automatic-publication risk.
3. **Low:** FR-08, unbounded URL-image download before the size check.
4. **Low:** FR-09, legacy weak-salt ciphertext remains readable.

FR-02 is also a high data-integrity defect. It is not an unauthenticated attack
path, but it can destroy reviewer work and prior draft content.

### Follow-up remediation order

1. Replace the reservation algorithm with an atomic ledger operation and fix
   image-model estimation.
2. Remove broad draft adoption until retry-series and human-edit guards exist.
3. Replace or prove the Google default model ID.
4. Split text and image defaults and their connection tests.
5. Split the pipeline into persistent queue steps.
6. Change retry classification to a transient allowlist.
7. Complete the URL-download cap and the weak-key upgrade path.
8. Correct the queue-health time, threshold documentation, changelog, README,
   and GitHub release status.
9. Add tests for every item above, then publish a new release.

## Executive assessment

### Direct answers

1. **Does the plugin achieve the project brief?**

   **Partly.** The project has most of the planned classes, screens, provider adapters, scheduling rules, sanitization controls, and tests. It can run the main generation path with mocked provider responses. It does not complete all required behavior. One current Google API contract is wrong. The long pipeline is not split into separate queue actions. Some required controls have no working UI path. Some documented features do not exist.

2. **How well does it achieve the objectives?**

   **Overall quality: 5.5/10.** The internal code style is good. The design is easy to read. The automated suite is strong for isolated classes. However, the most important production boundaries have weak or no tests. These boundaries are the real provider APIs, the Action Scheduler dispatch path, settings submission, queue concurrency, and the complete admin workflow. The release has good engineering form but incomplete production behavior.

3. **Is there drift?**

   **Yes. Significant drift exists.** Some drift is recorded in `DECISIONS.md`, but the release documents still state that all brief requirements are complete. The largest drift is the single-action pipeline. Other drift includes the absent Preview display, absent provider connection controls, inactive default-model settings, absent draft email, non-live next-run display, incomplete health data, and invalid grounding capability handling.

4. **Are there security vulnerabilities?**

   **Yes.** No critical unauthenticated remote-code-execution, SQL injection, stored XSS, or CSRF issue was found in the reviewed code. The main security risks are business-logic and content-integrity risks:

   - The budget cap is not a dependable cap under concurrency and it omits paid calls from accounting.
   - Grounded web content and existing post titles can influence model instructions. The documented prompt-injection control is not implemented.
   - Provider response and image sizes have no plugin limit. A bad upstream response can exhaust memory, disk, or image-processing resources.
   - Stored-key encryption uses a predictable fallback key if both required WordPress secrets are absent.

### Release recommendation

Do not release 1.0.0 as “brief complete.” Fix findings AS-01 through AS-06 first. Add contract tests from current provider fixtures. Add an Action Scheduler integration test that dispatches real queued actions. Change the README and changelog until those fixes are complete.

## Scope and method

The audit included these items:

- The project brief, instructions, README, changelog, decisions log, contribution guide, Composer files, CI workflow, build script, and uninstall path.
- All PHP files in `src/`, the plugin bootstrap, and the test suite.
- Authorization, nonce, output escaping, SQL construction, remote requests, model-output handling, key storage, file download, post creation, retries, cost accounting, and queue behavior.
- Current first-party API documentation for Anthropic, OpenAI, Google, DeepSeek, and WordPress.
- Local automated checks.

This audit did not use live provider keys. A live generation call would spend money and send project data to an external service. Provider conclusions use current first-party contracts and local request-shape tests.

## Verification results

| Check | Result | Evidence |
|---|---:|---|
| Git worktree before this report | Clean | `git status --short` returned no entries. |
| Composer manifest | Pass | `composer validate --no-check-publish` reported a valid manifest. |
| Dependency advisories | Pass | `composer audit --locked` reported no known advisory for the locked set. |
| WordPress coding standards | Pass | PHPCS checked 95 files with no error. |
| PHPUnit | Pass | 185 tests and 692 assertions passed in the WordPress test container. |
| Provider live calls | Not run | No authorization to spend provider credits or send prompts. |
| Action Scheduler end-to-end dispatch | Not covered | The repository also states that no test drives Action Scheduler itself ([README](README.md#L202)). |
| Settings save and connection UI | Not covered | The repository records this test gap ([README](README.md#L198)). The connection control is also absent from the rendered page. |

The passing suite is useful. It is not proof of external compatibility. For example, the original Google test did not assert the structured-output fields, so the contract defect was outside its coverage. The 1.0.1 follow-up corrects the original audit's inaccurate statement that the test asserted the obsolete shape.

## Objective coverage matrix

| Brief objective | Assessment | Evidence and gap |
|---|---|---|
| Separate text and image providers | **Implemented** | Separate interfaces and registries exist in `src/Providers/`. Prompt fields keep separate provider and model values. |
| Editable model IDs | **Partly implemented** | Prompt model fields are editable. Site default model values are saved and rendered, but generation does not read them. See AS-05. |
| REST provider adapters | **Partly implemented** | Anthropic, OpenAI, Google, and DeepSeek text adapters exist. OpenAI and Google image adapters exist. The current Google structured-output request is wrong. See AS-01. |
| Action Scheduler instead of WP-Cron | **Partly implemented** | Action Scheduler is bundled and single actions are scheduled. The full run still executes in one callback. See AS-02. |
| Six schedule types with DST and month handling | **Implemented with good tests** | [`Next_Run_Calculator`](src/Scheduling/Next_Run_Calculator.php#L98) handles all six types. Unit and integration tests cover DST, month boundaries, leap dates, and ordinal dates. |
| Split, idempotent pipeline steps | **Not implemented** | The decisions log admits that one action runs the full pipeline ([DECISIONS](DECISIONS.md#L149)). Retry idempotency is also incomplete. See AS-02 and AS-04. |
| Structured output and one repair | **Partly implemented** | The validator and repair call exist. Queue retry can repeat the full two-call repair sequence. Google strict output uses the wrong contract. |
| Mandatory content sanitization | **Implemented well** | [`Content_Sanitizer`](src/Security/Content_Sanitizer.php#L68) removes executable blocks and dangerous attributes, then applies `wp_kses_post()` and a narrow allowlist. Tests cover common XSS inputs. |
| Featured image modes | **Mostly implemented** | Required, fallback, optional, and none paths exist. Retry after an image failure can create duplicate drafts. Image downloads have no size cap. |
| Web search grounding | **Partly implemented** | Provider tools and source extraction exist. DeepSeek can still save an invalid grounding configuration. The documented injection protection does not exist. |
| Duplicate-topic avoidance | **Mostly implemented** | Recent topics, exact keys, similarity, title lookup, and one re-ask exist. A duplicate result can trigger full queued retries and extra paid calls. |
| SEO and taxonomy | **Implemented** | Runtime adapters exist for Yoast, Rank Math, and SEOPress. AI tag creation is limited to three new terms. |
| Cost caps and spend reporting | **Not dependable** | The main classes and UI exist. Accounting and concurrency defects can undercount or exceed the cap. See AS-03. |
| API key controls | **Mostly implemented** | Constants take priority. Database values use libsodium. Values are not rendered back. See SEC-04 for the missing-secret case. |
| Admin interface | **Partly implemented** | Main pages and prompt tabs exist. Preview output, Test connection controls, live next-run data, and full health information are missing or inactive. |
| Human review | **Partly implemented** | Per-prompt draft mode and the global override work. The required email for each review draft does not exist. Failure notifications do not exist. |
| Release quality | **Partly implemented** | CI, PHPCS, PHPUnit, POT, build script, and service disclosure exist. The README has no required screenshot. Release claims exceed actual coverage. |

## Prioritized findings

### AS-01 — High — Google text generation does not match the current Interactions API contract

**Category:** Functional correctness, provider drift, release blocker

**Verified facts**

- The adapter posts to `https://generativelanguage.googleapis.com/v1beta/interactions` ([Google.php](src/Providers/Text/Google.php#L34)).
- The adapter declares strict JSON support ([Google.php](src/Providers/Text/Google.php#L104)).
- For strict JSON, it sends `response_mime_type` and `response_schema` inside `generation_config` ([Google.php](src/Providers/Text/Google.php#L158)).
- Google moved Interactions API output controls to top-level `response_format`. Google states that `response_mime_type` was removed from this API contract. See the [Google migration guide](https://ai.google.dev/gemini-api/docs/migrate-to-interactions) and [current structured-output guide](https://ai.google.dev/gemini-api/docs/structured-output).
- Topic generation requests a schema whenever a provider reports strict JSON support ([Step_Propose_Topic](src/Pipeline/Step_Propose_Topic.php#L107)). Therefore every normal Google topic call uses the wrong fields.
- The first suggested Google text model is `gemini-3.7-flash` ([Google.php](src/Providers/Text/Google.php#L82)). **Not found in documents.** The current Google Interactions reference lists `gemini-3.6-flash`, but not `gemini-3.7-flash` ([Google Interactions API](https://ai.google.dev/api/interactions-api)).

**Inference**

The Google API will probably reject the strict-output request with HTTP 400. If it ignores the removed fields, the plugin loses provider-enforced schema output. A blank Google prompt model also falls back to a model ID that is not in the current first-party model list.

**Impact**

Google text generation is not dependable. This breaks one of the four named text providers and the Claude-plus-Google design objective when Google supplies text.

**Required correction**

- Send top-level `response_format` with `type`, `mime_type`, and `schema`.
- Replace or remove `gemini-3.7-flash` until it appears in the official model endpoint.
- Add recorded contract fixtures from the current API. Do not only assert a request shape written by the same project.
- Add an optional, explicit live smoke test that a maintainer can run with a test key.

### AS-02 — High — The full 30–120 second pipeline runs in one queue callback

**Category:** Architecture drift, performance, reliability

**Verified facts**

- The brief requires separate Action Scheduler steps so each request is short ([project brief](docs/PROJECT-BRIEF.md#L303)).
- The queue callback calls `Generator::run()` once ([Queued_Run_Handler](src/Pipeline/Queued_Run_Handler.php#L87)).
- `Generator::run()` performs budget, topic, body, post insert, image generation, image download, metadata generation, publication, cost settlement, and email checks in the same PHP request ([Generator](src/Pipeline/Generator.php#L115)).
- Provider generation calls permit 120 seconds each ([Http](src/Providers/Http.php#L34)). A run can make two proposal calls, two body calls, and one image call.
- The decisions log admits this drift and the timeout risk ([DECISIONS](DECISIONS.md#L149)).

**Impact**

Shared hosts can terminate the request before completion. A terminated request can leave a running row, a reserved budget, a draft post, or an unattached image. The queue cannot resume from a saved step because the payload does not hold pipeline state.

**Required correction**

- Implement one queue hook per step.
- Store step input and output in `runs.payload`.
- Load the run row by `run_id` in each callback.
- Make post and attachment creation idempotent by persistent identifiers, not only by one in-memory object.
- Add a test that dispatches the Action Scheduler queue and verifies the complete state sequence.

### AS-03 — High — Cost accounting and cap enforcement are not dependable

**Category:** Security business logic, financial control, correctness

**Verified facts**

1. `record_text_usage()` replaces token totals instead of adding to them ([Run](src/Pipeline/Run.php#L205)). The topic call records usage first ([Step_Propose_Topic](src/Pipeline/Step_Propose_Topic.php#L125)). The body call then replaces it ([Step_Generate_Body](src/Pipeline/Step_Generate_Body.php#L123)). The final cost omits topic-call tokens.
2. A duplicate result sets cost to zero ([Run](src/Pipeline/Run.php#L554)), although one or two paid topic calls already occurred ([Step_Propose_Topic](src/Pipeline/Step_Propose_Topic.php#L110)).
3. The estimate uses one output allowance even though the pipeline has a topic call and a body call ([Budget_Guard](src/Cost/Budget_Guard.php#L226)). The code comment says both calls are included, but the calculation does not add the 512-topic allowance.
4. Cap check and reservation are separate database operations ([Step_Budget_Check](src/Pipeline/Step_Budget_Check.php#L59)). Two workers can both read the same total before either worker writes its reservation. There is no transaction, lock, or atomic conditional update.
5. Failed runs keep the estimated reservation because `fail()` does not settle known usage or release unused cost ([Run](src/Pipeline/Run.php#L618)).

**Inference**

The plugin can report less spend than the provider bills. Concurrent workers can exceed a configured cap. Dead or failed runs can also overstate spend and block later work. The error direction is not consistently conservative.

**Impact**

The monthly cap is a warning-grade estimate, not an enforceable cap. The current UI and documentation describe a stronger control than the code provides.

**Required correction**

- Accumulate usage for every provider call.
- Record cost for duplicate and failed paths when usage exists.
- Include proposal, repair, grounding, and image calls in the preflight estimate.
- Use an atomic reservation. A dedicated monthly ledger row with a transaction or an atomic conditional update is preferable.
- State a documented overshoot bound. No client-side cap can replace provider-side billing limits.
- Add concurrent-worker tests.

### AS-04 — High — Retry behavior can create duplicate drafts and repeat non-retryable paid work

**Category:** Correctness, cost, data integrity

**Verified facts**

- Every retry opens a new run row. The decisions log records this choice ([DECISIONS](DECISIONS.md#L201)).
- Post assembly happens before image generation ([Generator](src/Pipeline/Generator.php#L165)).
- An image failure occurs after the draft exists ([Generator](src/Pipeline/Generator.php#L175)).
- A new run has no link to the draft made by the prior run. `Run::start()` always creates a new row, and `post_id` exists only on the new in-memory instance ([Run](src/Pipeline/Run.php#L125)).
- The retry policy retries every code not in a short permanent list ([Retry_Policy](src/Pipeline/Retry_Policy.php#L50)). It does not classify `autoscribe_duplicate_topic`, `autoscribe_budget_exceeded`, or validation errors as permanent.
- Each run row always starts with `attempt = 1` ([Run](src/Pipeline/Run.php#L128)). The displayed attempt column therefore does not show the real retry number.

**Inference**

A transient required-image failure can produce one draft per retry. A duplicate topic can run the paid proposal sequence again. A body that fails its one repair can receive two more full queue retries, which defeats the stated one-repair limit.

**Impact**

The plugin can create duplicate content, spend more than planned, and show incorrect attempt data.

**Required correction**

- Retry the same `run_id` and resume the failed step.
- Load persistent `post_id` and attachment state before any write.
- Make budget skips, duplicate skips, and exhausted validation repair non-retryable.
- Store the actual attempt on the run row and show it in the UI.

### AS-05 — Medium — Default models and provider connection tests are inactive admin features

**Category:** Functional drift, misleading UI and documentation

**Verified facts**

- The settings page saves default models ([Settings_Page](src/Admin/Settings_Page.php#L132)).
- `Settings::default_model()` is used only to render the setting. Generation does not call it. A blank prompt model uses the first hard-coded provider suggestion ([Step_Propose_Topic](src/Pipeline/Step_Propose_Topic.php#L92), [Step_Generate_Image](src/Pipeline/Step_Generate_Image.php#L80)).
- `Actions::test_connection()` exists ([Actions](src/Admin/Actions.php#L280)), but no admin hook, button, or form calls it. The settings page renders only key and default-model inputs ([Settings_Page](src/Admin/Settings_Page.php#L218)).
- The README says the Test connection control exists but lacks tests ([README](README.md#L198)). The control does not exist.
- The README says model endpoints are checked before paid generation calls ([README](README.md#L98)). Generation never calls `test_connection()`.

**Impact**

An administrator can save a default that has no effect. The administrator cannot test a provider from the UI. Documentation can cause false confidence.

**Required correction**

- Resolve models in this order: prompt value, site default, explicit provider fallback.
- Add a nonce-protected, capability-checked connection action per provider.
- Report the checked endpoint and model without exposing the key.
- Correct the README if generation does not perform preflight model checks.

### AS-06 — Medium — Preview is generated and charged, but never displayed

**Category:** Functional drift, performance

**Verified facts**

- Preview runs the topic and body calls synchronously in the admin request ([Actions](src/Admin/Actions.php#L170)).
- It stores title, excerpt, and raw model HTML in a user transient ([Actions](src/Admin/Actions.php#L178)).
- No code reads `PREVIEW_TRANSIENT`. A repository search finds only the definition and write.
- The redirect message says that the preview is shown ([Actions](src/Admin/Actions.php#L188)).

**Impact**

The user pays for output that the UI does not show. The admin request can also exceed normal web request timeouts.

**Required correction**

- Queue preview work or provide a controlled progress request.
- Render the result after applying the same sanitizer used for posts.
- Delete the transient after display.
- Add an end-to-end admin test that verifies visible preview content and no post creation.

### AS-07 — Medium — Human-review notifications are incomplete

**Category:** Safety objective, operational quality

**Verified facts**

- The brief requires an email for a generated review draft and a notification after final failure ([project brief](docs/PROJECT-BRIEF.md#L587)).
- The only `wp_mail()` call is the 80-percent budget warning ([Generator](src/Pipeline/Generator.php#L225)).
- Required-image failures keep the draft, but no email tells the user that the run failed.
- The pending-draft admin notice examines at most 100 recent drafts and only `post` and `page` ([Menu](src/Admin/Menu.php#L171)). Those are the only supported target types, but counts above 100 are understated.

**Impact**

Drafts and failed runs can remain unnoticed. This weakens the safety workflow that is meant to prevent unreviewed publication.

**Required correction**

- Send the specified review email with title, excerpt, and edit link.
- Send one final-failure email after retry exhaustion.
- Do not send one email for each intermediate retry.
- Count all pending generated drafts or label the count as capped.

### AS-08 — Medium — Capability-dependent grounding controls are not enforced

**Category:** Functional drift, configuration correctness

**Verified facts**

- The brief requires the UI to disable grounding for a provider that does not support it and to prevent invalid saves ([project brief](docs/PROJECT-BRIEF.md#L404)).
- `grounding_enabled` is a normal checkbox with no provider condition ([Prompt_Fields](src/Prompts/Prompt_Fields.php#L131)).
- The admin JavaScript only changes visible tabs ([Assets](src/Admin/Assets.php#L109)).
- The body step silently disables grounding if the provider reports no support ([Step_Generate_Body](src/Pipeline/Step_Generate_Body.php#L100)).
- The instructions say that the DeepSeek control explains the limitation ([INSTRUCTIONS](INSTRUCTIONS.md#L217)). It does not.

**Impact**

A saved prompt can state that grounding is on while DeepSeek does not use grounding. Source-list and cost expectations then become wrong.

**Required correction**

- Add server-side validation on save.
- Add accessible client-side disablement and a clear reason.
- Revalidate at run time and fail configuration errors instead of silently changing requested behavior.

### AS-09 — Medium — Several brief requirements remain open while release documents claim completion

**Category:** Requirements drift, documentation accuracy

**Verified facts**

- The next-run readout does not update when controls change. The repository admits this ([README](README.md#L200)).
- The health panel checks only whether Action Scheduler functions are loaded. It does not show when the queue last processed an action ([Settings_Page](src/Admin/Settings_Page.php#L361)).
- “Run now” queues and redirects. It does not stream a result. This is a documented design change ([DECISIONS](DECISIONS.md#L334)).
- The README has no screenshot, although the brief requires one ([project brief](docs/PROJECT-BRIEF.md#L658)).
- The brief says a plain clone must work, but the repository requires a separate Composer install. This is an explicit decision ([DECISIONS](DECISIONS.md#L525)).
- The duplicate similarity default is 78, not the specified 82 ([Topic_Deduplicator](src/Content/Topic_Deduplicator.php#L50)). It remains filterable.
- The README states that every brief item is implemented and tested ([README](README.md#L193)). The changelog makes the same claim ([CHANGELOG](CHANGELOG.md#L90)).

**Impact**

The documentation prevents a user from making a correct release decision. Known gaps are presented as complete features.

**Required correction**

- Change the release status to beta or release candidate until the blockers are fixed.
- Add a requirement-trace table to the release process.
- State each accepted deviation and its user impact in the README.
- Do not call a feature complete because a method or class exists.

## Security findings in criticality order

### SEC-01 — High — Budget cap bypass and accounting errors

This is the security view of AS-03. It is a financial-control failure. An external unauthenticated user cannot directly schedule jobs, but normal queue concurrency can exceed the configured cap. A lower-privilege site author can also influence the paid duplicate-topic prompt through post titles. Fix AS-03 before automatic publishing or unattended schedules are enabled.

### SEC-02 — Medium — Prompt-injection controls are absent for grounded data and post titles

**Verified facts**

- The instructions claim that retrieved web content is wrapped in delimiters and is labeled as data ([INSTRUCTIONS](INSTRUCTIONS.md#L211)). No provider adapter or pipeline prompt adds this instruction.
- For server-side search tools, the plugin does not receive retrieved page text before the model reads it. Therefore the plugin cannot wrap that text itself.
- Current Anthropic documentation confirms that its web search runs as a server tool on Anthropic infrastructure ([Anthropic server tools](https://platform.claude.com/docs/en/agents-and-tools/tool-use/server-tools)).
- Existing post titles and topic keys are inserted into the topic request as plain user-prompt text with no data boundary ([Step_Propose_Topic](src/Pipeline/Step_Propose_Topic.php#L209)). Users with normal post-authoring rights can control post titles even though they cannot manage AutoScribe prompts.
- Generated HTML is sanitized before database insertion. This limits script injection, but it does not verify factual or editorial content.

**Inference**

A hostile web page or crafted post title can influence topic choice and article instructions. In automatic mode, this can publish unwanted claims or links. The likely impact is content integrity and reputation, not direct server code execution.

**Required correction**

- Add a strong system instruction that all search results, citations, titles, and topic keys are untrusted data and must never override system or user instructions.
- Put locally supplied titles and keys in a structured JSON data block with clear start and end markers.
- Do not claim that server-side search text is wrapped by the plugin.
- Force review mode when grounding is enabled, or require a second explicit risk acceptance for automatic publication.
- Consider provider domain allowlists when the provider supports them.

### SEC-03 — Low — Provider and image response sizes have no plugin limit

**Verified facts**

- Provider JSON responses are loaded into memory without a `limit_response_size` value ([Http](src/Providers/Http.php#L55)).
- Base64 image strings are decoded in memory ([OpenAI_Image](src/Providers/Image/OpenAI_Image.php#L106), [Google_Image](src/Providers/Image/Google_Image.php#L107)).
- URL images use `download_url()` with its default 300-second timeout and no plugin file-size limit ([Image_Sideloader](src/Media/Image_Sideloader.php#L120)).
- `download_url()` uses `wp_safe_remote_get()`. WordPress validates the initial URL and redirects against SSRF, so the arbitrary image URL is not an open SSRF issue. See [WordPress `download_url()`](https://developer.wordpress.org/reference/functions/download_url/) and [`wp_safe_remote_get()`](https://developer.wordpress.org/reference/functions/wp_safe_remote_get/).
- Image bytes are not checked with `wp_check_filetype_and_ext()`, `getimagesize()`, or an image editor before metadata generation.

**Inference**

A compromised or faulty provider can return a very large body or a decompression-bomb image. This can exhaust PHP memory, temporary storage, upload storage, or image-processing CPU.

**Required correction**

- Set maximum response bytes for JSON and image requests.
- Reject images above configured byte and pixel limits.
- Verify the actual image type before upload.
- Use a shorter explicit download timeout.
- Delete uploaded files if attachment insertion or metadata generation fails.

### SEC-04 — Low — Stored-key encryption has no safe failure when WordPress secrets are absent

**Verified facts**

- The encryption key is derived from `AUTH_KEY` and `SECURE_AUTH_KEY` ([Key_Store](src/Security/Key_Store.php#L273)).
- If either constant is absent, the code uses an empty string. If both are absent, all such installs derive the same key from `"|"` ([Key_Store](src/Security/Key_Store.php#L295)).
- Normal WordPress installations define these values. The risk applies to incomplete, damaged, or unusual configurations.

**Impact**

In that unusual state, a database dump is enough to decrypt the stored provider keys because the derivation input is predictable.

**Required correction**

- Refuse database key storage if either required secret is absent, empty, or a known placeholder.
- Show a health error and require `wp-config.php` provider constants in that state.

### SEC-05 — Low — CI actions use mutable major-version tags

**Verified facts**

- CI uses `actions/checkout@v5` and `shivammathur/setup-php@v2` ([CI workflow](.github/workflows/ci.yml)).
- Composer production dependencies are locked and `composer audit` found no current advisory.
- `composer outdated --direct` reports Action Scheduler 4.1.0 while this project is constrained to the 3.x series. No advisory was reported for the locked 3.9.3 release.

**Impact**

A compromised mutable GitHub Action tag can affect the build environment. This is a repository supply-chain risk. It is not a direct runtime plugin vulnerability.

**Required correction**

- Pin third-party actions to full commit SHAs.
- Use an update bot to propose reviewed SHA changes.
- Review Action Scheduler 4.x compatibility in a separate change. Do not upgrade only to remove an “outdated” report.

## Security controls that are good

The following controls were present and correctly placed in the reviewed paths:

- Admin state changes use both nonces and `autoscribe_manage_prompts` capability checks ([Actions](src/Admin/Actions.php#L316), [Settings_Page](src/Admin/Settings_Page.php#L121), [Prompt_Meta_Box](src/Admin/Prompt_Meta_Box.php#L427)).
- The prompt custom post type uses a dedicated capability family. Normal Authors cannot edit prompts ([Prompt_Post_Type](src/Prompts/Prompt_Post_Type.php#L68)).
- SQL values use `$wpdb->prepare()`. Dynamic table identifiers use the WordPress `%i` placeholder. No SQL injection path was found in the reviewed queries.
- Provider endpoints are fixed HTTPS origins. Model IDs in path segments use `rawurlencode()`.
- API keys use headers, not URL query strings.
- Stored API keys are never rendered back into the form ([Settings_Page](src/Admin/Settings_Page.php#L230)).
- Model HTML receives layered sanitization before post insertion.
- Admin output uses context-appropriate escaping in the reviewed screens.
- Image URL download uses WordPress safe remote retrieval, which reduces SSRF risk.
- The test bootstrap rejects unmocked HTTP calls, which prevents accidental use of real provider keys in tests.
- Uninstall preserves the metadata that identifies generated content while removing plugin configuration.

## Code quality assessment

### Strengths

- The namespaces and class boundaries are clear.
- Most value objects have narrow responsibilities.
- User-facing strings use translation functions.
- Input sanitization and output escaping are visible and consistent.
- The schedule calculator has the best test depth in the project. This matches its risk.
- The content sanitizer is stronger than a simple `wp_kses_post()` call.
- The code comments explain many non-obvious choices.
- The HTTP tripwire is a good test safety control.
- The build excludes development dependencies and includes runtime dependencies.

### Weaknesses

- Many comments state stronger properties than the code supplies. Examples include atomic budget reservation, both-call estimates, complete idempotency, and grounding delimiters.
- The suite tests internal assumptions more than external contracts. In 1.0.0, the Google request test omitted the structured-output fields entirely. It did not assert the obsolete shape, as the first audit incorrectly stated.
- Several methods have no reachable product path. `test_connection()` and the preview transient are examples.
- The central `Generator` method owns too much state and too many side effects.
- Return values from some WordPress writes are ignored. For example, the final `wp_update_post()` result is not checked ([Generator](src/Pipeline/Generator.php#L185)). A failed publish transition can still produce a successful run result.
- Database writes in `Run` do not check `$wpdb` failures. A failed insert can create a `Run` object with ID 0.
- The run table has useful indexes for prompt and status, but monthly global spend queries use `started_at` without a dedicated index. This will become slower as the table grows.

## Performance assessment

| Area | Rating | Assessment |
|---|---:|---|
| Schedule calculation | Good | Bounded loops and strong calendar tests. |
| Provider calls | Weak | Multiple 120-second calls can occur in one PHP request. |
| Queue resilience | Weak | No per-step resume state. No real queue-dispatch test. |
| Run-log query | Fair | Pagination exists. Monthly and global spend scans need an index on `started_at`, or a composite index based on actual query plans. |
| Admin notices | Fair | The pending-draft notice reads up to 100 drafts on every admin page for authorized users. |
| Media handling | Weak | No byte, pixel, or processing limit. |
| Retention | Good | A daily retention action and bounded UI pagination exist. |

## Test quality assessment

The suite has broad class coverage, but release confidence is lower than the number 185 suggests.

Important missing tests:

- Current first-party provider contract fixtures.
- A real Action Scheduler dispatch from scheduled action through completion.
- Concurrent budget checks.
- Retry after post creation and image failure.
- Accurate accumulated usage across proposal, body, repair, and duplicate paths.
- Settings save behavior.
- Reachable connection-test buttons.
- Visible Preview output.
- Draft and final-failure email notifications.
- MariaDB CI.
- A built-zip smoke test that activates the exact artifact.
- Failure injection for `$wpdb`, `wp_insert_attachment()`, metadata generation, and final `wp_update_post()`.

## Required remediation order

1. Fix the Google Interactions request contract and model suggestions.
2. Fix cost accumulation, duplicate-path accounting, retry classification, and atomic cap reservation.
3. Split the pipeline into persistent Action Scheduler steps keyed by one `run_id`.
4. Make retries resume the same run and prevent duplicate posts and attachments.
5. Wire provider defaults and Test connection controls into reachable product paths.
6. Make Preview visible and safe.
7. Add draft and final-failure notifications.
8. Enforce grounding capability and add truthful prompt-injection guidance.
9. Add response and image resource limits.
10. Correct the README, changelog, and release status.

## Final conclusion

AutoScribe has a solid scaffold and several well-built components. The schedule calculator, sanitizer, capability model, key UI handling, and code style are good. However, the release is not complete against its brief. The current Google text adapter has contract drift. The central queue architecture rejects the brief’s main timeout control. The cost cap is not dependable. Retry behavior can duplicate drafts and repeat paid work. Several admin features exist only in text, inactive methods, or transient writes.

The correct status is **beta with known release blockers**, not stable 1.0.0. After AS-01 through AS-06 are fixed and the real queue path has integration coverage, the project can be reassessed for production use.

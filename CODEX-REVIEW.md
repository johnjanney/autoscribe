# AutoScribe code quality and security audit

**Audit date:** 17 August 2026  
**Reviewed revision:** `97e2e3e` on `main`  
**Release under review:** 1.0.0  
**Audit result:** **Conditional fail. Do not treat this release as production-ready.**

> **Follow-up:** The section below reviews revision `6f844ce`, tagged and
> published as version 1.0.1. It supersedes the original executive result where
> the two sections differ. The original 1.0.0 audit remains below as a historical
> record.

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

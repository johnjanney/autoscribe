# Response to the Codex audit

**Responding to:** [CODEX-REVIEW.md](CODEX-REVIEW.md), audit dated 17 August 2026 against `97e2e3e`
**Response date:** 17 August 2026
**Release under response:** 1.0.0 → 1.0.1

---

## Summary

Every finding was checked against the code, and against first-party provider
documentation where the claim was about an external contract. **Thirteen of the
fourteen findings are confirmed.** One is confirmed in part and refuted in part.
Two supporting claims inside otherwise-correct findings turned out to be wrong,
and are corrected below.

Twelve findings are fixed in this response. **AS-02 is not fixed**, and the
reasoning for that is set out in full in its own section — it is an
architectural change, not a defect repair, and it is now documented as a known
deviation rather than left implied.

| Finding | Verdict | Status |
|---|---|---|
| AS-01 Google contract | Confirmed in part; model-ID claim refuted | Fixed |
| AS-02 Single-action pipeline | Confirmed | **Not fixed — documented** |
| AS-03 Cost accounting and caps | Confirmed, all five sub-points | Fixed |
| AS-04 Retry duplicates paid work | Confirmed | Fixed, except step resume |
| AS-05 Inactive defaults and connection test | Confirmed | Fixed |
| AS-06 Preview never displayed | Confirmed | Fixed |
| AS-07 Review notifications missing | Confirmed | Fixed |
| AS-08 Grounding capability unenforced | Confirmed | Fixed |
| AS-09 Documentation overstates completeness | Confirmed, except threshold claim | Fixed |
| SEC-01 Budget cap bypass | Confirmed (= AS-03) | Fixed, with a stated bound |
| SEC-02 Prompt-injection controls | Confirmed | Fixed, and the false claim corrected |
| SEC-03 No response or image size limits | Confirmed | Fixed |
| SEC-04 Predictable key without salts | Confirmed | Fixed |
| SEC-05 Mutable CI action tags | Confirmed | Fixed |

**Verification after the changes:** PHPCS passes with zero errors across 98
files. PHPUnit passes 200 tests and 741 assertions, up from 185 and 692. No test
contacts a live provider; the bootstrap tripwire still fails any request that
reaches the network.

---

## Two claims in the audit that are wrong

Both sit inside findings that are otherwise correct. They are recorded here
because acting on them would have made the plugin worse.

### `gemini-3.7-flash` is a real, generally available model

AS-01 states that the first suggested Google text model is "**Not found in
documents**", cites the Interactions API reference model list as evidence, and
requires that the ID be replaced or removed.

It is a current GA model. Google's own model page documents `gemini-3.7-flash`
as generally available and production-ready, with a 1M-token context window and
tunable thinking levels, and the Interactions migration guide uses it in its
worked examples. The model list on the API reference page the audit cites is
simply incomplete — it also omits several other current identifiers.

The suggestion is unchanged. Removing a working default in favour of an older
one would have been a regression carried out on the strength of one stale page.

### The Google test did not assert a wrong request shape

AS-01 and the test-quality section both say the Google test "asserts a request
shape that no longer matches the current Google contract", and offer it as the
clearest example of the suite testing internal assumptions.

The test asserted nothing whatsoever about the structured-output fields. It
checked the URL, the auth header, `model`, `input`, `system_instruction`, and
`max_output_tokens`, and stopped there.

The distinction matters for the remedy. A test asserting a wrong shape gets
updated when the shape changes. A test asserting *nothing* is why the defect
survived a release with a passing suite, and the fix is a new assertion, not a
corrected one. `GoogleTest::test_structured_output_uses_top_level_response_format`
now pins the correct shape and asserts that the removed fields are absent.

---

## Finding-by-finding

### AS-01 — Google structured output — **confirmed and fixed**

**Verified.** The adapter sent `generation_config.response_mime_type` and
`generation_config.response_schema`. Confirmed against Google's current
structured-output guide and the Interactions API reference: structured output is
a **top-level `response_format` object** carrying `type`, `mime_type`, and
`schema`. The `generateContent` pair was removed when Interactions replaced it.

Because `Step_Propose_Topic` requests a schema from any provider reporting strict
JSON support, this affected every Google run, not only those asking for a
structured article.

**Also checked, and correct as written:** the endpoint URL, the
`{"type": "google_search"}` grounding tool shape, the `usage.total_input_tokens`
/ `usage.total_output_tokens` response path, and `Source_Extractor`'s handling of
`annotations` entries — which is where Interactions puts `url_citation` sources.
None of these needed changing.

**Fixed:** `src/Providers/Text/Google.php` now sends the top-level object.

**Not done:** recorded fixtures captured from a live API, and an optional live
smoke test. Both are worth having and neither can be produced without a funded
key; the new shape test is asserted against the published contract instead.

### AS-02 — the whole pipeline runs in one queue action — **confirmed, not fixed**

**Verified.** `Queued_Run_Handler::handle()` calls `Generator::run()` once, and
that method performs budget check, topic proposal, body generation, post insert,
image generation, sideload, metadata generation, status transition, and cost
settlement in a single PHP request. Provider calls permit 120 seconds each, and a
run can make up to five. The `payload` column holds only grounding source URLs,
so there is no step state to resume from.

**This is not fixed, and the decision is deliberate.** Splitting the pipeline
into six queue actions is not a defect repair: it means a new dispatcher, a
serialised state contract in `runs.payload`, six hooks with their own idempotency
guards, reworked retry semantics keyed on step rather than run, and integration
tests that drive Action Scheduler end to end. That is a phase of work, and it
changes the plugin's central execution model. Making that call silently, inside a
review response, would be the wrong way to decide it.

What has changed is that it is no longer an implied gap. The README now states it
first among known limitations, in the user's terms: a run occupies one PHP
request for 30 to 120 seconds, a host with a short `max_execution_time` can cut
it off part-way, and a retry restarts from the beginning rather than resuming.

Two of the consequences the audit attributes to this finding **are** fixed, under
AS-04: a retry no longer leaves a second draft behind, and the failure modes that
made a retry expensive are now classified as permanent.

**Recommend:** treat this as the next phase of work rather than a patch.

### AS-03 — cost accounting and cap enforcement — **confirmed on all five points, fixed**

Each sub-point was reproduced in the code, and each now has a test.

1. **Usage was assigned, not accumulated.** `Run::record_text_usage()` overwrote
   the token columns. `Step_Propose_Topic` recorded first, `Step_Generate_Body`
   then replaced its figures, and the proposal call vanished from the settled
   cost. Now accumulates. The repair call inside the body step previously
   re-added the first call's tokens to compensate; that compensation is removed,
   since it would now triple-count.

2. **A duplicate skip wrote a flat zero** despite having paid for one or two
   proposals — and `Budget_Guard::month_to_date_cents()` excluded skipped rows
   from the total entirely, so the money was invisible twice over. `Run::skip()`
   now settles from measured usage, and the status exclusion is removed. A budget
   skip still settles to zero, because it genuinely spends nothing.

3. **The estimate priced one body call.** The comment claimed it covered both
   calls; the arithmetic covered neither the 512-token proposal allowance nor the
   repair. The estimate now bounds two proposals, the body, and one repair, with
   the allowances named as constants rather than buried in an expression.

4. **Check and reserve were separate statements with no lock.** Confirmed as a
   real TOCTOU window, and an ordinary one rather than a theoretical one, since
   Action Scheduler runs actions in batches. `Budget_Guard::confirm_reservation()`
   now re-checks after the reservation is written, counting only rows up to and
   including the reserving run's own ID. Two runs that reserved concurrently
   therefore reach different answers — the earlier row sees only itself and
   proceeds, the later sees both and stands down — with the ordering supplied by
   the database's auto-increment, so there is no tie to break.

   The audit asks for "a documented overshoot bound". It is one run's estimate: a
   run already past the check cannot be recalled. That is now stated in the
   README, alongside the point that a provider-side spending limit is the only
   hard ceiling.

5. **A failed run kept its reservation.** `Run::fail()` now settles from measured
   usage, so a run that fell over on its first call no longer counts a whole
   article and image against the month.

**Not done:** concurrent-worker tests. The suite runs single-process against one
database connection, so a genuine race cannot be provoked from inside it; the
ordering property is instead enforced by the query itself, which is deterministic
and readable. Worth revisiting if the project ever adds a process-level harness.

### AS-04 — retries duplicate drafts and repeat paid work — **confirmed, fixed except step resume**

**Verified.** `Retry_Policy` treated any code outside a short permanent list as
retryable, which included `autoscribe_duplicate_topic`,
`autoscribe_budget_exceeded`, and every validator code. A duplicate topic paid
for two more proposals per retry. A body that failed its single permitted repair
received two more full queue retries, turning section 5.1's one-repair limit into
six paid calls. `Run::start()` always wrote `attempt = 1`, so the Run Log's
attempt column never showed the real number. And because post assembly precedes
image generation, a transient required-image failure produced one draft per
attempt with no link between them.

**Fixed:**

- Those codes are now permanent, with the reasoning recorded in the class.
- `Generator::run()` takes the attempt number and `Run::start()` records it.
- `Run::adoptable_draft()` finds the draft left by the prompt's previous failed
  run, and `Generator` binds it to the new run so `Step_Assemble_Post` updates it
  rather than inserting another. Only a draft still carrying its
  `_autoscribe_run_id` link is adopted — a post someone has since published or
  edited is not the plugin's to overwrite.

**Not done:** retrying the same `run_id` and resuming the failed step. That is
AS-02 by another name; there is no saved step state to resume from.

### AS-05 — inactive default models and connection test — **confirmed, fixed**

**Verified.** `Settings::default_model()` appeared in exactly two places, both of
them rendering the settings form. Generation read the prompt field and fell
straight through to `suggested_models()[0]`. An administrator could set a site
default, watch it save, and have it ignored on every run. Separately,
`Actions::test_connection()` had no hook, no button, and no form anywhere in the
plugin.

**Fixed:** a new `Providers\Model_Resolver` resolves prompt → site default →
adapter suggestion, and is used by both text steps, the image step, and the
budget estimate. `Actions::handle_test_connection()` is registered on
`admin_post_`, protected by its own nonce and capability check, and reachable
from a per-provider button on the Settings screen; the result is shown next to
the button.

The README claim that model endpoints are checked before a paid generation call
was false and is corrected: they are contacted only by that button.

### AS-06 — preview generated, charged, never displayed — **confirmed, fixed**

**Verified.** Nothing in the repository read `PREVIEW_TRANSIENT`; only its
definition and its single write existed. The redirect message told the user the
preview was shown below.

**Fixed:** `Prompt_Meta_Box::render_preview()` reads it, renders title, excerpt,
and body, and deletes the transient so a stale article is not redisplayed. The
body goes through the same `Content_Sanitizer` the pipeline applies before
`wp_insert_post()`, then `wp_kses_post()` — rendering model output unfiltered
into an admin page would have made the preview a softer target than the post it
previews.

**Not done:** moving preview generation onto the queue. It remains synchronous
in the admin request, and the description on the control says so.

### AS-07 — human-review notifications incomplete — **confirmed, fixed**

**Verified.** The only `wp_mail()` call was the 80-percent budget warning.
Neither the per-draft review email nor the final-failure notification existed,
and the pending-draft notice read at most 100 recent drafts.

**Fixed:** `Generator::send_review_notice()` sends title, opening 200 characters,
and a direct edit link whenever a run finishes in draft.
`Generator::send_failure_notice()` is called by `Queued_Run_Handler` only after
the retry branch has declined — so it is one email after the attempts are spent,
not one per attempt. Deliberate skips are outcomes rather than faults and are not
mailed. The draft count now uses a `WP_Query` count with an `EXISTS` comparison
on the indexed meta key, so it is accurate rather than capped.

### AS-08 — grounding capability not enforced — **confirmed, fixed**

**Verified.** `grounding_enabled` was an unconditional checkbox, the admin script
only handled tabs, and `Step_Generate_Body` silently dropped grounding for a
provider without it — producing an article the user believed was researched, and
an empty Sources list with no stated reason.

**Fixed** at all three layers the audit asks for:

- The editor disables the control and shows the reason when a provider without
  search is selected, driven by a capability map emitted from the adapters rather
  than duplicated in JavaScript.
- `Prompt_Meta_Box::save()` refuses the combination server-side, because the REST
  API, WP-CLI, and an imported prompt all reach that path without seeing the
  control.
- `Step_Generate_Body` now fails with `autoscribe_grounding_unsupported` instead
  of quietly changing the requested behaviour. The code is classified permanent,
  since a configuration error will not fix itself on a retry.

### AS-09 — documentation overstates completeness — **confirmed, with one exception**

**Verified:** the non-live next-run readout, the health panel showing only
whether Action Scheduler is loaded, "Run now" queueing rather than streaming, the
missing screenshot, and the README and CHANGELOG both claiming every brief item
was implemented and tested.

**One claim is not drift.** The audit lists the duplicate-similarity default of
78 rather than the brief's 82 as a requirements gap. The brief's own requirement
is that the threshold be *filterable* so it can be tuned per site, and it is. The
class documents why the default sits lower — `similar_text()` compares characters
rather than meaning, and scores hyphenated slugs badly. That is a reasoned,
recorded, adjustable decision, not undisclosed drift.

**Fixed:** version bumped to 1.0.1 and described as a release candidate; the
README states the single-action pipeline, the cap's overshoot bound, and the
missing screenshot as known deviations; the 1.0.0 changelog entry now carries a
correction notice pointing at 1.0.1 rather than being quietly rewritten. The
health panel gained a "Queue last processed" row via
`Scheduler::last_processed()`, since a loaded library says nothing about a queue
that has not run for a week.

**Not done:** the screenshot, which needs a running site to capture, and is now
listed as an open item rather than left unmentioned.

### SEC-01 — budget cap bypass — **confirmed, fixed**

Addressed in full under AS-03. The overshoot bound is stated, and the README no
longer describes the cap as stronger than it is.

### SEC-02 — prompt-injection controls absent — **confirmed, fixed**

**Verified,** including the part of the finding that is about honesty rather than
code: `INSTRUCTIONS.md` claimed the plugin wraps retrieved web content in
delimiters and tells the model to treat it as data. It did not, and it cannot —
search runs as a server-side tool on the provider's infrastructure, and the
plugin never sees the retrieved text before the model reads it. Separately,
existing post titles and topic keys went into the proposal prompt as plain prose,
and any user who can author a post can write a title.

**Fixed:**

- Titles and topic keys now go inside an explicitly labelled untrusted-data
  block, JSON-encoded so a title containing the closing marker cannot end the
  block early, preceded by an instruction that nothing inside it may change the
  task, the output format, or those rules.
- The INSTRUCTIONS warning is rewritten to say what is true: the plugin cannot
  wrap or filter what the provider retrieved, it does wrap what it supplies
  itself, and human review is the only control that covers the difference.

**Not done:** forcing review mode whenever grounding is on. Section 10 already
provides a global force-review switch, and overriding a per-prompt setting from a
different feature's checkbox would make the safety catch harder to reason about,
not easier. The documentation recommends it instead. Worth revisiting if the
project decides the recommendation is not enough.

### SEC-03 — no response or image size limits — **confirmed, fixed**

**Verified,** including the audit's own correction that `download_url()` uses
`wp_safe_remote_get()` and so is not an open SSRF path. The exposure is resource
exhaustion, not redirection.

**Fixed:** `Http` sets `limit_response_size` on both verbs. `Image_Sideloader`
caps image bytes, caps decoded pixels — a decompression bomb is small on disk and
enormous in memory, so the byte limit alone is not enough — verifies the real
type with `getimagesize()` and `wp_check_filetype_and_ext()` rather than trusting
the provider's declared MIME type, stores the verified type on the attachment,
uses an explicit 60-second download timeout instead of the 300-second default,
and deletes the uploaded file if verification or attachment insertion fails.

### SEC-04 — predictable key when salts are absent — **confirmed, fixed**

**Verified.** With both constants undefined, every affected install derived its
encryption key from the string `"|"`.

**Fixed:** `Key_Store::set()` refuses to store a key when either salt is missing,
empty, or still the placeholder shipped in `wp-config-sample.php` — which is
published in the WordPress source and so is not a secret. The error names the
remedy. The Settings screen now surfaces a failed key save instead of reporting
"Settings saved", which it previously did while discarding the key, and the
health panel reports the salt condition.

### SEC-05 — mutable CI action tags — **confirmed, fixed**

**Fixed:** `actions/checkout` and `shivammathur/setup-php` are pinned to full
commit SHAs, with the release each SHA corresponds to kept in a trailing comment
so a future bump is a reviewable diff.

**Not done, on the audit's own advice:** Action Scheduler stays on 3.9.3. The
audit is right that upgrading to 4.x only to clear an "outdated" report would be
a change made for the wrong reason.

---

## Fixed beyond the numbered findings

These come from the audit's code-quality section rather than its findings list.

- **`wp_update_post()` return value ignored on the final publish transition.** A
  refused transition passed unnoticed and the run reported success for a post
  still in draft. Since section 10 makes the draft/published distinction the
  entire safety model, the failure is now surfaced.
- **`Run::start()` ignored `$wpdb->insert()` failure,** returning a `Run` wrapping
  ID 0. Every subsequent write then matched no row — including the budget
  reservation — and the run appeared to succeed leaving no trace. It now returns
  a `WP_Error`, classified permanent.
- **No index on `runs.started_at`.** Site-wide monthly spend filters on that
  column alone, and the composite `prompt_started` cannot serve a query that does
  not constrain its leading column. Added, with `DB_VERSION` bumped so the
  upgrade path runs.

---

## Tests added

200 tests, up from 185.

- `Providers/Model_ResolverTest` — five tests over the prompt → default →
  suggestion order, including whitespace and the nothing-configured case.
- `Pipeline/Cost_AccountingTest` — six tests: usage accumulates, a duplicate skip
  records what its proposals cost, a budget skip costs nothing, a failure settles
  rather than keeping its reservation, duplicate spend counts towards the monthly
  total, and the estimate exceeds a single body call.
- `Providers/GoogleTest::test_structured_output_uses_top_level_response_format` —
  asserts the correct shape and the absence of the removed fields.
- `Pipeline/Retry_PolicyTest::test_paid_outcomes_are_not_retried` — eight codes
  that must never be retried.
- `Admin/Prompt_Meta_BoxTest` — two tests over grounding capability enforcement
  on save. The existing round-trip fixture is pinned to a search-capable provider
  and says why, since it previously selected DeepSeek by taking the last choice
  of every select.

## Test gaps the audit named that remain open

Recorded rather than quietly dropped:

- Recorded first-party provider contract fixtures, and an optional live smoke
  test. Both need funded keys.
- An Action Scheduler dispatch test from scheduled action through completion.
- Concurrent budget checks — not provokable in a single-process suite.
- Settings save behaviour, and the connection-test buttons now that they exist.
- Visible preview output, and the two notification emails.
- MariaDB in CI, and a smoke test that activates the built zip.
- Failure injection for `$wpdb`, `wp_insert_attachment()`, and metadata
  generation. The `wp_update_post()` and `$wpdb->insert()` paths are now handled
  in code but are not yet covered by a test.

---

## Where this leaves the release

The audit's conclusion was **beta with known release blockers**, and its required
remediation order put AS-01 through AS-06 ahead of a release decision. Of those
six, five are fixed and one — AS-02 — is a deliberate, documented deviation
awaiting a decision rather than a patch.

1.0.1 is accordingly published as a **release candidate**, not a settled stable
release. The three things a user needs in order to judge it for themselves — the
single-action pipeline, the cap's overshoot bound, and the missing screenshot —
are stated plainly in the README rather than inferred from a passing test count.

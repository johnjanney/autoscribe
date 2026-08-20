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

---
---

# Response to the Codex follow-up review

**Responding to:** the follow-up section of [CODEX-REVIEW.md](CODEX-REVIEW.md),
dated 18 August 2026 against `6f844ce` (tag `v1.0.1`)
**Response date:** 18 August 2026
**Release under response:** 1.0.1 → 1.0.2

---

## Summary

Eleven follow-up findings. Every one was checked against the code, and against
first-party documentation where the claim was about an external contract.

**Nine are confirmed. Two are rejected on evidence.** Eight of the nine confirmed
findings are fixed here; the ninth is FR-05, the single-action pipeline, which
remains a deliberate deferral for the reasons given below.

Verifying FR-02 also turned up a defect neither review found: adoption was
resolved after the body call, so duplicate detection counted the previous
attempt's own draft as a topic already covered. Every retry that got as far as a
body call was therefore skipped as a duplicate of itself, which made adoption
unreachable in practice. It is fixed here and covered by a test.

| Finding | Verdict | Status |
|---|---|---|
| FR-01 Cap concurrency, lost reservation, image estimate | Confirmed, all three | Fixed |
| FR-02 Draft adoption overwrites unrelated work | Confirmed | Fixed |
| FR-03 `gemini-3.7-flash` unverified | **Rejected** — documented as GA | No change |
| FR-04 Text and image defaults collide | **Rejected** — the slugs differ | No change |
| FR-05 Single-action pipeline | Confirmed | **Not fixed — deferred, documented** |
| FR-06 Retry classification open by default | Confirmed | Fixed |
| FR-07 Prompt-injection mitigation incomplete | Confirmed | Fixed for the two in-scope cases |
| FR-08 Image byte limit applied after download | Confirmed | Fixed |
| FR-09 Weak-salt records readable after upgrade | Confirmed | Fixed |
| FR-10 "Queue last processed" reports scheduled time | Confirmed | Fixed |
| FR-11 Changelog and release-status claims | Confirmed in part | Fixed |

Also accepted from the follow-up's "corrections to the audit response":

- **The similarity-threshold rebuttal is withdrawn.** The brief does name 82, and
  78 is a deviation whether or not the value is filterable. `DECISIONS.md` was
  worse than the code: it went on quoting 82 after the code had settled on 78.
  The value is unchanged and the reasoning is unchanged; both documents now state
  the deviation instead of hiding it.
- **The release-candidate framing is withdrawn.** A stable tag, a dated changelog
  section, and a GitHub release marked neither draft nor pre-release make it a
  release. Calling it a candidate in the README while shipping it as a release
  was the kind of drift the audit exists to catch.

**Verification after the changes:** PHPCS passes with zero errors and zero
warnings across 102 files. PHPUnit passes 215 tests and 782 assertions, up from
200 and 741. No test contacts a live provider; the bootstrap tripwire still fails
any request that reaches the network.

---

## FR-01 — Confirmed, all three parts. Fixed.

### The race is real, and the 1.0.1 reasoning was wrong

The follow-up's demonstration holds, and the mechanism is worth stating exactly,
because 1.0.1's comment argued the opposite in some detail.

`confirm_reservation()` counts rows with `id <= $run_id`. The argument was that
the auto-increment gives a total order over reservations, so of two concurrent
runs the earlier row sees only itself and the later row sees both. **A row's ID
is assigned when the run is inserted, not when its reservation is written**, so
the auto-increment orders the *inserts* and nothing else. Interleave it this way:

1. A and B both insert. A gets the lower ID.
2. B writes its reservation and re-reads with `id <= B`. A's row exists but still
   carries `cost_cents = 0`, so B sees only its own reservation. B passes.
3. A writes its reservation and re-reads with `id <= A`. That bound excludes B
   entirely. A sees only its own reservation. A passes.

Both spend. Reversing the ID order lets more than two through. The claimed
one-run overshoot bound was not a bound.

**Fix.** `Spend_Lock` (`src/Cost/Spend_Lock.php`) takes a named MySQL lock —
`GET_LOCK`, scoped by database name and table prefix so sites on a shared server
do not serialise against each other — and `Step_Budget_Check` performs the whole
read-check-reserve inside it. `GET_LOCK` is held by the connection rather than by
a row, so it works across processes without a schema change or a transaction.
Where the lock cannot be taken — a server that does not implement it, or a
ten-second wait that expires — the run falls back to the 1.0.1 ordering pass,
which is now documented in both the class and the README as a narrowing rather
than an equivalent.

The README's one-run claim is removed. What replaces it says what is actually
true: a run already past the check cannot be recalled, and the reserved figure is
an estimate, so a run that costs more than estimated overshoots by the
difference.

### The lost reservation

Confirmed. `Run::update()` discarded `$wpdb->update()`'s return value, so a
reservation that never reached the database left the run spending against a cap
that could not see it. `update()` now returns whether the write succeeded — false
only, since zero affected rows is ambiguous — `reserve_cost()` passes that up,
and `Step_Budget_Check` fails the run with `autoscribe_reservation_failed` before
the first provider call.

### The missing image cost

Confirmed, and the practical case is worse than the finding states. Generation
resolves the image model as prompt → site default → *the adapter's own
suggestions*; the estimate resolved it as prompt → site default → empty string.
An empty string makes `Pricing_Table` fall back to the text model's rates, and
the seeded Claude rows carry a zero per-image rate. So the configuration that
reserved nothing for its image was not an exotic one — it was a Claude article
with an OpenAI picture and both model fields left alone, which is the default
state of a new prompt.

`Budget_Guard` now resolves both models through the registry exactly as
generation does. `test_an_image_is_priced_even_when_no_image_model_is_set()`
covers it.

### Tests

A multi-process concurrency test is still not possible in a single-process
PHPUnit suite, and the follow-up is right that its absence is a gap. What is
covered: that the lock is genuinely available on the test database rather than
silently failing to every run's fallback path, that a failed reservation stops
the run before any provider call — the bootstrap tripwire is the proof of that
second half — and the image-estimate regression.

---

## FR-02 — Confirmed. Fixed, and a further defect fixed with it.

Every stated fact checks out. `adoptable_draft()` was called on every run rather
than only on retries; the query asked only for the newest failed run of the
prompt with a post; and the safety check tested that the post was a draft and
that its run meta was non-empty, without testing that the meta matched the row it
had just selected.

The consequence is as described. After retries are exhausted the abandoned draft
stays adoptable for ever, so the next ordinary scheduled occurrence — a different
article, days later — overwrites it. A reviewer who edits a failed draft and
leaves it in draft status loses the edit to the next run.

**Fix.** Five conditions now have to hold, and each one closes a different way of
destroying work that is not this run's:

- `attempt > 1`, so a scheduled occurrence never adopts;
- the candidate is the row immediately before this one for this prompt, so an
  unrelated or overlapping run in between ends the series;
- that row failed, and its attempt number is exactly one lower;
- the post still carries *that row's* ID in `_autoscribe_run_id`;
- `post_modified_gmt` is at or before the failed run's `finished_at`. The failed
  run wrote the draft and then closed itself, so anything later came from a
  person.

**The defect found while testing this.** Adoption was resolved after the body
call, which put it after duplicate detection — and duplicate detection counts
drafts. A retry therefore proposed a topic, found its own abandoned draft in the
already-covered list, and was skipped as `skipped_duplicate` before it could
adopt anything. Adoption after a successful body call was unreachable in
practice, which is presumably why neither review's reading of the code caught it:
the code path is plainly there, it just never runs. The adoptable draft is now
resolved before the proposal call and excluded from both the covered list and the
title check.

Six tests cover the boundary in both directions: a retry adopts; a later
scheduled run does not; an edited draft is refused; a published post is refused;
an intervening run ends the series; a relinked post is refused.

---

## FR-03 — Rejected. `gemini-3.7-flash` is documented as generally available.

The follow-up records this as **"Not found in documents"** and cites Google's
latest-model page as identifying `gemini-3.6-flash` and `gemini-3.5-flash-lite`
as the current GA Flash models.

That page says the opposite. Retrieved 18 August 2026:

- **[Gemini API — latest model](https://ai.google.dev/gemini-api/docs/latest-model)**
  is *about* `gemini-3.7-flash`. It states that "Gemini 3.7 Flash
  (`gemini-3.7-flash`) is generally available (GA)", describes it as ready for
  production use, and names `gemini-3.6-flash` as a migration *alternative* — the
  older model, not the current one.
- **[Gemini API — models](https://ai.google.dev/gemini-api/docs/models)** lists
  `gemini-3.7-flash` among the stable text models, alongside `gemini-3.6-flash`
  and `gemini-3.5-flash-lite`.

The 1.0.1 response was right on the substance and wrong to assert it without a
URL; that omission is what left the claim unverifiable, and the citations are
above so it is not repeated. The adapter's suggestion order is unchanged.

Two things in the finding are accepted regardless: no live model-list call has
been made against a funded key, and both conclusions rest on documentation. And
the underlying risk the finding is pointing at is real — which is why section 2.2
of the brief makes model IDs editable configuration, why the field is a text box
with a dropdown rather than a fixed list, and why `Model_Resolver` puts the
adapter's suggestion last, behind the prompt field and the site default.

---

## FR-04 — Rejected. The text and image adapters do not share a slug.

The finding's chain of consequences all depends on its first premise, that
"OpenAI and Google use the same slug for their text and image adapters." They do
not:

| Adapter | `slug()` |
|---|---|
| `Text/OpenAI` | `openai` |
| `Image/OpenAI_Image` | `openai_image` |
| `Text/Google` | `google` |
| `Image/Google_Image` | `google_image` |

Because the slugs differ, each consequence falls with the premise:

- Defaults are keyed by slug, so `openai` and `openai_image` are separate
  settings and separate form rows. There is no collision to split.
- `Settings_Page::all_providers()` builds its map from those slugs, so writing
  image adapters after text adapters adds four rows; it does not overwrite two.
- `Actions::test_connection()` reads
  `text_provider( $slug ) ?? image_provider( $slug )`. No text adapter answers to
  `openai_image` or `google_image`, so the image adapter is what the null
  coalescing reaches, and the image connection test is reachable for both.

Keys are stored per slug too, so the two capabilities also hold independent API
keys — which is what section 2.1 of the brief requires, since a user must be able
to run Claude for text and Google for pictures.

No change. The requested "OpenAI and Google tests that leave both prompt model
fields blank" is a fair ask on its own merits, and `Model_ResolverTest` plus the
new `test_an_image_is_priced_even_when_no_image_model_is_set()` cover the blank
case for the path where it mattered.

---

## FR-05 — Confirmed. Still not fixed. Still documented.

Nothing here is disputed. One request can contain up to four text calls at a
120-second timeout each, an image generation call, a bounded image download,
attachment metadata generation, and a post write. There is no step state, so a
retry re-runs from the beginning and pays for it again.

It is not fixed for the same reason as last time, and the reason has not got any
better with age: this is a rewrite of the pipeline into six queued actions with
serialised state in `runs.payload`, plus idempotency keyed by `run_id` at each
boundary, plus a resume path, plus the tests to prove a step never runs twice. It
is the largest single piece of work left in the plugin and it is not a patch.

What has changed is the surrounding claim. The README no longer describes the
plugin as brief-complete, lists this as the one outstanding architectural gap,
and states plainly that a retry re-runs from the beginning and pays for it.

---

## FR-06 — Confirmed. Fixed.

The class comment said only transient transport failures were retried; the
implementation retried everything absent from a denylist. The follow-up's point
about direction is the important one: under a denylist, every error code that had
not yet been thought of — including every code a later release adds, and every
code a provider starts returning without warning — was retried three times,
paying for the same answer each time.

`Retry_Policy` now holds a `TRANSIENT` allowlist of three codes:
`autoscribe_transport_error`, `autoscribe_provider_rate_limited`, and
`autoscribe_provider_unavailable`. Everything else is permanent, whether or not
anyone has classified it. The list is filterable through
`autoscribe_transient_error_codes`, because a provider can start returning a
transport-level failure under a code this plugin does not know and waiting for a
release to retry it would be worse than letting a site say so itself.

`permanent_codes()` is replaced by `transient_codes()`. The list means the
opposite now, so the name had to change with it.
`test_an_unknown_code_is_not_retried()` is the test the finding asked for.

---

## FR-07 — Confirmed. Fixed for the two cases inside the plugin's control.

Both stated paths are real, and both are fixed by extending the same control
1.0.1 built for the already-covered list. `Security\Untrusted_Block` now holds
the markers and the "this is data, not instructions" preamble in one place, and
three call sites use it:

- the agreed title and topic key sent to the body call, which were previously
  interpolated into plain instruction text;
- the rejected response and the validation error sent to the repair call, which
  were previously pasted mid-sentence — and a response that failed validation is
  precisely the one most likely to contain something other than an article;
- the collision reason sent on a proposal re-ask, which the finding does not
  mention but which quotes an existing post title and so has the same shape.

The third path — server-side search results — is not fixable from here, and the
finding says so. The provider's model reads them after the request leaves; the
plugin never sees them and cannot delimit them. `INSTRUCTIONS.md` states this and
the README's recommendation stands: keep review mode on wherever grounding is on.

The finding's assessment of impact is accepted as written. This is a content
integrity problem — unwanted titles, claims, or links in automatically published
posts — not server code execution.

---

## FR-08 — Confirmed. Fixed.

`download_url()` streams the whole response to a temporary file with no
caller-supplied ceiling, the file was then read whole into memory, and only then
was `MAX_IMAGE_BYTES` consulted. The check protected the uploads directory and
nothing before it.

The fetch no longer uses `download_url()`. It uses `wp_safe_remote_get()` with
`limit_response_size` set to the limit plus one byte, so the transfer stops at
the ceiling and the bound covers bandwidth, disk, and memory. `wp_safe_remote_get()`
rather than `wp_remote_get()`, because the URL arrives in a provider response and
should not be able to reach the site's private network. The existing file-type
and pixel checks are unchanged and still run after the write.

Four tests were added, covering the previously untested URL branch entirely: the
limit and timeout actually reach the transport, an oversized response is
rejected, an error status is reported, and a valid URL image is attached with its
alt text.

---

## FR-09 — Confirmed. Fixed.

Accurate, including the sharp edge: 1.0.1 stopped new keys being written under
unusable salts and left every key 1.0.0 had already written that way in place and
in use. On any site that had the problem, the fix changed nothing.

`source()` now returns a new `SOURCE_UNSAFE` when a stored record exists and the
salts are unusable, and `get()` refuses it with `autoscribe_key_unsafe` rather
than decrypting under the predictable key. The Settings screen describes the
state and asks for the key again once real salts are installed, or for a
`wp-config.php` constant instead.

The record is refused, not deleted. Deleting an administrator's credential
without being asked is its own kind of damage, and the follow-up asks for the
record to be marked unsafe rather than removed.

**Not covered by a test, and this is a real gap.** The salts are PHP constants
defined by `wp-config.php` before the plugin loads, and a test cannot un-define
them; the only seam would be a filter existing solely so the suite could lie
about the environment, which is production API in service of a test. It is listed
in the README's known limitations rather than left implied.

---

## FR-10 — Confirmed. Fixed.

Both halves of the mechanism are as described: `ActionScheduler_DBStore` maps
`orderby => 'date'` onto `scheduled_date_gmt`, and a schedule object's
`get_date()` returns the time the action was armed for. The result was that an
action due last Tuesday and executed a moment ago was reported as last processed
last Tuesday — which inverts the panel's whole purpose, since a queue that is
badly backed up and catching up looked stalest exactly when it was recovering.

The query now orders by `modified`, which the store maps onto `last_attempt_gmt`,
and reads the completion time through `ActionScheduler::store()->get_date()`,
which returns `last_attempt_gmt` for any action that is not pending. The lookup
is wrapped, because Action Scheduler throws if the row is pruned between the
query and the read.

---

## FR-11 — Confirmed in part. Fixed.

Accepted and corrected:

- **The 1.0.1 changelog claims are too broad.** The published 1.0.1 entry now
  carries a dated correction notice naming the four claims that were wider than
  the code, and pointing at 1.0.2.
- **The README contradicted itself.** It said one requirement was knowingly
  unmet and then listed three. It now names all three up front: the single-action
  pipeline, the live next-run readout, and the screenshot.
- **The 82-percent threshold.** Covered above. The code keeps 78 for its stated
  reason; `DECISIONS.md` and the README now record the deviation rather than
  quoting the brief back.
- **Release-candidate status.** Withdrawn. 1.0.2 is a normal patch release and is
  described as one.

One item is not accepted as drift: "plain-clone installation". `vendor/` is
committed, which is what section 12 of the brief asks for, and a plain
`git clone` into `wp-content/plugins` produces a working plugin.

**On the GitHub release.** The `v1.0.1` release remains published as a normal
release, and I have not altered it — editing a published release is the
repository owner's call, not something to do inside a review response. If the
intent was for it to be a pre-release, that flag needs setting by hand.

---

## What is still not covered by a test

Carried forward from the previous response, minus what is now covered:

- Concurrent budget checks across processes. The lock is exercised; mutual
  exclusion between two workers is not provokable in a single-process suite.
- Key storage and reading under unusable salts. See FR-09.
- An Action Scheduler dispatch test from scheduled action through completion.
- Settings save behaviour and the connection-test buttons.
- Recorded first-party provider contract fixtures, and a live model-list smoke
  test. Both need funded keys, and FR-03 would have been settled in one call.
- MariaDB in CI, and a smoke test that activates the built zip.

---

## Where this leaves the release

Of the eleven follow-up findings, nine are confirmed and two are rejected on
evidence. Eight of the nine are fixed here with tests. The ninth, FR-05, is the
pipeline rewrite, and it remains the one thing standing between this plugin and
an honest claim of brief completeness.

1.0.2 is a normal patch release, described as one, with the three unmet brief
requirements named in the README rather than inferred from a passing test count.
The recommendation for unattended publishing is unchanged and is in the README:
keep review mode on, and set a spending limit at the provider, because no
client-side cap is a hard ceiling.

---
---

# Response to the Codex PR review of 1.0.2

**Responding to:** the automated Codex review on
[PR #1](https://github.com/johnjanney/autoscribe/pull/1), against `d348dd0`
**Response date:** 19 August 2026
**Release under response:** 1.0.2 → 1.0.3

---

## FR-12 — Confirmed. Fixed.

> **P2 — Delay binding the inherited draft until assembly.** When a retry adopts
> a draft but then hits a transient provider error during topic or body
> generation, this early `record_post()` makes the failed retry row point to that
> draft without updating the draft's `_autoscribe_run_id` metadata. The next
> retry selects this row as its immediate predecessor, but `Run::adoptable_draft()`
> rejects the draft because its metadata still names the original attempt; a
> later successful attempt therefore creates a second draft.

Correct in every particular, and it is a regression introduced by the 1.0.2 fix
for FR-02. Version 1.0.1's ownership check asked only whether the run link was
non-empty, so it did not care which run the link named. Tightening it to name the
row being adopted from is what made the missing meta write matter.

The path, verified:

1. Attempt 1 assembles a draft and fails on the image. `Step_Assemble_Post` has
   written `_autoscribe_run_id = run1` on the post, and `run1.post_id` is the
   draft.
2. Attempt 2 adopts. `record_post()` sets `run2.post_id` to the draft. The
   proposal or body call then fails, so assembly never runs and nothing updates
   the post's meta, which still reads `run1`.
3. Attempt 3 selects `run2` as its predecessor, and every other condition holds —
   failed, attempt 2, still a draft, untouched. The ownership check compares the
   post's `run1` against `run2.id` and refuses.
4. Attempt 3 assembles a second draft.

`test_adoption_survives_a_retry_that_fails_before_assembly()` reproduces it: it
failed with `null is identical to 5` before the fix and passes after.

### On the two remedies offered

The comment offers two. **The parenthetical one — transfer the post metadata at
adoption — is the one taken**, because the primary suggestion does not hold on
its own.

Delaying the binding until assembly means a retry that fails before assembly
leaves its run row with no post at all. The next attempt then selects that row as
its predecessor, finds `post_id` empty, and has nothing to adopt — so it creates
a second draft by a different route. The chain needs the failed row to keep
pointing at the draft it adopted; what was missing was the other half of the
handover, not the half that was there.

`Run::adopt_post()` now does both together: it records the post on the run and
moves the post's run link to that run in one operation. The invariant the check
tests — the post's run link names the run that currently owns it — holds from the
moment of adoption rather than only after a successful assembly. Splitting those
two writes is what let them drift apart, so they no longer have separate callers.

Two details worth stating, both checked:

- **The human-edit guard is unaffected.** `update_post_meta()` does not touch
  `post_modified`, so a draft adopted but never written to still compares as
  untouched against the failed run's `finished_at`.
- **The audit link stays meaningful.** Section 10 requires `_autoscribe_run_id`
  on every generated post so a post can be traced to its run. Naming the run that
  currently owns the draft is at least as truthful as naming an abandoned earlier
  attempt, and `Step_Assemble_Post` still overwrites it on a successful write.

The new test asserts both halves of the invariant — that the failed retry row
still owns the draft, and that the next attempt adopts it — so neither remedy can
be applied by halves in future without the suite noticing.

---

## FR-13 — Confirmed. Fixed.

> **P2 — Abort adoption when the ownership write fails.** When the `wp_postmeta`
> write fails — for example because of a database error or a metadata filter —
> `update_post_meta()` returns `false`, but this method continues after the run
> row has already been bound to the draft. If the retry then fails before
> assembly, the run row names the current attempt while the post metadata still
> names the earlier one, so the next retry rejects the draft and can create the
> same duplicate this change is intended to prevent.

Correct, and the sting is that it is the *same* defect as FR-12 re-entering
through a failure path. The 1.0.3 fix made adoption depend on two writes and
checked neither, so any failure of the second one landed back in the state the
fix existed to prevent. A fix that only works when nothing goes wrong is not much
of a fix.

Two things were wrong, not one:

1. **Neither result was checked.**
2. **The order was backwards.** The run row was bound first, so the write that
   can fail for reasons outside the plugin's control was the one that ran second
   — which is the arrangement where a failure leaves the most damage behind.

**Fix.** Adoption is now all or nothing, and the order is reversed so the cheaper
failure changes nothing at all:

- The ownership write goes first. If it does not take, the run row has not been
  touched, so the draft still belongs to whoever owned it and there is nothing to
  undo.
- The run row is bound only after that, and if *that* write fails the ownership
  is put back where it was and the in-memory post ID is cleared.
- `adopt_post()` returns whether the draft now belongs to this run.

**One detail the finding does not mention, and it matters here.**
`update_post_meta()` returns `false` on failure *and* when the value being stored
already equals the stored one. A plain `if ( ! update_post_meta( ... ) )` would
therefore be wrong in a way that is easy to miss and hard to reproduce. The write
is verified by reading the value back and comparing it to this run's ID, which is
correct for both cases. In today's flow the meta always names an earlier run so
the equal-value case cannot arise — but that is a property of the current call
site, not of the method, and it is not one to depend on.

**What a failed adoption now does.** `Generator` treats it as no adoption rather
than pressing on. That matters because the adopted post ID is also what excludes
the draft from duplicate detection: carrying on would hide the old draft from the
dedupe check and then write a second one beside it.

> **Correction, 19 August 2026.** The paragraph above originally went on to claim
> that clearing the inherited ID would leave the run to "stand down as a
> duplicate". It would not, and FR-14 below sets out why. The run is now stopped
> outright at the adoption site.

Two tests cover it: a blocked ownership write adopts nothing, changes nothing,
and says so; and a successful adoption reports success and moves the link.


---

## FR-14 — Confirmed. Fixed. My own mitigation was wrong.

> **P2 — Stop the run when draft adoption fails.** When the ownership write is
> refused, clearing `$inherited` does not make this run stand down: execution
> continues into `Step_Propose_Topic::run()`. Because passing `0` causes the
> abandoned draft to be included in `recent_topics()`, the provider is encouraged
> to propose a different, non-colliding topic; if it does, the pipeline generates
> and assembles a second draft while leaving the original behind. Return a
> skipped/error result immediately on adoption failure rather than relying on
> duplicate detection to abort.

Correct, and it is a correction to the previous response rather than to the code
alone. The 1.0.4 fix made adoption atomic — that part holds — but the claim about
what happens *after* a failed adoption was wrong, and it was wrong in the
direction that matters.

The reasoning I gave was: leave the abandoned draft visible to duplicate
detection and the run will collide with it and skip. That has the mechanism
backwards. The already-covered list is injected into the proposal call for the
express purpose of steering the model away from repeats — the instruction it
carries is literally "propose something different". So on a failed adoption the
likely path is not a collision at all:

1. The retry proposes a *new* topic, as instructed.
2. `collision_reason()` finds nothing, because the topic genuinely is new.
3. The body call is made and paid for.
4. Assembly writes a second draft, and the orphaned one stays where it is.

The pile-up adoption exists to prevent, reached by a longer route and with a
provider bill attached. Relying on dedupe to abort only worked in the case where
the model repeated itself, which is the single thing that list is there to
prevent.

Writing the test made this plain. My first attempt asserted against a mock that
returned the same topic on every call, and it "passed" as a duplicate skip — for
the wrong reason. Making the retry propose a different topic, which is what a
real provider reading that prompt would do, showed the run running all the way to
the image step: past proposal, past the body call, past assembly.

**Fix.** The run ends at the adoption site with `autoscribe_adoption_failed`,
before the first paid call, and the run row records why. No provider is
contacted, no second draft is written, and the original draft keeps its owner.
`autoscribe_adoption_failed` is not on the transient allowlist, so it is not
retried — a refused metadata write is not something a second attempt five minutes
later is likely to get past, and each attempt would cost another proposal call to
find that out.

The test now asserts the whole outcome rather than just the error: one post on
the site, the original draft still owned by the run that created it, and a failed
run row with no post bound to it.

---

## FR-15 — Confirmed, and wider than reported. Fixed.

> **P2 — Regenerate the translation template for the new error.** This release
> adds a new user-facing gettext string, but `languages/autoscribe.pot` is
> unchanged and contains no corresponding entry. Consequently, translators
> working from the distributed template cannot translate the adoption-failure
> notice, so localized installations will display this message in English;
> regenerate the POT as required by the release checklist.

Correct, and the one string named is a small part of it. The template still
carried the string set from **1.0.0**, four releases back. Regenerating it after
adding a string is step four of the release checklist in `CONTRIBUTING.md`, and
it was missed on 1.0.1, 1.0.2, 1.0.3, 1.0.4, and again here.

Regenerating with the documented command added **29 strings** and removed 2:

| What was missing | Since |
|---|---|
| Both notification emails — the review-draft one and the retries-exhausted one | 1.0.1 |
| The whole health panel: "Queue last processed", "Security salts", and their descriptions | 1.0.1 |
| "Test connection", and the default-model help text beside it | 1.0.1 |
| Every image validation error — byte limit, pixel limit, file type, unreadable data | 1.0.1 |
| Both grounding refusals | 1.0.1 |
| The run-not-recorded error | 1.0.1 |
| All four untrusted-data block strings | 1.0.2 |
| Both weak-salt messages, and the third added for reading | 1.0.2 |
| The reservation-failure notice | 1.0.2 |
| The image URL status error | 1.0.2 |
| The adoption-failure notice | 1.0.5 |

The two removals are the strings replaced by fenced blocks in 1.0.2 — the
template was still asking translators to translate text the plugin no longer
emits.

So a localised site displayed all 29 in English, and the translator had no way to
fix it, because the string was not in the file they work from. That is a worse
outcome than the finding describes, and it had been true for four releases.

**Fix.** The template is regenerated with the command in `CONTRIBUTING.md`, and
`TranslationTemplateTest` now fails when a translatable string in the code is
absent from it. The checklist is no longer the only thing standing between a new
string and a site that cannot translate it — the same reasoning as `VersionTest`,
which exists because the header and the version constant had the same capacity to
drift apart quietly.

The test deliberately does **not** regenerate the template and compare, because
the header carries a creation timestamp and the plugin version, so that test
would fail on every release for reasons that have nothing to do with coverage. It
asserts the property a translator actually depends on: if the plugin can say it,
the template contains it. Restoring the stale 1.0.0 template makes it fail and
name all 29 strings with the file and line each came from.

**Why this is in this pull request rather than its own.** The string Codex named
was introduced by this pull request, so the omission is this pull request's to
fix. The other 28 came with it because there is no honest way to regenerate the
template for one string only.

---
---

# Response to the third Codex review

**Responding to:** the fresh-review section of [CODEX-REVIEW.md](CODEX-REVIEW.md),
dated 19 August 2026 against `076b6dd` (tag `v1.1.0`)
**Response date:** 19 August 2026
**Release under response:** 1.1.0 → 1.1.1

---

## Summary

Ten findings. **Nine confirmed, one rejected on evidence.** All nine are fixed.

| Finding | Verdict | Status |
|---|---|---|
| CR-01 Paid usage lost on a write failure | Confirmed | Fixed |
| CR-02 Not one provider call per request | Confirmed as documentation drift | Docs corrected; not split |
| CR-03 Globals outside the run's configuration | Confirmed ×2 | Fixed |
| CR-04 Image reported attached when it is not | Confirmed | Fixed |
| CR-05 Terminal writes unchecked | Confirmed | Fixed |
| CR-06 No atomic step claim | Confirmed | Fixed — one half rejected, see below |
| CR-07 `gemini-3.7-flash` not in the catalog | **Rejected** — listed as New Stable today | No change |
| CR-08 Sweep makes ~2,000 queue queries | Confirmed | Fixed |
| CR-09 Monthly warning not exactly once | Confirmed | Fixed |
| CR-10 Documentation contradictions | Confirmed | Fixed |

**Verification:** PHPCS passes with zero errors and zero warnings. PHPUnit passes
291 tests and 1,083 assertions, up from 286 and 1,066. No test contacts a live
provider.

---

## The pattern worth naming before the findings

CR-01 and CR-05 are the same defect as five the pipeline split already fixed: a
write whose result nobody consumed. I ran an audit for exactly that during the
split and reported it clean.

The audit was wrong because of how I scoped it. I grepped for callers of methods
that **already returned** `bool` — which found the call sites I had recently
changed and, by construction, none of the methods still returning `void`.
`record_text_usage()`, `record_image()`, `succeed()`, `fail()`, and `skip()` were
never in the search. An audit shaped around the previous symptom finds the
previous symptom.

The right question was "which writes can fail without anyone noticing", and it
should have been asked of every write, not of the ones I had just touched.

---

## CR-01 — Confirmed. Fixed.

A provider that answers has charged for the answer. Whether the run log accepts
the counters afterwards is a separate question, and the two were not connected:
`record_text_usage()` and `record_image()` returned `void`, so a refused write
left the step to finish, and the next queued action loaded a fresh run and read
the row. The charge was real; the record of it was gone, and with it the
month-to-date total the section 7.4 cap reads.

Both writes now report. A step that cannot store what it just spent returns
`autoscribe_usage_not_recorded` and the run stops.

**Why stopping books the charge rather than losing it.** The counters are held in
memory whether or not the write lands, and the object that made the call is the
object that settles the run when the failure ends it. So the cost is measured
from figures the row never accepted — which is the point. Carrying on is what
loses them.

## CR-02 — Confirmed as drift. Documentation corrected; the calls are not split.

The claim is false and I should not have made it. The topic step asks again when
its first proposal collides, and the article step makes one repair call when a
response does not validate. A single step can make two provider calls at up to
120 seconds each.

`INSTRUCTIONS.md` already said so — I corrected it in the previous round — while
the changelog and the scope document still claimed the bound. That is the worst
version of the error: the accurate text existed and contradicted the promotional
text.

**I have corrected the claim rather than split the calls.** The reasoning:

- The re-ask and the repair are *within* one logical step. Splitting them adds
  two pipeline positions, two payload keys, and two more places for a run to
  stall, to buy a bound that is still not a guarantee — a single 120-second call
  already exceeds a 30-second limit on its own.
- What the split actually bought is not a request-size bound but a **blast
  radius**: a killed request costs a step rather than an article, and the sweeper
  restarts it. That is true, useful, and now what the documentation says.

Splitting them remains reasonable future work. It is not a correctness fix, and
describing the current behaviour honestly is.

## CR-03 — Confirmed, both halves. Fixed.

The fingerprint covered prompt fields and nothing else, so two global settings
could change under an open run.

**The site default model.** A prompt with a blank model field resolves through it
at every step, so changing it mid-run swaps the model the budget was checked for.
The defaults for the prompt's providers are now part of the fingerprint.

**Force review.** This one needed a different answer rather than the same one.
Failing a run because the safety catch was *tightened* would be perverse, and
failing it because the catch was loosened is weaker than simply not honouring the
loosening. So an open run keeps the stricter of the setting it started under and
the setting at the end: turning review on mid-run takes effect immediately,
turning it off never applies to an article already being written.

## CR-04 — Confirmed. Fixed.

`set_post_thumbnail()` returns `false` when it fails **and** when the post already
carries that thumbnail, so its return value cannot distinguish a refusal from a
no-op — which is presumably why it was ignored. The post is now asked what its
thumbnail actually is.

That matters most for `required` mode: a run reporting success without a featured
image has published precisely what that mode exists to prevent.

Attachment metadata is verified the same way, and for the same reason —
`wp_update_attachment_metadata()` has the identical false-means-two-things
problem. An attachment whose metadata could not be built is removed rather than
left half-attached.

## CR-05 — Confirmed. Fixed.

Every ending is now one conditional update that only an open run accepts, and it
reports whether this call is the one that closed the run.

The conditional part is worth more than the reporting. It makes closing a
*transition* rather than a write, so a duplicate action and a stall sweep that
already gave up both lose the race instead of closing a run twice — and closing
twice means a second review email, a second re-arm, and a settled cost
overwritten by a later one.

Nothing is announced until the transition succeeds. Publishing was never the
risky part; announcing off a transition that did not happen was.

## CR-06 — Confirmed. Fixed, with one half of the suggested remedy rejected.

The per-step guards are reads, and a read cannot exclude anyone: two workers can
both find no stored article and both buy one. `Run::claim_step()` is now a
compare-and-swap on the run's position, taken before anything is spent. The
loser stands down having paid nothing.

**Unique scheduling is not usable here, and this was tested rather than
reasoned.** Action Scheduler's uniqueness counts actions that are pending *or in
progress*, and every step arms its successor from inside itself — so the action
doing the arming is itself the duplicate. Setting `unique` stops the chain dead
after its first step, which is what happened when I tried it.

Uniqueness would have prevented a second action row. The claim prevents a second
worker spending, which is the property that matters.

**Two defects in the claim itself were caught before release**, both raised on the
pull request that added it, and both worth recording because they are the cost of
introducing a lock into a system that did not have one:

- *A lost claim looked like a finished sequence.* Both returned the same value, so
  the losing worker did not stand down — it finished the run, closing a run with
  no article early on, or publishing before the winner had attached the image. A
  lost claim is now its own outcome.
- *A refused featured-image write became a fatal error.* The verification added
  for CR-04 built its error and then let the next line overwrite it with the
  attachment ID, so every image mode crashed on a refused thumbnail rather than
  handling it.
- *Two guards cancelled each other out.* Putting force review into the abort
  fingerprint (CR-03) meant any change to it stopped the run, so the monotonic
  rule added for the same finding could never be reached — and tightening a
  safety catch would have killed the run it protects. The fingerprint covers the
  settings where continuing under a changed value is wrong; force review is
  governed by the monotonic rule alone.
- *Losing the close race was reported as a failure.* The winner had already sent
  the review mail and armed the next occurrence; the loser's error then had the
  handler send a failure notice and re-arm on top. The duplicate announcement the
  CR-05 check exists to prevent, arriving by the other door.
- *A concurrent sweep could free a live claim.* The release re-read the column,
  and a released-then-retaken claim produced an identical marker, so a second
  sweeper acting on a stale view freed a live worker's claim. This took two
  attempts. The first added a token and a re-check before releasing, which was
  the wrong shape: check-then-read leaves a window between the two, and it could
  not be tested at all. The release now names the claim observed when the run was
  judged idle, which makes the check and the release one conditional update — and
  makes the interleaving reproducible in a single process, which is how the
  second attempt is verified and the first could not be.
- *A run at its restart limit could be closed while a worker was on it.* The
  limit was evaluated against a candidate scan that can be many pages old. This
  took two attempts as well. Re-asking the queue before giving up narrowed the
  window without closing it — another sweep can record the final restart, this
  one can see the new count and find nothing queued, and the restart can be armed
  and claimed before this one writes. The terminal write is now tied to the
  position the sweep observed rather than to a separate read taken beside it, so
  a worker holding the step wins and one that has not claimed yet finds the run
  closed and stands down without spending.

  Five corrective rounds on one guard is the headline number of this review, and
  the reason is consistent: every version of it that was a *sequence* of checks
  had a gap between two of its steps, and each review found the next one. The
  versions that hold are the ones expressed as a single conditional update —
  claim, release, and now the terminal close. That is the lesson worth keeping
  from this cycle rather than any individual defect.
- *A refused release spent one of the run's restarts.* The restart it armed was
  guaranteed to lose an unchanged claim, so two failures gave up on a recoverable
  run.
- *An abandoned claim could never be taken again.* A worker killed mid-step leaves
  the marker behind, and the next worker reads the position with the marker
  stripped — so it asked to claim a value the column no longer held, and failed
  every time. A run interrupted at any point after claiming could never resume and
  was given up on instead: the guard defeating the sweeper that exists to recover
  from exactly that. The sweeper now releases an abandoned claim before
  restarting, which it can do safely because it has already established that
  nothing is queued or running for the run.

## CR-07 — Rejected. `gemini-3.7-flash` is listed as a stable model today.

This is the second review to raise it and the second time the cited page says the
opposite. Retrieved 19 August 2026 from
[Google's Gemini model catalog](https://ai.google.dev/gemini-api/docs/models) —
the same URL the finding cites:

- `gemini-3.7-flash` — **"New Stable"**, described as the latest and most capable
  Flash model.
- `gemini-3.6-flash` — "Stable", described as the *previous-generation* Flash
  model.

So the finding's premise, that the catalog lists 3.6 but not 3.7, does not hold
against the catalog. Putting 3.6 first would make the plugin default to a model
Google itself labels previous-generation.

Two things in the finding stand regardless, and are already how the plugin works:
the model field is user-editable with a connection test, and `Model_Resolver`
puts the adapter's suggestion last, behind the prompt field and the site default.
Section 2.2 of the brief requires exactly that, because this is a dependency that
moves faster than releases do.

No live model-list call has been made against a funded key. This conclusion rests
on first-party documentation, as the finding's does.

## CR-08 — Confirmed. Fixed.

One queue query per candidate run, up to two thousand per sweep, against the
queue the sweep exists to watch. Now one query per page, matched against the
page's run IDs.

Action Scheduler's store exposes no bulk query for this, so the wrapper reads its
table directly and falls back to the per-run API when the table is not the one it
expects — a site using the legacy post-based store, or a substituted store, gets
correct answers slowly rather than wrong answers quickly.

## CR-09 — Confirmed. Fixed.

Read-then-update is not a claim. Two runs finishing together could both see the
old month and both send the one email section 7.4 allows.

The month is now claimed with `add_option()`, because only one caller can create
a row that does not exist. The option name carries the month, so the insert *is*
the claim and the loser is told so.

## CR-10 — Confirmed. Fixed.

Three separate errors, all mine:

- **The README version table said 1.0.0.** Wrong since 1.0.1, through six
  releases. I have been updating the status paragraph and never looked at the
  table above it.
- **"Run now and Preview both answer in the request that asked" is false.** Run
  now queues and always has; `DECISIONS.md` D-19 explains why, and I contradicted
  my own decision record while writing release notes.
- **The one-provider-call claim** — see CR-02.

---

## On the findings not fixed

The review's remediation list also asks for tests that dispatch the bundled
Action Scheduler queue end to end, MariaDB in CI, and a built-archive activation
smoke test. Those remain open and are recorded in the README's known limitations.
They are infrastructure work rather than defects, and none of them is a claim the
documentation currently makes.

---

## Where this leaves the release

Nine of ten findings fixed, one rejected with the evidence above. Two of the nine
— the lost usage and the unchecked terminal writes — were financial-control
defects that predate the pipeline split and that my own audit should have caught.

1.1.1 is a patch release. The known deviations from the brief are unchanged: Run
now does not stream, the next-run readout is not live, the duplicate threshold is
78 rather than 82, and there is still no screenshot.

---

# Follow-up after 1.1.2 — the empty stored step

**Confirmed. Fixed.**

The sixth corrective round on the same guard, and the first where the fault was
introduced by the previous round rather than merely survived by it.

1.1.2 tied the sweeper's terminal write to the position it observed. `close_at()`
passed that position to `wpdb::update()`, converting an observed empty string to
NULL on the reasoning that an un-advanced run stores NULL. That is true of a run
that has never advanced. It is not true of a run the sweeper has already
recovered: `release_claim()` writes back `completed_step()`, which at the first
step is the empty string, not NULL. So the column held `''` while the WHERE asked
for `IS NULL`, and the two never met.

The consequence is worse than a failed write. `give_up()` refuses to close, the
run stays `running`, and `Budget_Guard` goes on counting its reservation against
the monthly cap. Nothing ever releases it, because the only thing that would is
the close that cannot match. A few stalled runs are enough to make the cap deny
every subsequent run.

The fix is the comparison `claim_step()` already uses — `COALESCE( step, '' )` —
which required writing the statement out, because `wpdb::update()` cannot put a
function in its WHERE. One static prepared statement, per D-26.

**What this round says about the previous five.** Each earlier round narrowed a
window; this one was a plain disagreement between two methods about how the same
value is spelled. `claim_step()` had the answer already — the COALESCE was there,
written for exactly this reason — and the new code beside it re-derived a
different one instead of reusing it. The lesson is narrower than the earlier
ones and worth stating separately: when a column has more than one representation
of the same state, the reconciliation belongs in one place that every reader
shares, not repeated per query from memory.

The regression test releases a first-step claim and then asks the sweeper to give
up, and fails against a strict `step = ''` match as well as against the `IS NULL`
match it replaced.

---
---

# Response to the fourth Codex review

**Responding to:** the verification-review section of
[CODEX-REVIEW.md](CODEX-REVIEW.md), dated 19 August 2026 against `88aefb3`
(tag `v1.1.3`)
**Response date:** 19 August 2026
**Release under response:** 1.1.3 → 1.2.0

---

## Summary

Eight findings. **Seven confirmed, one rejected on evidence retrieved today.**
All seven are fixed.

| Finding | Verdict | Status |
|---|---|---|
| VR-01 Failed terminal writes still trigger downstream actions | Confirmed | Fixed |
| VR-02 Concurrent sweepers can overwrite run payload state | Confirmed | Fixed |
| VR-03 Fallback mode publishes when the fallback cannot attach | Confirmed | Fixed |
| VR-04 First Google suggestion absent from the catalog | **Rejected** — both cited pages list it today | Docs strengthened |
| VR-05 Run snapshot excludes resolved models and pricing | Confirmed | Fixed |
| VR-06 Assembly ignores audit, taxonomy, and SEO writes | Confirmed | Fixed |
| VR-07 Finalisation has no claim | Confirmed | Fixed |
| VR-08 Documentation contradictions | Confirmed | Fixed |

**Verification:** PHPCS passes with zero errors and zero warnings across 120
files. PHPUnit passes 327 tests and 1,228 assertions, up from 300 and 1,118. No
test contacts a live provider; the bootstrap tripwire still fails any request
that reaches the network.

---

## The pattern, for the third round running

The previous response said the right question was "which writes can fail without
anyone noticing", and that it should be asked of every write rather than of the
ones just touched. VR-01, VR-05, and VR-06 are that question asked again and
answered better, and the honest summary of the last round is that I fixed the
writes and stopped at the *results*.

Making `fail()`, `skip()`, and `succeed()` return a Boolean was half a fix.
`false` meant two opposite things — somebody else closed this run, and the
database refused the write — and every caller that inspected it collapsed them
back into one. A lost race must be silent; a refused write must be loud and must
leave the run alone. Answering both with the same value guaranteed that whichever
reading a caller chose, it was wrong half the time.

`Close_Result` names the three outcomes, and every ending in the queue driver
carries its answer to `conclude()`, which is the single place that decides
whether anything is retried, mailed, or armed.

---

## VR-01 — Confirmed. Fixed.

The sequence the reviewer describes is real and the fix is the three-state close.

**What changes.** `Run::fail()`, `skip()`, and `succeed()` return `Close_Result`:
`Closed`, `Already_Closed`, or `Write_Failed`. A caller that closes a run attaches
the answer to the error it is returning, and `Queued_Run_Handler::conclude()`
reads it first:

- `Closed` — proceed exactly as before.
- `Already_Closed` — stand down. Whoever won has already reported it.
- `Write_Failed` — report an operational fault, arm nothing, retry nothing, and
  leave the run open for the stall sweep to settle.

That last branch is the one the release note is about: the run is deliberately
left recoverable, so nothing else would say anything at all. It sends one alert
per hour at most, under its own subject rather than the run-failure one, because
the thing to act on is the database and not the prompt.

**The accounting half.** A run that stops with a charge nobody recorded must not
be settled from its counters, because they are known to be short. Two cases keep
the reservation as a floor instead: a failure whose code is
`autoscribe_usage_not_recorded`, and a stalled run the sweeper found holding a
claim — which means a worker was inside a paid step when it died. A run that
stalled *between* steps has nothing outstanding and still releases its
reservation in full, because holding it would refill the cap with money nobody
spent, which is the failure the sweeper exists to prevent.

Settlement also re-reads the counters rather than trusting what the object loaded
earlier, and keeps its own in-memory figures as a floor under them. Both can be
right and neither can be trusted alone: the row carries what other actions
recorded, and the object carries calls a provider has already answered whose
write the row may have refused.

**Tests.** `Terminal_StateTest` drives the queued endings with the writes
refused: the usage write and the terminal write failing together, a refused close
not arming the next occurrence, and the alert being sent once however often the
fault repeats. `Concurrent_StateTest` covers both settlement rules.

## VR-02 — Confirmed. Fixed.

Two changes, because the finding names two mechanisms.

**The counter leaves the payload document.** `sweeps` is a column now. Keeping a
concurrency counter inside the JSON document that every step reads whole and
writes whole was the defect; no amount of care at the call site fixes a counter
that shares storage with the state it is supposed to be protecting. The
migration runs through the existing schema-version check, and `sweeps()` still
reads the payload value as a floor so a run opened by 1.1.x and still in flight
across the upgrade does not get a fresh set of restarts.

**The count is the sweeper's claim.** `record_sweep()` takes the count the caller
judged the run on and increments conditionally, so of two sweeps holding the same
view exactly one proceeds. This covers the gap the claim release does not: a
worker that died between finishing a step and arming the next one leaves no claim
to release, so the release could not exclude anybody there.

**Payload and position writes are conditional on the claim.** A worker slow
enough to be judged gone is not necessarily gone. `merge_payload()` and
`record_step()` now require that the step column still holds this worker's claim
token, so a swept-and-replaced worker cannot write over its replacement or free
its replacement's claim. `Pipeline::advance()` reads a lost claim as
`CLAIM_LOST` rather than as an error, so such a worker stands down instead of
closing a run that now belongs to somebody else.

One subtlety worth recording: a conditional payload write that changes nothing
reports zero affected rows, which is indistinguishable from a lost claim without
a second look. Only that ambiguous case pays for the extra read, because reading
it as failure would stop runs for re-recording state they had already recorded.

**On the reviewer's note that these tests run in one process.** They do, and that
is still a real limitation, recorded in the README. What the tests do exercise is
the ordering the guards depend on — a stale view, a conditional write, and which
writer wins — because every guard here is a single SQL statement whose atomicity
is the database's to provide.

## VR-03 — Confirmed. Fixed.

Fallback mode is a promise that there is always a picture, and it was falling
through to no picture in exactly the case it was chosen for.

At run time, a fallback that cannot be attached — ID zero, a deleted attachment,
or a thumbnail write WordPress refuses — now returns
`autoscribe_fallback_image_missing`, which fails the run and leaves the draft for
a person. That is the same ending required mode has, because at that point they
are the same situation.

At save time, the prompt editor refuses to store fallback mode unless the ID
names an image in the media library, and stores `required` instead with a notice
saying so. The reasoning is `enforce_grounding_capability()`'s: a disabled
control is a courtesy, and the REST API, WP-CLI, and an import all reach the save
path without seeing it. `required` rather than `optional` because it is the
strictest honest reading of what the site owner asked for — never publish without
an image — and because silently widening a publication policy is the class of
change this plugin should never make on its own.

## VR-04 — Rejected on evidence. Documentation strengthened.

The finding says Google's catalog does not list `gemini-3.7-flash` and marks it
**Not found in documents**. I retrieved both pages the finding cites, today,
19 August 2026:

- <https://ai.google.dev/gemini-api/docs/models> lists `gemini-3.7-flash` and
  describes it as "Our latest and most capable Flash model, built for complex
  coding, agentic workflows, and reliable multi-step execution."
- <https://ai.google.dev/gemini-api/docs/latest-model> says "Change your target
  model string to `gemini-3.7-flash`" and records it as generally available and
  ready for production use.

`gemini-3.6-flash` is listed too, and remains the second suggestion so a site
that wants to pin it can. Demoting the default to a model Google's own migration
guide tells clients to move *off* would make the plugin worse on the evidence
available to either of us.

**What the finding is right about underneath the fact.** A hard-coded first
suggestion is the plugin's real default however loudly section 2.2 says model IDs
are configuration, and a catalog is not something to remember. Two things
changed:

- The adapter's docblock records the retrieval date and both URLs, and says to
  re-check them on the day the list is next edited.
- `CONTRIBUTING.md` adds a release step: open each provider's catalog, confirm the
  first suggestion is still generally available, and update the recorded date
  whether or not the list changed.

`GoogleTest` pins the first suggestion so that changing it is a deliberate act
next to that recorded date. It is deliberately offline: a test that calls a
provider fails when a network does, needs a funded key in CI, and puts the
suite's correctness in somebody else's hands.

## VR-05 — Confirmed. Fixed.

The fingerprint catches an edit to the prompt or to a site default and cannot
catch what changes underneath both. A blank model field resolves through the
adapter's suggestion list, which is code, so an upgrade can change the model a
run in flight is using without changing anything the fingerprint compares — one
article proposed by one model and written by another. Editing the pricing table
is worse, because it is a supported act with an immediate effect on what an open
reservation releases.

Opening a run now records the resolved text model, the resolved image model, both
provider slugs, and the rate rows for those models plus the wildcard. Every paid
step reads the models off the run, the budget check prices the estimate from the
recorded table, and settlement uses the same table. An edit applies to the next
run, which is what an editor is asking for anyway.

The snapshot always carries the wildcard rate, because that is what an unlisted
model is priced at, and a recorded table without one would price a model at
nothing and defeat the cap. A run with no snapshot — opened by 1.1.x, still in
flight — falls back to resolving as before, for the same reason a missing
fingerprint is not treated as an edit.

## VR-06 — Confirmed. Fixed.

Every write in assembly is now inspected, and the policy is uniform: if the post
cannot carry what the run was asked to give it, the post stays a draft and the
run fails.

- The run link and topic key are read back, not inferred from
  `update_post_meta()`, which answers false both for a refused write and for a
  value that already matched. `Meta_Writer` does that in one place for assembly
  and all four SEO adapters.
- `SEO_Adapter_Interface::apply()` returns `bool`.
- `Taxonomy_Applier::apply()` returns `true|WP_Error`.
- `Run::record_article()` returns `bool`.

The run row is bound to the post immediately after the meta write and before the
SEO and taxonomy writes, so a failure in those still leaves the draft adoptable
by the next attempt rather than orphaned.

**Why terminal rather than a warning, for all of them.** A warning needs somewhere
to go, and the only places available are the run row's error column — which is
the failure path — and an email nobody asked for. A draft plus a failure notice
is recoverable and legible; a published post silently missing its categories or
its SEO metadata is neither. The reviewer asked which failures are terminal:
these all are, and the README records it.

One note on what could not be tested the obvious way: WordPress ignores the
result of its own term-relationship insert, so a refused relationship cannot be
made to fail. The test drives the failure WordPress does report — a term it
cannot create — which is the same path through our code.

## VR-07 — Confirmed. Fixed.

Finalisation claims the run's position before it does anything, exactly as the
five steps do. Two actions could otherwise both transition the post and both
write a settled cost before one lost the close race, so nothing was charged twice
but every plugin listening for a publish ran twice, and the loser's cost write
could land last. A second finaliser now finds the position claimed and returns
the close-race code, which the queue driver already knows to swallow.

## VR-08 — Confirmed. Fixed.

- The installation section no longer names a versioned zip. It points at the
  latest release, which is the thing that stays true.
- `DECISIONS.md` D-09b no longer claims at most one provider call per queued
  request. It records the real bound, why the claim was wrong, and that the
  README and the pipeline document had already been corrected — because three
  documents describing one mechanism is how a bound nobody can rely on survives.
- The README's "two requirements" is now the full list of six deviations.
- The grounding residual-risk warning is in the README, where
  `Untrusted_Block`'s comment says it is, with a link to the longer version in
  `INSTRUCTIONS.md`. The comment now names both.

---

## What is still not covered

Unchanged from the last round, and still recorded in the README rather than
implied: no test drives Action Scheduler's own dispatch; the concurrency tests
run interleavings in one process rather than across two connections; CI runs
against MySQL only; and no test calls a live provider. The last is deliberate and
will stay that way.

---
---

# Response to the fifth Codex review

**Responding to:** the fresh-verification section of
[CODEX-REVIEW.md](CODEX-REVIEW.md), dated 19 August 2026 against `cedb422`
(tag `v1.2.0`)
**Response date:** 19 August 2026
**Release under response:** 1.2.0 → 1.3.0

---

## Summary

Six findings. **All six confirmed, all six fixed.** No claim in this review turned
out to be wrong, and the VR-04 retraction is accepted with thanks — that is the
second time a first-party page has settled a disagreement that neither of us
could settle by argument.

| Finding | Verdict | Status |
|---|---|---|
| F120-01 Recovered paid step settles below cost | Confirmed | Fixed |
| F120-02 The claim does not fence all state changes | Confirmed | Fixed, with a stated residual |
| F120-03 Taxonomy success not verified by reading back | Confirmed | Fixed |
| F120-04 Preview outside the snapshot and recovery contract | Confirmed | Fixed |
| F120-05 Each sweeper page reads every active action | Confirmed | Fixed |
| F120-06 Save-time guard misses programmatic paths | Confirmed | Fixed, and the response corrected |

**Verification:** PHPCS passes with zero errors and zero warnings across 125
files. PHPUnit passes 350 tests and 1,441 assertions, up from 327 and 1,228. No
test contacts a live provider.

---

## The pattern, again, and what is different about it this time

Both high findings are the same shape as the last round's, one level down: a
guard that is correct where it is applied and is not applied everywhere it is
needed. The cost floor covered the run that is given up on and not the one that
is restarted. The claim fenced two writes while its own comments described it as
ownership of the whole step.

The useful lesson is about how the fixes were scoped rather than about the
defects. Both times I fixed the case in the finding and stopped at the edge of
the example, and both times the review came back with the neighbouring case. So
this round each fix was taken to the boundary of its abstraction and the
boundary is now stated in the code: `Run::update()` fences everything, the two
exemptions say why they are exempt, and the two WordPress writes that cannot be
fenced say so in the comment rather than leaving it to be discovered.

---

## F120-01 — Confirmed. Fixed.

The reservation floor existed only where the sweeper gave up. A restart that
succeeded settled from the replacement's usage, and the interrupted call — which
a provider had already been paid for — was gone.

`release_claim()` now raises a `cost_floor` column to whatever the run has
reserved, in the same conditional statement that releases the claim. Every
settlement afterwards is held at or above it: `measured_cents()` applies it, so
success, failure, and skip all inherit it, including the ending that measures no
usage at all — a run interrupted inside its *first* paid call has nothing to
measure and may still have been charged.

**Why the reservation rather than an allowance for the interrupted step.** The
review offered both. The reservation is the figure the run was already checked
against the cap for, so holding it costs the site nothing it had not set aside,
and it needs no assumption about which step was interrupted or what that step
costs. A per-step allowance would be more exact and would need the estimate
broken down per step to be exact at all; the conservative figure is the one that
cannot be wrong in the dangerous direction.

**Tests.** `Interrupted_ChargeTest` covers the release recording the floor, and
the sequence the finding names: interrupt a paid claim, restart it, finish
successfully, and assert the settlement cannot fall below what was reserved.

## F120-02 — Confirmed. Fixed, with one residual stated rather than implied.

Three changes.

**Every run-row write from a claimed step is fenced.** `Run::update()` adds the
claim to its WHERE clause whenever this object holds one, so the article
identity, the post link, the reservation, and the settled cost all refuse a
worker whose claim has moved. `close()` does the same for the terminal
transitions a step performs itself — the duplicate-topic skip the finding names
could previously close a run its replacement was part way through.

**Usage is incremented by the database, and is deliberately not fenced.** The
counters were a read-modify-write, so two workers each wrote a total computed
before the other's; `image_count` was set to 1 rather than counted, so two
pictures were billed twice and recorded once. Both are now SQL increments. They
are the one write a lost claim does not stop, and the reasoning is the mirror of
F120-01's: a provider that answered has charged for the answer whoever asked, and
refusing the write because the worker was replaced would delete the only record
of real money. Adding is safe from any worker precisely because it is not an
overwrite.

**The two WordPress writes re-check the claim.** Post assembly and image
attachment ask again immediately after the provider call and before the first
side effect, which is where nearly all of the window is: the call is the long
part, and the check costs one query.

**What this does not do.** It does not make a media sideload or a
`wp_insert_post()` conditional on a database row, so a claim lost in the instant
between the check and the write is not caught. A generation token on WordPress
writes would close that, and it would mean carrying a fence through
`wp_insert_post`, `wp_insert_attachment`, `set_post_thumbnail`, and
`wp_set_post_terms` — none of which take one — by wrapping each in a
compare-and-swap of our own. That is a larger change than this round should make
on the evidence, and the cost of the remaining case is a duplicate article rather
than lost money, because the usage counters record both workers' spending. It is
in the README as a known limit rather than left for the next review to find.

**Tests.** `Stale_WorkerTest` drives a worker through a sweep and a replacement,
then has it try to write the run row, close the run, assemble a post, and attach
an image — and asserts the live worker is not impeded.

## F120-03 — Confirmed. Fixed.

The finding is exactly right about WordPress, including the detail that made the
1.2.0 test unable to prove anything: `wp_set_object_terms()` inserts the
relationship without inspecting the result, and skips an integer term ID that no
longer exists. An array return is not evidence.

`Taxonomy_Applier` now clears the object's term cache and reads the terms back,
comparing what is on the post against what was asked for — by ID for categories,
by slug for tag names — and reports what is missing. Both cases in the finding
are now tested: a refused relationship insert, and a category deleted between the
prompt being saved and the run reaching assembly.

## F120-04 — Confirmed. Fixed.

Preview was the one run that opened its own row, and it had drifted out of every
contract added since.

- It opens through `Generator::open_preview()` now, so it records the same
  configuration fingerprint, resolved models, and rate snapshot as any other run,
  and settles from them.
- Runs record a kind. `Stall_Sweeper` closes an abandoned preview and does
  nothing else: no re-arm, no failure notice, no attempt counter, because none of
  those belong to a preview. Closing it is the whole of what it needs, since the
  only thing it leaves behind is its reservation.
- `Queued_Run_Handler::handle_step()` refuses to advance a preview at all, which
  is the belt to that braces: an action armed by an earlier version cannot make
  the sequence finalise a post that was never created.
- The `succeed()` result is inspected. A preview that cannot be closed still
  returns its article — the user paid for it and is waiting to read it — but it
  no longer does so silently.

A row written by 1.2.x has no recorded kind, so `kind()` falls back to the step
column, which previews have always written. Tested.

## F120-05 — Confirmed. Fixed.

The active action set is read once per sweep and intersected with each page in
PHP. Staleness across pages was already handled — `recover()` re-asks about the
individual run before doing anything — so the per-page read bought nothing.

The query is also joined to the plugin's action group. That adds nothing today,
since the hook name is the plugin's own; it means a future collision cannot make
the sweeper believe somebody else's action is advancing one of these runs.

`Stall_SweeperTest` now fills more than one page and asserts the bulk read
happens at most once. The per-run freshness check is a different statement and is
bounded by the recovery batch rather than by pages scanned, which is the bound
that was already correct.

## F120-06 — Confirmed. Fixed, and the earlier claim withdrawn.

The 1.2.0 response said the REST API, WP-CLI, and imports all reach the editor's
save validation. That was wrong, and the review is right about why: the save
handler returns before any validation unless the editor's nonce is present, the
prompt post type is not exposed over REST at all, and `wp post meta update`
submits no nonce.

Rather than document the gap, the rules moved: `Prompt_Validator` holds both
cross-field rules, the editor calls it, and it is hooked on `save_post` and at
the end of any request that writes one of the meta keys it reads.

**Why the end of the request rather than the write itself.** Correcting on each
meta write fights the writer. Setting image mode to fallback and then setting the
fallback image is the natural order — it is the order the editor's own save loop
uses — and the moment between the two is a state the rules would correct, undoing
a configuration that was about to become valid. Deferring means the rules judge
what the writer finished.

Two residuals, stated rather than implied: a write that bypasses the meta API
(direct SQL) is not seen by anything in PHP, and a configuration split across two
requests cannot be told apart from a writer that stopped half way. Run-time
enforcement is the backstop for both, and it is the control that decides what
actually gets published.

---

## On the Action Scheduler 3.9.3 → 4.1.0 note

Recorded, not acted on in this release. A major version of the queue this plugin
schedules everything through is not something to take on in the same release as
six concurrency and accounting fixes, and there is no advisory against the locked
version. It needs its own change, with the pipeline exercised against it.

## What is still not covered

Unchanged, and still in the README rather than implied: no test drives Action
Scheduler's own dispatch; the concurrency tests run interleavings in one process
rather than across two connections; CI runs against MySQL only; no test calls a
live provider. Added to that list this round: the residual window in F120-02,
which is a real limit rather than a missing test.

---
---

# Response to the sixth Codex review

**Responding to:** the fresh-verification section of
[CODEX-REVIEW.md](CODEX-REVIEW.md), dated 19 August 2026 against `01f272a`
(tag `v1.3.0`)
**Response date:** 19 August 2026
**Release under response:** 1.3.0 → 1.4.0

---

## Summary

Four findings. **All four confirmed, all four fixed.**

| Finding | Verdict | Status |
|---|---|---|
| F130-01 A terminally closed worker passes the claim fence | Confirmed | Fixed |
| F130-02 Preview recovery cannot tell a live preview from an abandoned one | Confirmed | Fixed |
| F130-03 Deleted prompt metadata bypasses the validator | Confirmed | Fixed |
| F130-04 Bulk queue detection can query a table the active store does not use | Confirmed | Fixed, with one correction to the fix as specified |

**Verification:** PHPCS passes with zero errors and zero warnings across 125
files. PHPUnit passes 358 tests and 1,483 assertions, up from 350 and 1,441. No
test contacts a live provider.

---

## F130-01 — Confirmed. Fixed.

The reproduction is exactly right, and the defect is the third instance of one
mistake: a guard taken as far as the example that prompted it and no further.
Round four fenced two writes. Round five fenced the rest of the writes. Neither
round asked what *ownership* means, and the answer had a third part nobody had
written down — the run still being open.

A terminal sweep closes a run at the claim it observed and leaves the marker
alone, so the token stays equal to `runs.step` for ever. The worker it closed
therefore read `lost_claim() === false`, and every claimed write matched.
Finalisation was the worst of it: claim, then `wp_update_post()`, so a run the
log reported as failed could still publish.

**The fix.** Ownership is one predicate — `id`, `status = running`,
`step = claim` — and it is now carried by `record_step()`, the payload write, the
generic claimed update, and both claim questions. The questions ask it in a
single query, per the required fix, so the position and the status cannot be read
from two different moments. Finalisation re-asks it immediately before the post's
status transition.

**One thing the required fix did not mention, and the tests caught.** A step that
ends the run itself — a budget skip, a duplicate topic — closes the row and then
returns its error, and with status in the predicate that worker suddenly looked
like it had lost the claim. Reading a self-close as a lost race would have turned
every skip into "somebody else owns this run" and the real outcome would never
have been reported. So `lost_claim()` is false for the object that performed the
transition, while its *writes* are refused exactly as anyone else's are: ending a
run is not losing it, and a closed row is finished either way.

**Tests.** `Stale_WorkerTest` now closes a run at the worker's own live claim and
asserts that the worker cannot write the row, the payload, or the position; that
finalisation refuses to publish and the post stays a draft; and that a worker
which closed the run itself still reports its outcome.

## F130-02 — Confirmed. Fixed.

Previews have no queued action to look for, so age is the whole liveness test,
and the threshold it was borrowing is filterable down to two minutes — inside a
normal preview, which can make two topic calls and a body call with its repair at
up to 120 seconds each.

Previews now recover on `PREVIEW_THRESHOLD`, thirty minutes, filterable through
`autoscribe_preview_stall_threshold` and floored at the queued-run threshold so a
site that raises one raises both and a site that lowers the queued threshold does
not drag this down with it.

I took the smaller of the two fixes offered. A lease the request clears in a
`finally` block is the better mechanism, and it is better because it survives a
preview that legitimately takes longer than any threshold — but it only helps if
the request reaches its `finally`, and the case being recovered from is the
request that did not. The gap between the two designs is a preview that runs for
more than half an hour, which is longer than four provider timeouts.

## F130-03 — Confirmed. Fixed.

`deleted_post_meta` is registered alongside the added and updated hooks, with the
first argument documented as an array of meta IDs there rather than one ID.
Deleting the fallback image now corrects the mode at the end of the request, and
there are tests for the deletion of a fallback ID and of a watched provider key.

## F130-04 — Confirmed. Fixed, with one correction to the fix as specified.

The finding is right: table existence is not store identity, and the contract in
the method's own docblock promised the check it was not making.

The required fix said to treat the hybrid store as a fallback case. I have not
done that, and the reason is worth stating rather than quietly departing from:
the hybrid store is what a stock Action Scheduler install runs *while it
migrates*, and it is the store this project's own test environment runs. It is
not another place to keep actions — it is a wrapper whose destination is the
database store, so every action created while it is in place, which is every
action this plugin schedules, is in the table the bulk query reads. Excluding it
would turn the ordinary case into the fallback and reintroduce the per-run reads
on a site that is merely mid-migration.

So the check accepts the database store and the hybrid store, and rejects
everything else. What that costs in the hybrid case is an action left unmigrated
in the post store — necessarily an old one — being missed by the bulk read; the
per-run re-check before recovery is what makes that safe, which is the same
argument the finding makes for the ordinary case.

I found this because the strict version of the check failed the suite: it
disabled the bulk read in the test environment, which is running
`ActionScheduler_HybridStore`. That is a better answer than the one I would have
written from the specification alone.

**Test.** The store singleton has no public setter, so the test swaps it through
reflection, asserts the post store falls back to the public API, and puts the
original back.

---

## What is still not covered

Unchanged: no test drives Action Scheduler's own dispatch; the concurrency tests
run interleavings in one process rather than across two connections; CI runs
against MySQL only; no test calls a live provider. The residual window from
F120-02 — a claim lost between the re-check and a WordPress write — is unchanged
as well, and is narrower than it was: a run that has been *closed* can no longer
be written to at all, so what remains is two live workers rather than one live
worker and a finished run.

---
---

# Response to the seventh Codex review

**Responding to:** the fresh-verification section of
[CODEX-REVIEW.md](CODEX-REVIEW.md), dated 19 August 2026 against `26bb7ee`
(tag `v1.4.0`)
**Response date:** 19 August 2026
**Release under response:** 1.4.0 → 1.5.0

---

## Summary

Three findings. **All three confirmed, all three fixed.** Each was checked
against the code before being acted on; none needed clarification, and the
late-usage reproduction was exactly right.

| Finding | Verdict | Status |
|---|---|---|
| F140-01 Late usage on a closed run does not reach the monthly cap | Confirmed | Fixed |
| F140-02 The final-publication test stops at the first claim | Confirmed | Fixed |
| F140-03 The preview-threshold comment contradicts the code | Confirmed | Fixed |

**Verification:** PHPCS passes with zero errors and zero warnings across 125
files. PHPUnit passes 363 tests and 1,521 assertions, up from 358 and 1,483.

---

## F140-01 — Confirmed. Fixed.

This is the other half of a decision made in 1.3.0 and left unfinished. Usage
counters are unfenced on purpose — a provider that answered has charged for the
answer whoever asked — and the review is right that the money then stopped at the
run log. The cap sums `cost_cents`; a closed run computed that before the late
counters existed; nothing re-measured it.

**What changed, following the recommended fix.**

- The counters stay unfenced. A billed response is never discarded.
- Every increment is followed by a reconciliation. On an open run it matches
  nothing, because settlement has not happened yet and will read the counters
  when it does. On a closed one it re-measures the row from the rates the run
  recorded and raises the cost with `GREATEST( cost_cents, measured )` in the
  statement — so two late increments cannot lose each other, whichever lands
  second carries both, and the figure can only move up. Measurement already
  includes the reservation floor, so a reconciliation cannot undo one.
- The grounded surcharge moved to a column and an atomic increment. It was in
  the payload document, which is fenced by the claim and by the run being open —
  the right rule for state and the wrong one for money, and the practical effect
  was that a late grounded call could not be recorded at all. Same reasoning as
  the token counters, so it is now the same mechanism.

**The claims the review says were too strong.** They were, and both are
corrected. The README and the sixth response said a closed run could not be
written to at all; the accurate sentence is that state writes are fenced, money
is accepted from any worker at any time, and a late charge raises the closed
run's cost. I checked the usage-recording error messages the finding also names:
those describe a *refused* write, where the run stops and the object's in-memory
counters book the charge into the closing settlement, and they are accurate as
written.

**Tests.** Late text usage, a late image, a late grounded call, and two late
increments on one closed run — each asserting the raw counters, the settled
`cost_cents`, and `month_to_date_cents()` agree.

## F140-02 — Confirmed. Fixed.

The finding is right that the test proved the outer guard and not the inner one:
the row already held a claim marker, so finalisation's own `claim_step()` failed
and it returned before reaching the pre-publication check.

There are two tests now. The old one keeps its scenario and takes a name that
says what it covers — finalisation refusing a run it cannot claim. The new one
closes the run in the gap the finding names, from inside the query filter,
immediately before the ownership read that guards the transition.

**It is verified by removal, not by passing.** With the pre-publication check
deleted the test fails, and it fails on the assertion that matters: the post is
`publish` rather than `draft`. The publication assertion is deliberately first,
so a removed guard is reported as a published post rather than as an unexpected
error code.

**One departure from the suggested method.** The finding suggested a second
connection. I tried that first: a second connection cannot touch a row created
inside the test's own uncommitted transaction — it waits on a lock nobody will
release, and the test takes fifty seconds to fail. The close is issued on the
same connection instead, which puts the statement in exactly the right place in
the sequence, and the reason is recorded in the test.

## F140-03 — Confirmed. Fixed.

The constant's comment said the queued-run threshold is not used for previews,
and `preview_threshold()` returns the larger of the two. The comment now states
the rule the code implements: it is a floor, so lowering the queued threshold
cannot pull a preview's below thirty minutes and raising it for a slow host
raises both.

---

## What is still not covered

Unchanged, and still recorded in the README: no test drives Action Scheduler's
own dispatch; the concurrency tests interleave in one process rather than across
two connections; CI runs against MySQL only; no test calls a live provider. The
Action Scheduler 4.1.0 upgrade is still deferred to its own change.

The check-to-WordPress-write window from F120-02 also remains, and is where it
was: a claim lost in the instant between the ownership check and a media or post
write. What has changed since it was first stated is that the money side of it is
now fully accounted for — whichever worker's article survives, both workers'
spending reaches the cap.

---
---

# Response to the eighth Codex review

**Responding to:** the fresh-verification section of
[CODEX-REVIEW.md](CODEX-REVIEW.md), dated 19 August 2026 against `2e542f7`
(tag `v1.5.0`)
**Response date:** 19 August 2026
**Release under response:** 1.5.0 → 1.6.0

---

## Summary

Three findings. **All three confirmed, all three fixed.**

| Finding | Verdict | Status |
|---|---|---|
| F150-01 Terminal cost reconciliation is not durable | Confirmed | Fixed |
| F150-02 The grounded column loses an additive legacy count | Confirmed | Fixed |
| F150-03 The README correction contradicts and repeats itself | Confirmed | Fixed |

**Verification:** PHPCS passes with zero errors and zero warnings across 125
files. PHPUnit passes 368 tests and 1,549 assertions, up from 363 and 1,521.

---

## F150-01 — Confirmed. Fixed.

The finding is right, and the phrase it takes issue with — "fully accounted
for" — was mine and was too strong for the same reason the previous one was: it
described the successful path and called it the property.

Pricing a counter cannot be done in the statement that raises it without writing
the rate table into SQL, which would be a second implementation of the money
formula and the last place to want one. So the two writes stay separate and the
gap is made recoverable instead.

**The mechanism.** Every money write now also sets `cost_stale` on a closed row,
in the same statement — so the flag cannot be lost separately from the money it
refers to, which is what makes this durable rather than merely retried.
Reconciliation clears the flag with a compare-and-swap on the counters it
measured: an increment arriving mid-measurement means the condition matches
nothing, the row stays flagged, and that increment's own reconciliation or a
repair pass prices both. `GREATEST` still means the figure can only rise.

**The two repair passes**, which is the part the finding asks for by name:

- `Budget_Guard::check()` settles outstanding rows before it sums. The caller
  holds the spend lock, so this closes the successful-path race the finding also
  notes: a new run cannot pass a cap on a total that was known to be short.
- The stall sweep settles a bounded batch every five minutes, because a site that
  has stopped generating would otherwise never reach the budget guard and would
  keep an unpriced row for ever.

**On returning success from the recorders.** The finding says not to report
success when the cost update failed. I have kept the recorders reporting on the
counter write, and the reason is the flag: it is written by the same statement,
so if the counters landed the flag landed, and the accounting operation really is
complete — it is finished later rather than left undone. Returning false would
fail a run whose money is recorded and scheduled for pricing, which is a worse
answer than the one the flag makes possible. `reconcile_cost()` itself returns
its result, so a caller that wants to know can ask.

**Tests.** The reconciliation statement is refused while the counter write is
allowed — the exact interruption — and the assertions are that the money is on
the row, the price is not, the row says so, and a later pass fixes all three. A
second test puts a cap between the unpriced and priced figures and proves the
budget guard repairs before it sums. A third proves an open run is never flagged.

## F150-02 — Confirmed. Fixed.

Confirmed by reading it and by the test, which fails on the old code exactly as
the finding describes: legacy one, column zero, one increment, total one.

I took the first of the two suggested designs — copy the legacy value into the
column during the migration and keep the payload as a read fallback — because it
leaves one number in one place afterwards, and the alternative (`payload +
column`) makes every future reader responsible for never backfilling.

Only open runs are migrated. A closed run's money was already settled under the
old reading, and raising its counter now would price a surcharge into a figure
that had been accounted for; there is a test for that too.

## F150-03 — Confirmed. Fixed.

The paragraph was extended when it should have been replaced, which is exactly
what the finding says the repetition shows. It is rewritten around the division
it is trying to express — state writes refused, money writes accepted — and the
duplicated conclusion is gone. It now also says what F150-01 makes true: a late
charge is priced by the next repair pass, at most five minutes away and always
before the next run is allowed to spend.

---

## What is still not covered

Unchanged: no test drives Action Scheduler's own dispatch; the concurrency tests
interleave in one process; CI runs against MySQL only; no test calls a live
provider; Action Scheduler 4.1.0 is deferred to its own change.

The check-to-WordPress-write window from F120-02 also remains, and is where it
was: a claim lost in the instant between the ownership check and a media or post
write. Its money side is settled either way — both workers' spending reaches the
cap, now including when the process recording it dies part way.

---
---

# Response to the ninth Codex review

**Responding to:** the fresh-verification section of
[CODEX-REVIEW.md](CODEX-REVIEW.md), dated 19 August 2026 against `7fea053`
(tag `v1.6.0`)
**Response date:** 19 August 2026
**Release under response:** 1.6.0 → 1.7.0

---

## Summary

Four findings. **All four confirmed, all four fixed.**

| Finding | Verdict | Status |
|---|---|---|
| F160-01 Usage escapes accounting during the terminal transition | Confirmed | Fixed |
| F160-02 Budget preflight sums while known stale cost remains | Confirmed | Fixed |
| F160-03 The migration does not add existing 1.5 grounded calls | Confirmed | Fixed |
| F160-04 The README promises a repair deadline the queue cannot keep | Confirmed | Fixed |

**Verification:** PHPCS exits zero across 125 files — checked by exit status
rather than by reading the tail of the output, which is how the last round's
failure got past me. PHPUnit passes 375 tests and 1,637 assertions, up from 368
and 1,549.

---

## The shape of these four

Three of them are one boundary seen from three sides: money recorded at a moment
when nothing was watching. 1.6.0 marked a row whose charge arrived after it
closed, and stopped there — so a charge arriving *during* the close, a backlog
larger than one repair batch, and an upgrade that merged two counts from
different periods all went through. Each is the same lesson the last three rounds
taught in different words: a rule applied at the boundary that prompted it, and
not at the next one along.

What has changed this time is that the fixes are stated as an invariant rather
than as a patch to a case: money raises a revision, a price names the revision it
priced, and anything that finds those two disagreeing marks the row. The three
boundaries fall out of that one rule rather than each needing its own.

## F160-01 — Confirmed. Fixed.

The interleaving is exactly as described, and the counter statement cannot
mark it, because at that instant the row is legitimately open.

Every money write now raises `usage_revision`, open or closed.
`measured_cents()` records the revision it priced — read after the counters, so a
charge landing between the two reads makes the revision newer than the figure
rather than older. Both terminal statements carry that revision and set
`cost_stale` when the row has moved past it, so the close marks itself.

The conditional close does this in its own statement, which is better: one
statement cannot be interrupted half way. `wpdb::update()` cannot compare two
columns, so the unconditional close checks immediately afterwards; the window
that leaves is between two statements of one request, and what it writes is the
same marker the repair passes look for.

**Verified by removal.** With the marking neutralised the new test fails on the
assertion that matters — the closed row does not say it owes a price. It is not
a test that merely passes.

## F160-02 — Confirmed. Fixed.

The 26-row reproduction is right, and so is the observation that a zero-row
compare-and-swap was being reported as success.

- `Run::settle_all_unsettled()` drains in bounded pages and returns whether it
  finished. It also stops early when a whole page settles nothing, because
  another pass would read the same rows and fail the same way.
- `Budget_Guard::check()` calls it before summing and **refuses the run** with
  `autoscribe_accounting_unavailable` when it cannot finish. A cap that cannot be
  worked out has to stop spending rather than pass it; authorising against a
  total the database says is short is the failure the whole mechanism exists to
  prevent.
- `reconcile_cost()` now returns false for a failed read and for a
  compare-and-swap that matched nothing, and true only when it settled the row.

One thing worth recording about the zero-row case, because a test made it clear:
when a charge lands mid-measurement, that charge's *own* reconciliation prices
both, so the row ends up correct even though this caller's attempt missed. False
is still the honest answer for the attempt, and the test asserts both halves.

## F160-03 — Confirmed. Fixed.

The finding is right that the payload and the column count different periods, and
that my test only proved the case where the column was still zero.

The migration adds the two and removes the legacy key in the same conditional
write, which is what makes it idempotent: a row that still carries the key has
not been migrated, and the write is conditional on the exact payload it was read
from, so a document a worker changed in between is left for the next pass. Closed
rows are migrated too and flagged for repricing — their money was settled under
the reading that dropped a search, which is precisely why it has to be added now.
The schema version is recorded only if the data migration finished, so an
interrupted one is continued rather than forgotten.

This reverses the "leave settled runs alone" rule I added last round, and its
test with it. That rule was protecting closed rows from a migration that
*overwrote*; with an additive one, leaving them alone is what loses the money.

## F160-04 — Confirmed. Fixed.

"At most five minutes" was the sweep's schedule read as a bound on when Action
Scheduler runs it, which it is not — the project's own README says elsewhere that
nothing tests the queue's dispatch, so I should have caught the contradiction.

The paragraph now separates the two mechanisms: the budget check clears the whole
backlog before it sums and refuses the run if it cannot, which is the guarantee
that matters and does not depend on the queue; and the sweep clears a batch in
the background on a best-effort schedule, for a site that has stopped generating.
It also says where to find a past-due `autoscribe_sweep_runs` action and how to
run it.

## One thing this round found on its own

The 1.7.0 archive built at twice the size of 1.6.0, which is the only reason I
looked: a stray copy of the previous release zip was sitting in the working copy,
`.gitignore` was hiding it from `git status`, and `.distignore` did not exclude
it — so the build staged it inside the plugin. Nothing shipped: 1.6.0's archive
is clean and 1.7.0 was rebuilt before release.

Archives are excluded from the package now, and the build stops if one is staged
anyway. A packaging check that only works when somebody notices a number is not a
check, which is the same lesson as last round's trimmed lint output.

---

## What is still not covered

Unchanged: no test drives Action Scheduler's own dispatch; the concurrency tests
interleave in one process; CI runs against MySQL only; no test calls a live
provider; Action Scheduler 4.1.0 is deferred to its own change. The
check-to-WordPress-write window from F120-02 remains, with its money side settled
either way.

---
---

# Response to the tenth Codex review

**Responding to:** the fresh-verification section of
[CODEX-REVIEW.md](CODEX-REVIEW.md), dated 20 August 2026 against `1ec6794`
(tag `v1.7.0`)
**Response date:** 20 August 2026
**Release under response:** 1.7.0 → 1.8.0

---

## Summary

Four findings. **All four confirmed, all four fixed.**

| Finding | Verdict | Status |
|---|---|---|
| F170-01 A new revision can certify an old usage snapshot | Confirmed | Fixed |
| F170-02 The migration can report false success and false failure | Confirmed | Fixed |
| F170-03 One irrelevant stale row can stop all future runs | Confirmed | Fixed |
| F170-04 The release archive and changelog do not match the tag | Confirmed | Fixed for future releases; 1.7.0 documented |

**Verification:** PHPCS exits zero across 125 files. PHPUnit passes 382 tests and
1,666 assertions, up from 375 and 1,637.

---

## F170-01 — Confirmed. Fixed.

The invariant I described last round did not hold, and the code said so if
anyone had read it carefully — including me, since I wrote the comment claiming
the opposite. `load_usage()` read the counters and `measured_cents()` read the
revision a statement later, so a charge landing between them produced a price
computed without it and stamped with a revision that said the price was current.
The close then compared equal revisions and left the row clean.

Everything a price is made of — status, models, counters, grounded count, floor,
and revision — now comes from one `SELECT`. `reconcile_cost()` uses that same
read for the status it acts on, instead of taking status and grounded count in a
query of their own.

Three things came with it:

- **A failed measurement no longer closes the books.** If the read fails, this
  object cannot say what the run cost, so both terminal statements mark the row
  instead of writing a figure nothing stands behind.
- **The unclaimed close is one prepared statement**, like the claimed one. The
  `wpdb::update()` plus follow-up marker pair is gone, and with it the window
  where a process could stop between closing a run and recording that its price
  was short.
- **The grounded-call migration raises the revision**, because it changes a
  counter that costs money and a close that cannot see that change prices without
  it.

Both new tests are verified by removal: neutralise the marking and they fail on
the assertion that matters.

## F170-02 — Confirmed. Fixed.

Both directions were real, and the false-failure one was the worse of the two —
a payload merely containing the text `grounded_calls` was re-read on every page
and every subsequent request, because the schema version is only recorded when
the migration finishes. One odd payload would have put a table scan and a
`dbDelta()` pass on every request the site served.

- It pages by ID cursor, advancing past every row it inspects, so a row with
  nothing to move is a row dealt with rather than one that comes back for ever.
- `$wpdb->last_error` is checked on both the page query and the completion query;
  a failed read is a failure rather than an empty table.
- Completion is decided by decoded keys and the cursor, not by whether the raw
  JSON still contains a substring.
- `dbDelta()` is not taken at its word: the new column is confirmed to exist
  before the version is recorded.
- The version is bumped again, so an install that recorded 7 after a failed read
  retries.

## F170-03 — Confirmed. Fixed.

Failing closed is right when a cap cannot be computed, and I applied it to every
run rather than to the runs a cap would have summed.

The guard reads both caps first and returns early when neither is set — an
uncapped site has no total to be wrong about, and the sweep still repairs in the
background. When a cap is set, repair is scoped to exactly what that cap sums:
one prompt's current month for a per-prompt cap, the site's current month for the
global one. `Run::unsettled()` takes those bounds, so repair and summation cover
the same rows by construction rather than by coincidence.

The Run Log now shows "Accounting pending" against a run whose charge has not
been priced, which is what the refusal message tells the operator to look for.
The message said that before it was true; it now names the column that shows it.

## F170-04 — Confirmed. Fixed for future releases, and 1.7.0 documented.

The archive was built, the changelog was then edited, and the tag was made
afterwards — so the published 1.7.0 asset contains every runtime file exactly as
tagged and a `CHANGELOG.md` one bullet short. The date on that entry was wrong
too: 19 August for a release made on the 20th.

- The date is corrected.
- `bin/build.sh` refuses to run against a working copy with uncommitted changes
  to anything it packages. That is the actual cause — building from a tree that
  was not the tree committed — and it is now impossible to do by accident.
  `AUTOSCRIBE_ALLOW_DIRTY=1` exists for a throwaway local build.

**I have not replaced the published 1.7.0 asset.** Rewriting a published artifact
to correct a one-file documentation difference seemed worse than recording the
difference: anyone who downloaded it has a valid build of the tagged runtime
code, and a silently swapped asset with a different digest is a worse
provenance story than an accurate note. The 1.8.0 entry records exactly what
differs. If you would rather it were replaced, say so and I will.

---

## What is still not covered

Unchanged: no test drives Action Scheduler's own dispatch; the concurrency tests
interleave in one process; CI runs against MySQL only; no test calls a live
provider; Action Scheduler 4.1.0 is deferred to its own change. The
check-to-WordPress-write window from F120-02 remains, with its money side settled
either way.

The build guard is a check on the *inputs* to a release rather than on the
artifact. CI building and publishing the archive itself — the review's stronger
suggestion — is a better answer and a larger change to how this project releases;
it is worth doing on its own rather than alongside four accounting fixes.

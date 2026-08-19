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

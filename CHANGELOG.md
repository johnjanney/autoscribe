# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## Version management

### What the numbers mean

`MAJOR.MINOR.PATCH`, and for a WordPress plugin the public API is larger than
the PHP classes. Anything a site owner or another plugin can depend on counts.

| Change | Bump |
|---|---|
| Removing or renaming a meta key, option, hook, filter, or capability | **MAJOR** |
| A database migration that cannot be rolled back | **MAJOR** |
| Raising the minimum PHP or WordPress version | **MAJOR** |
| Changing a default in a way that alters existing sites' behaviour | **MAJOR** |
| New feature, new setting, new provider adapter | **MINOR** |
| New hook or filter, additive only | **MINOR** |
| A default that only affects newly created prompts | **MINOR** |
| Bug fix with no interface change | **PATCH** |
| Documentation, tests, CI, tooling | **PATCH** |

Two judgement calls worth stating, because they are easy to get wrong:

- **Adding a prompt field is MINOR, not PATCH.** It changes what the editor
  renders and what is stored, even though nothing breaks.
- **Changing a sanitiser so it strips something it previously allowed is
  MAJOR-ish.** It is a bug fix in intent, but existing content behaves
  differently afterwards. Ship it as MINOR at minimum, and say so loudly in the
  entry.

### Where the version lives

The plugin header in `autoscribe.php` is the single source of truth. The
`AutoScribe\VERSION` constant mirrors it because the User-Agent needs it at
runtime and parsing the plugin file on every request would be a real cost for no
benefit.

The two cannot drift: `tests/VersionTest.php` asserts they match, so a release
with a bumped header and a stale constant fails the suite before it ships.

### Release checklist

1. `Unreleased` entries are complete and grouped under Keep a Changelog headings
   (`Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`).
2. Bump the version in **both** places in `autoscribe.php` — the header comment
   and the `VERSION` constant.
3. Move `Unreleased` content into a new dated section. Leave `Unreleased` in
   place and empty.
4. Update the version and any status claims in `README.md`.
5. Regenerate translations:
   ```bash
   npx wp-env run cli --env-cwd=wp-content/plugins/autoscribe \
     wp i18n make-pot . languages/autoscribe.pot --slug=autoscribe \
     --domain=autoscribe --exclude=vendor,tests,dev,build,node_modules,bin
   ```
6. `./vendor/bin/phpcs` and the full PHPUnit suite pass.
7. `bin/build.sh` produces `build/autoscribe-{version}.zip`. Earlier builds are
   left in place on purpose — `build/` is the local archive of what was shipped.
8. Commit, push, and **wait for CI to pass on all three PHP versions**. Do not
   tag a commit CI has not gone green on.
9. Tag and release:
   ```bash
   git tag -a v{version} -m "AutoScribe {version}"
   git push origin v{version}
   gh release create v{version} build/autoscribe-{version}.zip \
     --verify-tag --title "AutoScribe {version}" --notes-from-tag
   ```
   `--title` is not optional: `--notes-from-tag` fills the body but leaves the
   release name empty.

### Build artefacts

`build/` is gitignored. Binaries do not belong in version control, and the
durable home for a released zip is its GitHub release. The local copies exist so
you can diff what you actually shipped against what you are about to ship, so
`bin/build.sh` no longer clears the directory — it replaces only the zip for the
version being built, and lists what is on disk when it finishes.

---

## [Unreleased]

Phases 1 to 4 of the pipeline split scoped in `docs/PIPELINE-SPLIT.md`.
Phase 3 is the first part a site would notice: a scheduled run is now advanced
one step per queued request instead of running end to end in one. It is on `main`
rather than in a release because the phases still to come — the finalise step and
the stall sweeper — are what make the split worth having.

### Fixed

- **A second writer to `runs.payload` would have destroyed the first one's
  data.** `Run::record_sources()` encoded a fresh single-key object over
  whatever the column held — correct while it was the only writer, and silently
  destructive the moment it was not. Section 5 makes a second writer the design
  rather than an accident, so every payload write now goes through
  `Run::merge_payload()`, which merges at the top level. The grounding sources
  recorded under section 7.1 were the data that would have been lost.

- **Abandoning a run left the prompt's attempt counter raised.** The counter
  lives on the prompt because a retry opens a new run and the count has to
  survive across rows. Every terminal path clears it except the two that abandon
  a run when the prompt is gone or switched off, so a prompt disabled part-way
  through a retry series and later switched back on resumed mid-series and
  quietly got fewer attempts than it should.
- **A prompt deleted mid-chain killed the queue action instead of closing the
  run.** The branch that handles a removed prompt went on to ask that prompt
  whether grounding was enabled, which is a fatal when there is no prompt left.
  The action died before the run could be failed or its chain cancelled, so the
  run stayed open with its budget reservation held — the opposite of what the
  branch exists to do.
- **The grounded-request surcharge was settled from the prompt's current
  setting.** A run outlives an edit, so that was wrong in both directions:
  disabling grounding after the grounded call dropped the surcharge from a
  request already paid for, and enabling it beforehand added a surcharge for a
  request that never happened — the proposal call's own tokens are enough to make
  settlement apply whatever count it is given. The step that makes the request
  now records that it made one, and settlement reads that instead. Nothing else
  can know: the surcharge is not part of the usage providers report.
- **A retry the queue refused stopped the prompt silently.** The retry branch
  deliberately leaves the regular next occurrence unarmed, because a retry is
  outstanding — so when the queue would not take the retry, the prompt was left
  with a raised attempt counter, no queued action of any kind, and nothing said.
  Reporting the refusal was not enough on its own; the caller now treats the run
  as finished, clears the counter, arms the next occurrence, and reports the
  refusal rather than the transient failure behind it. The transient failure is
  on the run row either way; that the queue would not take the retry is the part
  nobody would otherwise learn.
- **A next occurrence that could not be armed was not reported either.** Nothing
  else in the system notices: there is no queued action left to fail and no run
  to record it against, so the prompt simply never runs again — the outcome
  section 4.3 exists to prevent. It now sends the failure notice.
- **A generated post could be left with nothing pointing at it.** Later steps
  read the post back off the run rather than receiving it as an argument, because
  they run in separate requests, and the write that links them discarded its
  result. A refused link left the image step attaching its picture to post 0 and
  publishing looking for a post that was never recorded. Found by auditing the
  remaining discarded write results rather than by a review.
- **Aborting a run part-way did not charge for the grounded call it had made.**
  Settlement measures token and image usage; the grounded-request charge is
  passed in, and every abort path left it at zero. A run stopped after its
  grounded body call therefore settled for less than it spent, and the
  month-to-date total the cap reads was short by the difference.
- **A run whose settings fingerprint could not be stored started anyway.** A
  missing fingerprint is read as "opened by an earlier version", which is right
  for an upgrade and wrong for a failed write: the run would then accept any edit
  silently — the guard added above turned off by the one failure it most needs to
  survive.
- **Deactivating the plugin left its whole queue armed.** The teardown passed a
  hook *and* a group with empty arguments, which makes Action Scheduler skip its
  cancel-everything-for-this-hook shortcut and match only actions whose arguments
  are exactly empty — and every action this plugin arms carries a prompt or run
  ID. So nothing was ever cancelled. It cancels by hook now, and clears the new
  step actions as well: those are keyed by run rather than by prompt, so nothing
  else reaches them, and left behind they either strand their runs or resume a
  half-finished one when the plugin is switched back on. This predates the split;
  writing a test for the new hook is what found it.
- **The queue wrapper could not tell a scheduled job from a refused one.**
  `as_schedule_single_action()` returns 0 when it cannot create an action, and
  all three arming calls discarded that. Each discarded it into a different
  silence: a refused step left a run in `running` with no next action and its
  budget reservation held indefinitely; a refused retry dropped the attempt; and
  a refused re-arm stopped the prompt for ever, which is the one outcome section
  4.3 exists to prevent. All three now report the failure.
- **A prompt edited mid-run applied to the rest of that run.** Every step action
  reloads the prompt, so an edit landing between two queue passes took effect for
  the remaining steps: a larger model or a newly required image spending against
  a cap that was checked for the old settings, and — the case that matters most —
  a change of publication mode letting a run that began under review finish by
  publishing, turning section 10's safety model off retrospectively for work
  already in progress. A run now records a fingerprint of the settings it was
  checked against and stops if they change. The next occurrence runs under the
  new settings from the start, which is what the editor asked for.
- **A scheduled run settled its cost as zero, so the monthly cap could not
  fire.** Token and image usage is accumulated in memory and written out whole,
  which is correct only while one object sees every call a run makes. Advanced
  one queued action at a time, each step's counters overwrote the last step's —
  and the object that settles the cost saw no usage at all, replacing the
  reservation with zero. Every scheduled run therefore reported spending nothing,
  the month-to-date total never moved however many articles were generated, and
  section 7.4's cap had nothing to act on. Usage is read back off the row before
  it is added to.
- **`Run::post_id()` reported no post for any run it had not opened itself.** It
  returned an in-memory property, which is correct only while a run exists solely
  inside the request that opened it. A run advanced one queued action at a time
  is a fresh object each time, so publishing failed with "Invalid post ID" on
  every scheduled run. It reads the row when nothing is cached.
- **A step that could not be recorded as completed made the run repeat it.**
  Everything downstream reads `runs.step` to know where a run has got to, and
  the write that advances it discarded its result. A refused write therefore
  left the run pointing at the step that had just finished, and the driver — told
  the step succeeded — read the same position back and ran it again, and kept
  running it: for as long as PHP allowed, with the budget reservation held open
  throughout. Under the queue driver it would have been an endless chain of
  actions instead. The refusal is now terminal.

  The synchronous driver is also bounded to one iteration per step. That is not
  a second fix for the same fault but a guard against the ones nobody has thought
  of yet: a sequence that stops advancing now ends the request instead of
  spinning inside it, whatever the reason.
- **The payload cache was assigned before the write was attempted.** A refused
  write left the merged document in memory, so the object went on reporting keys
  the row did not contain, and a later write persisted the rejected patch along
  with it. `Run::record_sources()` had the same fault one level up — and that is
  the one that reaches published content, since the section 7.1 sources block is
  built from it. Both now cache only once the write is accepted, and drop the
  cache on a refusal so the next read goes back to the database.

- **A grounded run published even when its sources could not be recorded.** The
  section 7.1 source URLs are the only record of what third-party text entered
  the model context. `Step_Generate_Body` discarded the result of writing them,
  so a refused write published the article anyway — without its Sources block
  where the prompt asked for one, and with no provenance record either way. The
  run now stops. That costs an article already paid for, which is the right
  trade: the refusal is the runs row rejecting writes, and assembly, settlement,
  and closing the run all write to that same row, so the run could not complete
  correctly in any case.

### Added

- **Idempotency guards on every paid step**, as section 5 requires. The topic
  proposal and the article are stored on the run and returned on re-entry
  instead of being bought again; the image step records its outcome, including
  a decision to give up, so a re-entry does not pay a provider to make that
  decision a second time. `Step_Assemble_Post` was already idempotent. The
  budget check deliberately is not — skipping it would let a run past a cap
  breached in the meantime — and it is safe to repeat because the reservation is
  an absolute write rather than an increment, which now has a test saying so.
- A stored article is re-validated before it is trusted. Paying twice is bad;
  publishing from a truncated payload row is worse, so a stored copy that no
  longer satisfies the schema — or is not a fields array at all — is regenerated
  rather than used. Discarding it clears its source URLs too: they name text the replacement never read, and
  leaving them behind would publish a provenance record for an article that was
  thrown away.
- `Run::merge_payload()` and `Run::payload()`, the read and write side of the
  document the split pipeline will pass step state through.
- `Run::record_sources()` reports whether its write succeeded.
- `Article::to_array()` and `Article_Validator::from_array()`, so an article can
  be stored and rebuilt. Rebuilding re-validates rather than trusting the store:
  an `Article` exists only where the schema was satisfied, and a payload row that
  was truncated or written by an older version of the plugin is exactly where
  that stops being true on its own.

## [1.0.5] - 2026-08-19

Version 1.0.4 handled a failed draft adoption by carrying on and trusting
duplicate detection to stop the run. The automated review pointed out that this
has the mechanism backwards, and it is right.

### Fixed

- **A run whose adoption failed carried on and wrote a second draft anyway.**
  The already-covered list is injected into the proposal call precisely so the
  model proposes something *different*. So on a failed adoption the retry
  proposed a new topic, the collision check passed, the body was paid for, and
  assembly wrote a second draft beside the orphaned one — the pile-up adoption
  exists to prevent, reached by a longer route and with a provider bill attached.
  Relying on duplicate detection to abort only worked when the model happened to
  repeat itself, which is the one thing that list is there to stop.

  The run now ends with `autoscribe_adoption_failed` at the adoption site, before
  the first paid call.
- **The translation template had been stale since 1.0.0.** Regenerating it is in
  the release checklist, and a checklist is only as good as the last person to
  read it: twenty-nine strings added across 1.0.1 to 1.0.5 were missing from
  `languages/autoscribe.pot`, including both notification emails, the whole
  health panel, every image validation error, the grounding refusals, the
  weak-salt messages, and all four untrusted-data blocks. A localised site
  displayed all twenty-nine in English, and a translator had no way to fix it
  because the strings were not in the template they work from. Two strings that
  no longer exist were still asking to be translated.

### Added

- A test that fails when a translatable string in the code is absent from the
  template. The checklist is no longer the only thing standing between a new
  string and a site that cannot translate it. It does not compare the file to a
  fresh build — the header carries a timestamp and the version, so that would
  fail on every release for no reason — it asserts the property a translator
  depends on: if the plugin can say it, the template contains it.

## [1.0.4] - 2026-08-19

> **Correction, 19 August 2026.** The second entry below claimed a failed
> adoption would leave the run to "stand down as a duplicate". It would not — see
> 1.0.5. The first entry, on making adoption atomic, is accurate and stands.

A follow-on from 1.0.3. The same automated review pointed out that the fix made
adoption depend on two writes without checking either, so a failure in the second
one reproduced the state the fix was for. Confirmed, and closed properly this
time.

### Fixed

- **A refused ownership write left a half-adopted draft.** `update_post_meta()`
  can fail — a database error, or a filter on `update_post_metadata`
  short-circuiting the write — and 1.0.3 discarded the result. It also bound the
  run row *before* moving the post's run link, so a failure left the run naming
  the draft while the draft still named the attempt before it: exactly the
  mismatch that makes the next attempt refuse the draft and build a duplicate.

  Adoption is now all or nothing. The ownership write goes first, so the cheaper
  failure changes nothing at all, and it is verified by reading it back rather
  than by trusting a return value that is also false when the stored value
  already matches. The run row is bound only after that, and its own failure puts
  the ownership back.
- **A failed adoption is now treated as no adoption.** Carrying on as though it
  had succeeded would hide the old draft from duplicate detection and then write
  a second one beside it. The run stands down as a duplicate instead.

### Changed

- **A scheduled run is advanced one queued action per step.** Section 5 asks for
  this because a run takes 30 to 120 seconds and a host with a short
  `max_execution_time` terminates it part-way. Each request now carries at most
  one provider call, so what a kill costs is one step rather than an article.

  The new `autoscribe_run_step` hook carries a run ID and nothing else, and the
  position is read from `runs.step` — one hook rather than one per step, so the
  queue never holds routing the run row could contradict. "Run now" and Preview
  keep the synchronous path, and both drivers advance the same sequence.

  **What this does not yet give is recovery.** Action Scheduler marks a killed
  action failed and stops; it does not retry. A killed step still strands a run
  until the stall sweeper lands. The window shrinks from six provider calls to
  one; it does not close.
- **The step order moved out of `Generator` and into a `Pipeline` class.**
  Section 5 needs a run to be resumable from its row alone, because a queued
  action arrives knowing only a run ID — and an order expressed as a sequence of
  statements inside one method cannot be resumed, only restarted. `Pipeline`
  holds the list and executes one step at a time, taking every step's input from
  the run rather than from a local variable, which is what phases 1 and 2 were
  building towards.

  `Generator` keeps the synchronous path that "Run now" and Preview need, and
  drives the same sequence in a loop. There is deliberately one list and two
  drivers: two lists would be two descriptions of one order, and the one that
  drifts is the one nobody is looking at.

- The featured image work — the provider call, the sideload, the thumbnail, and
  the decision about what to do when no image can be had — moved out of
  `Generator` and into `Step_Generate_Image`, where the other four steps'
  equivalents already live. It had a practical cost as well as an untidy shape:
  the idempotency guard belongs with the paid call, and a guard in the
  orchestrator could not be tested, because the orchestrator runs the pipeline
  once and never re-enters.

- `Run::adopt_post()` and `Run::record_post()` return whether their writes
  reached the database.

## [1.0.3] - 2026-08-19

An automated Codex review of the 1.0.2 pull request found a narrow regression in
the draft-adoption tightening that release shipped. Confirmed, and fixed here
with the regression test that reproduces it.

### Fixed

- **A retry that adopted a draft and then failed before assembly broke the
  chain, and a later attempt created the second draft anyway.** Adoption is
  allowed only when the post's `_autoscribe_run_id` names the run being adopted
  from, and only `Step_Assemble_Post` writes that meta — so a retry that adopted
  a draft and then fell over on the topic or body call left its run row pointing
  at a draft that still named the attempt before it. The next attempt saw the
  mismatch and refused, which is exactly the duplicate 1.0.2 set out to prevent.
  Adoption now transfers the post's run link at the same moment it records the
  post on the run, so the two never disagree.

### Changed

- `Run::adopt_post()` replaces a bare `record_post()` call at the adoption site.
  Recording the post and moving its run link are one operation, and splitting
  them is what allowed them to drift apart.

## [1.0.2] - 2026-08-18

A second Codex review of the 1.0.1 release found that four of its fixes were
narrower than the release notes claimed, and that one of them introduced a
regression. This release makes those fixes hold, and corrects the claims that
did not.

Two of the follow-up findings were checked against the code and the provider's
own documentation and are recorded as rejected rather than fixed. Both, and the
evidence, are in `CODEX-REVIEW-RESPONSE.md`.

### Fixed

- **The monthly cap still had a concurrency bypass.** Version 1.0.1 replaced a
  read-then-write race with an ordering trick — re-read the total, count only
  rows up to the reserving run's own ID — and claimed it bounded the overshoot to
  one run. It did not. A row's ID is assigned when the run is inserted, not when
  its reservation is written, so the auto-increment does not order the
  reservations: a later run that reserves and re-reads before an earlier one
  reserves at all sees nothing, and so does the earlier one when its turn comes.
  Both spend. The check and the reservation now happen inside a named MySQL lock
  (`Spend_Lock`), which makes them atomic across processes. The ordering pass is
  kept only for databases where the lock cannot be taken, and is described as the
  weaker fallback it is.
- **A reservation that failed to write did not stop the run.** `$wpdb->update()`
  can return false, and the result was discarded, so a run could proceed to spend
  real money against a cap with no record of the spending. A failed reservation
  now ends the run before the first provider call.
- **An image could be generated without being reserved for.** The preflight
  estimate resolved the image model without the adapter's suggestions while
  generation resolved it with them, so a prompt with no image model and no site
  default priced the image under the *text* model — and the seeded Claude rows
  carry a zero per-image rate. A Claude article with an OpenAI picture and the
  model fields left alone reserved nothing at all for the image.
- **Draft adoption could overwrite an old or human-edited draft.** Adoption was
  written as "the newest failed run of this prompt that still has a post", with
  no tie to the retry that created it. Once retries were exhausted the draft
  stayed adoptable indefinitely, so the next ordinary scheduled occurrence — a
  different article, days later — overwrote it, and a reviewer part-way through
  editing it lost the work. Adoption now requires a retry, the immediately
  preceding row, a matching attempt number, an intact run link, and a post
  untouched since the run that created it closed.
- **A retry collided with its own abandoned draft.** Adoption was resolved after
  the body call, so duplicate detection had already counted the previous
  attempt's draft as a topic covered — meaning a retry after a successful body
  call was skipped as a duplicate of itself and adoption was unreachable in
  practice. The draft this run will overwrite is now resolved first and excluded
  from the covered list and the title check.
- **Retry classification was open by default.** The policy was a denylist of
  permanent codes, so any error nobody had classified yet — including every code
  a later release or a provider might introduce — was retried three times, paying
  for the same answer each time. It is now an allowlist of transport-level
  failures, filterable through `autoscribe_transient_error_codes`, and an
  unrecognised code is permanent.
- **The image size limit was applied after the download.** `download_url()`
  streamed the whole response to a temporary file with no ceiling, and the file
  was read whole into memory, before the 20 MB check ran. The limit protected the
  uploads directory and nothing else. The fetch now passes the limit to the HTTP
  layer, so it bounds bandwidth, disk, and memory.
- **Weak-salt key records survived the upgrade.** Version 1.0.1 refused to *store*
  a key where `AUTH_KEY` and `SECURE_AUTH_KEY` are absent or placeholders, but
  went on reading and using keys 1.0.0 had already stored that way — so the
  exposure the check was added to prevent continued on every site that already
  had it. Such records are now refused on read and reported on the Settings
  screen. They are left in the database rather than deleted; the screen asks for
  the key again once real salts are installed.
- **"Queue last processed" reported the scheduled time, not the completion time.**
  The query ordered by Action Scheduler's `date`, which is `scheduled_date_gmt`,
  and read the schedule object's own date, which is the time the action was armed
  for. A job due last Tuesday and run a moment ago was shown as last processed
  last Tuesday — inverting the panel's purpose, since a queue catching up looked
  stalest exactly when it was recovering.
- **Model-supplied text was still pasted into prompts as prose.** The agreed
  title went into the body call's instructions, and a response that failed
  validation went into the repair call's instructions, both unfenced. A proposal
  that had itself been steered carried the steering forward. Both now go in
  labelled, JSON-encoded untrusted-data blocks, as the already-covered list
  already did. So does the collision reason sent on a re-ask, which quotes an
  existing post title.

### Changed

- `Retry_Policy::permanent_codes()` is replaced by `transient_codes()`. The list
  it returns now means the opposite, so the name had to change with it.
- `Run::adoptable_draft()` takes the running attempt number, and
  `Topic_Deduplicator::recent_topics()` and `collision_reason()` take a post to
  exclude.
- The `DECISIONS.md` note on duplicate detection said the similarity threshold
  was 82 percent, as the brief specifies. The code has defaulted to 78 since
  0.5.0 for a stated reason; the document now records the deviation instead of
  repeating the brief.
- The README no longer claims the budget overshoot is bounded to one run, no
  longer calls the release a release candidate while the tag says otherwise, and
  counts the missing screenshot among the unmet requirements rather than
  contradicting itself two paragraphs later.

### Added

- Tests for draft adoption in both directions, for the reservation failure path,
  for image pricing with no model configured, for the bounded image download,
  and for the retry allowlist rejecting an unclassified code.

## [1.0.1] - 2026-08-17

> **Correction, 18 August 2026.** Four claims in this entry were broader than the
> code. The concurrency race was narrowed, not closed. The image size limit was
> applied after the download rather than bounding it. Weak-salt protection
> covered new writes only. And the retry fix named specific codes while leaving
> everything unclassified retryable. Version 1.0.2 fixes all four; read that
> entry rather than this one for the current state.

An external audit of the 1.0.0 release found defects in cost accounting, retry
behaviour, one provider contract, and several admin features that existed in
code but had no reachable path. This release fixes them and corrects the release
documentation, which claimed completeness the code did not have.

### Fixed

- **Google structured output used the wrong request contract.** The adapter sent
  `generation_config.response_mime_type` and `response_schema`, which the
  Interactions API removed when it replaced `generateContent`. Structured output
  now goes in a top-level `response_format` object. Every Google topic proposal
  requests a schema, so this affected every Google run.
- **Token usage was overwritten rather than accumulated.** The body call erased
  the proposal call's tokens, so the settled cost omitted every proposal a run
  had paid for.
- **A duplicate-topic skip recorded a cost of zero** despite having paid for one
  or two proposal calls, and skipped runs were excluded from the monthly total
  entirely. A prompt stuck proposing repeats could spend without the reported
  total moving. Skips now settle to what they really spent, and every status
  counts towards the cap.
- **A failed run kept its full estimated reservation.** A run that fell over on
  its first provider call still counted a whole article and image against the
  cap. Failures now settle from measured usage.
- **The preflight estimate priced one body call** while the pipeline can make
  four paid text calls — two proposals, the body, and one repair. It now bounds
  all of them.
- **The cap could be exceeded by concurrent workers.** Reading the month total
  and writing the reservation are separate statements, and Action Scheduler runs
  actions in batches. A second pass now counts only rows up to the reserving run,
  so concurrent runs resolve in row order rather than all passing.
- **Duplicate topics, budget breaches, and exhausted validation repairs were
  retried.** Each retry re-ran the paid work to reach the same answer, turning
  the documented one-repair limit into six paid calls. All three are now
  permanent.
- **A retry after an image failure created a second draft.** The retry now adopts
  the draft left by the previous attempt and updates it.
- **Run rows always recorded `attempt = 1`,** so the Run Log's attempt column
  never showed the real retry number.
- **A failed run-row insert produced a `Run` with ID 0,** which then silently
  discarded every subsequent write, including the budget reservation.
- **A refused `wp_update_post()` on the final publish transition passed
  unnoticed,** so a run reported success for a post still sitting in draft.
- **Grounding could be saved for a provider that has none.** DeepSeek has no web
  search; the prompt editor now disables the control and explains why, the save
  path refuses it, and a run that reaches the body step with an impossible
  configuration fails rather than quietly generating an ungrounded article.
- **Site default models were saved and ignored.** Generation read the prompt
  field and then fell straight through to the adapter's first hard-coded
  suggestion. Resolution is now prompt, then site default, then suggestion.
- **The "Test connection" control did not exist.** The method behind it had no
  hook, button, or form. It is now a per-provider button on the Settings screen.
- **The Preview control generated an article, charged it against the budget, and
  never displayed it.** The result is now rendered in the prompt editor, through
  the same sanitiser the pipeline applies before inserting a post.
- **A key that could not be stored was discarded silently.** The Settings screen
  now reports the failure instead of showing "Settings saved".
- **The pending-draft notice stopped counting at 100 drafts,** understating the
  backlog on exactly the sites most in need of the warning.

### Added

- Email notification when a draft is held for review, and one notification after
  a run's retries are exhausted. Section 10 required both; only the 80-percent
  budget warning existed.
- Response size limits on provider calls, and byte, pixel, and file-type checks
  on generated images. A faulty or hostile response could previously exhaust
  memory, disk, or image-processing time. Uploads are removed if the attachment
  fails to insert.
- An explicit untrusted-data block around the post titles and topic keys sent in
  the topic proposal call. Anyone who can author a post can write a title, which
  is a wider group than those allowed to manage prompts.
- Database key storage is refused when `AUTH_KEY` or `SECURE_AUTH_KEY` is absent
  or still a placeholder. Every such site derived the same encryption key from
  the string `"|"`, so a database dump was enough to read the stored keys. The
  health panel reports the condition.
- A "Queue last processed" row in the health panel. "Action Scheduler is loaded"
  is true of a queue that has not run for a week.
- An index on `runs.started_at`, which site-wide monthly spend filters on alone.

### Changed

- CI pins third-party GitHub Actions to full commit SHAs rather than moving
  major-version tags.
- The README no longer claims every brief requirement is implemented, and states
  the single-action pipeline, the cap's overshoot bound, and the missing
  screenshot as known deviations.
- The grounding warning in INSTRUCTIONS.md no longer claims the plugin wraps
  retrieved web content in delimiters. Search runs on the provider's
  infrastructure and the plugin never sees that text before the model does.

## [1.0.0] - 2026-08-17

> **Corrected by 1.0.1.** The completeness claim below was not accurate. An
> audit found that the pipeline does not use one queue action per step, several
> admin controls had no reachable path, and the cost cap did not hold. See the
> 1.0.1 entry. This entry is left as written for the record.

First stable release. Every section of the project brief is implemented: the
four text providers and two image providers, the six schedule types, the
generation pipeline with sanitisation and structured-output validation, web
search grounding, duplicate-topic avoidance, SEO adapters, taxonomy handling,
cost caps, the admin interface, and the human-review override.

No functional change from 0.8.0. This release marks the point at which the
brief's scope is complete and the version number stops understating it.

### Known limitations

These are real and are not blockers, but they are worth knowing about:

- The Settings screen's save path and the Test connection control have no
  automated coverage. The prompt editor's save path does.
- No test drives Action Scheduler itself. `Queued_Run_Handler` is tested by
  calling it directly, so the queue's own dispatch is exercised in development
  but not asserted.
- CI runs against MySQL only. A divergence between MySQL and MariaDB was found
  and fixed during phase 7; nothing guards against the reverse.
- Cost figures are estimates computed from reported token usage against a
  pricing table the site owner maintains. They are not billing data and the
  plugin never fetches prices.

## [0.8.0] - 2026-08-17

Closes the coverage gaps left by phase 7, and two defects found while writing
the tests for them.

### Added

- Grounding source URLs are now extracted from Anthropic, OpenAI, and Google
  responses and recorded on the run row, as section 7.1 requires. They were
  captured by the response object and then discarded: no adapter ever passed
  them, so the feature was nominal.
- Optional Sources list appended to grounded articles, controlled by a new
  per-prompt setting.
- Tests for the queued run path, retry backoff and the three-attempt cap,
  Preview, taxonomy application, grounding, and `uninstall.php`.
- Section 11's schedule integration test: three prompts on three schedule types
  across a month boundary and both America/Chicago daylight saving transitions,
  each asserting an exact UTC instant.

### Fixed

- `uninstall.php` left `autoscribe_pricing`, `autoscribe_global_budget_cents`,
  and `autoscribe_budget_notice_month` behind. Those options were added in
  phases 5 and 7 and never added to the uninstall.
- `uninstall.php` deleted every `_autoscribe_%` post meta key, which included
  the `_autoscribe_generated` flag section 6 adds to generated attachments so
  they can be found later, and the `_autoscribe_run_id` link section 10 adds to
  generated posts. Uninstall keeps that content deliberately, so destroying the
  only means of identifying it was self-defeating. The sweep is now an explicit
  key list, and a test asserts both halves.
- Prompt posts are now removed on uninstall. They were left behind as
  unreachable content of an unregistered post type.

### Changed

- `actions/checkout` bumped to v5, clearing the Node 20 deprecation warning.

## [0.7.0] - 2026-08-17

Section 9's admin interface, which section 11 never assigned to a phase.

### Added

- Tabbed prompt editor covering every setting in section 3.2, with a next-run
  readout and Run now and Preview controls.
- Run Log list table with filtering by prompt, status, and month, and a Retry
  action on failed runs.
- Settings screen: provider credentials, default model IDs, global spend cap,
  editable pricing table, notification address, run-log retention, and a system
  health panel.
- `Run::query()`, `Run::count()`, and `Run::prune()`.
- Section 10's global "force human review" override, which beats the per-prompt
  setting and an explicit WP-CLI status argument alike.
- Section 3.2's daily run-log retention job, defaulting to 90 days.
- Admin notice reporting how many generated drafts are awaiting review.

### Changed

- Every prompt field is now declared once, in `Prompt_Fields`, which both the
  render and the save pass read. A test asserts the round trip, so a field
  cannot be rendered without also being saved.
- The pricing table and global cap keep their own options rather than moving
  into `autoscribe_settings`. Section 3.2 asks for a single option but section
  8.1 contradicts it for keys, and both are read on paths that never load the
  rest of the settings.

### Fixed

- `phpcs.xml.dist` was scanning `build/`, reporting every class in the plugin as
  a duplicate of its own build output.

## [0.6.0] - 2026-08-17

### Added

- Continuous integration: PHPCS and PHPUnit on PHP 8.1, 8.2, and 8.3.
- `README.md`, including the third-party service disclosure naming every provider
  endpoint the plugin contacts and the data sent to each.
- `languages/autoscribe.pot` for translators.
- `bin/build.sh`, producing an installable zip containing the plugin and its
  production dependencies only.
- A test asserting that the plugin header version and the `VERSION` constant
  agree, so the two cannot drift.

### Changed

- The duplicate-topic test now provides its own API key rather than relying on
  the constants the development environment happens to define. It passed locally
  and would have failed in CI for an environmental reason rather than a real one.

## [0.5.0] - 2026-08-16

### Added

- Budget guard blocking a run before any provider call once the month-to-date
  total meets the prompt's cap, with the run row itself acting as the
  reservation so concurrent Action Scheduler workers cannot both pass the check.
- Topic deduplication rejecting a proposal that collides with existing content
  before the expensive body call, with a re-ask naming the collision.
- SEO adapters for Yoast, Rank Math, and SEOPress, writing to verified meta keys,
  and a null adapter for sites running none of them.

## [0.4.0] - 2026-08-16

### Added

- `Next_Run_Calculator` covering all six schedule types, including nth-weekday and
  last-weekday of month, month-end roll-forward, leap days, both DST transitions,
  and catch-up from a stored next run in the past.

## [0.3.0] - 2026-08-16

### Added

- End-to-end pipeline: topic proposal, body generation, image generation,
  sanitization, and post assembly, drivable from a single WP-CLI invocation.
- Featured image sideloading with alt text.

## [0.2.0] - 2026-08-16

### Added

- Provider adapters for Anthropic, OpenAI, Google, and DeepSeek text generation,
  and OpenAI and Google image generation.
- Test suite asserting the outgoing request shape for every adapter, and that a
  401 returns a `WP_Error` rather than a fatal.
- An HTTP tripwire that fails any test making an unmocked request.

## [0.1.0] - 2026-08-16

### Added

- Plugin scaffold, activation and uninstall routines, custom tables, the prompt
  post type, and encrypted API key storage.

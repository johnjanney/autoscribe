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

## [1.13.4] - 2026-08-20

The thirteenth external review. All five findings confirmed and fixed; the
verdicts, and the two places this departs from the recommended fix, are in
`CODEX-REVIEW-RESPONSE.md`.

### Fixed

- **A late charge could be priced while the budget check held the spend lock.**
  Money arriving after a run has closed takes the same lock the budget check
  holds from its final sum through its reservation — except that the code took
  the lock and ignored whether it got it, so a wait that timed out, or a database
  with no `GET_LOCK`, carried on and priced the row anyway. The charge is still
  always written: a provider that answered has been paid, and a charge nobody
  records is worse than one recorded late. What now waits is the *pricing*. The
  write marks the closed row as owing a price, and a row that owes a price is one
  the budget check must settle, under the lock, before it authorises any further
  spending.

  The status a charge decides on could also be out of date by the time the write
  landed, so a run closing in that gap was priced on the open path with nothing
  held. The row is re-read after the write and handed to the locked path.

- **Re-arming a prompt was two critical sections pretending to be one.** The
  cancel sat outside the lock the insert takes, so a prompt save and a finishing
  run could interleave and leave the queue holding an occurrence computed from a
  schedule that had already been replaced. Cancel and insert now happen under one
  lock, and the schedule is re-read from the prompt inside it — the caller's copy
  is only the fallback for a prompt that has since been deleted.

- **Uninstall left three options behind.** The schedule sweep's cursor was not in
  the list, and the 80-percent warning claims one option per month it fires in,
  named for that month, so they could not be listed at all. They are now found by
  their exact prefix and removed through the options API.

- **Documentation that had drifted.** The README said version 1.11.0 while the
  plugin shipped 1.13.3, and said two preserved meta keys where the code
  preserves three. `VersionTest` now asserts the README states the shipping
  version, so that particular drift cannot be committed again. A `Budget_Guard`
  comment still named the 512-token proposal ceiling that 1.13.1 replaced, and
  `Source_Extractor` described only the legacy Google grounding shape.

### Changed

- **The OpenAI and Google text calls are explicitly stateless.** Both APIs store
  every response by default — Google for 55 days on the paid tier — and this
  plugin never reads one back: each generation is independent and the pipeline
  keeps its own state in the runs table. Both adapters now send `store: false`,
  which is the only part of provider retention a caller can turn off. Verified
  against both providers' current documentation on 20 August 2026.

## [1.13.3] - 2026-08-20

A run that was interrupted once and then finished normally reported about three
times what it spent — 0.24 against an identical run's 0.07 — and held that much
of the monthly cap for the rest of the month. Seen on a live site, on a run that
stalled before its cron was in place, was swept, restarted, and published.

### Changed

- **The floor a lost worker leaves behind is now the size of the accident, not
  the size of the pipeline.** When the stall sweeper releases a claim, the worker
  holding it may have completed a paid call whose token counts never reached the
  database; nothing afterwards can discover that, so the run keeps a floor under
  what it may settle for. That floor was the run's entire reservation — two
  proposals, a body, a repair, and an image — which bounds the whole pipeline
  rather than the one step that was interrupted.

  A worker can only be inside one step, and the claim it leaves behind names
  which. The floor is now what the run has already recorded plus what that step
  alone could have cost, and never more than the reservation. A run interrupted
  during the budget check, which buys nothing, now floors nothing at all.

  Where the step cannot be identified, or its prompt has since been deleted, the
  whole reservation is still held: an over-estimate costs the site a little of a
  cap it had already set aside, and an unrecorded charge costs it the cap.

### Fixed

- **The reservation was built on a proposal ceiling that no longer existed.**
  `Budget_Guard::PROPOSAL_OUTPUT_ALLOWANCE` was 512 and documented as matching
  what the proposal step requests, which stopped being true in 1.13.1 when that
  step's ceiling went to 2048. It now reads the step's own constant, so the
  estimate bounds the call again instead of trailing it.

## [1.13.2] - 2026-08-20

### Added

- **The Run Log says when a run is waiting on a queue nobody is running.** A run
  is five short queued requests rather than one long one, so every step needs
  the queue to fire again. Where WP-Cron is doing that job it only fires on page
  loads, so a run advances while an administrator is clicking around the admin
  and stops the moment they stop — which is indistinguishable, from the Run Log,
  from a plugin that hangs.

  The Settings screen has reported the cron configuration since 0.7.0, under
  System health. Nobody goes to Settings to find out why a run says "running".
  The Run Log now carries the warning itself, and only when both halves are
  true: a run has been open for more than five minutes *and* the queue has
  finished nothing in that time. An old run on a working queue is the stall
  sweeper's business and says nothing, and a quiet queue with nothing waiting on
  it is a quiet site.

### Fixed

- **The two-connection tests no longer leave queue rows behind.** They commit,
  by design, and they cancelled the actions they had armed — but a cancelled
  action is still a row, and a step action is keyed by run ID, a number the runs
  table hands out again once those rows are deleted and the auto-increment
  counter resets. Ninety-odd stale `autoscribe_run_step` rows had accumulated in
  the development database, and any later test whose run drew a matching ID was
  told by the queue that its run was alive. It surfaced as an unrelated
  concurrency test failing at random. The harness now deletes every queue row it
  created, by watermark, the same way it already handled runs and posts.

## [1.13.1] - 2026-08-20

A scheduled run failed on a live site with "The topic proposal was not JSON."
The model had been asked for a title and a slug and answered with something
else, and that single malformed answer ended the run: the day's article was
never written.

### Fixed

- **A malformed topic proposal is now repaired rather than fatal.** Section 5.1
  allows one repair request per run on a validation failure. The body step made
  it; the proposal step did not. Nor was there a retry to fall back on — an
  unusable response is permanent as far as `Retry_Policy` is concerned, and
  rightly so, since a scheduled retry would send the identical request. So the
  proposal step now sends one repair, quoting the rejected response and the
  reason it was rejected, and one only: the allowance belongs to the run, not to
  each proposal attempt, so a collision re-ask that also comes back malformed
  does not buy a second.

- **The proposal call asks for a larger output ceiling.** It asked for 512
  tokens, which is generous for a title and a slug and not generous at all for a
  model that reasons before it answers: on the current generation that budget
  covers the model's own reasoning too, so a ceiling sized for the answer can be
  reached before the answer begins. The ceiling is a limit rather than a
  purchase — an unused token is not billed — so it is now 2048.

- **A failed proposal says what came back.** "The topic proposal was not JSON."
  was the whole of what the Run Log could tell anybody, which is not enough to
  tell a refusal from a preamble from a response cut off mid-object — and those
  want different responses from whoever reads it. The error now distinguishes
  the three and quotes the first 200 characters of what the model actually
  returned, flattened and escaped.

### Changed

- The queue dispatch tests lift Action Scheduler's runner time limit for their
  duration. The runner is a singleton that measures elapsed time from when it
  was constructed, which in a test suite is hundreds of tests before it is used,
  so it would open a batch, judge itself nearly out of time, and stop — failing
  by suite position rather than by anything the plugin did.

## [1.13.0] - 2026-08-20

Tests, not behaviour: the queue now runs the plugin in the suite, instead of the
suite doing the queue's job and taking its word for the rest.

Every pipeline test until now advanced a run by calling the handler directly, one
step at a time. That is a faithful description of what Action Scheduler does, and
it assumes everything around it: that the hooks are registered at all, that a
prompt id survives being encoded into an action row and read back, that a step
arming its successor produces an action the runner picks up, and that a finished
run leaves the next occurrence armed. A prompt that runs once and is never armed
again looks exactly like a prompt that works — and that is the defect a live site
reported in 1.10.0.

### Added

- **Action Scheduler dispatch tests.** `Queue_DispatchTest` hands a prompt to the
  real `ActionScheduler_QueueRunner` and asserts on what comes out: a published
  post carrying its run id, a run row marked successful, the next occurrence
  armed and matching what the editor is shown, no action left failed in the
  store, a provider failure that closes the chain rather than leaving it open,
  and the recurring sweep putting an unqueued prompt back in the queue.

  Each was checked by removal — unregistering the step hook, skipping the
  re-arm, and unregistering the sweep each fail the expected test for the
  expected reason.

  Two details of the harness are worth recording, because both silently make the
  tests pass while doing nothing. The plugin never arms an action for the current
  second, so a drain that asks once finds an empty queue and reports success; the
  drain waits for an action that is about to come due, and stops at anything
  further out. And the two-connection tests commit, so their actions outlive
  them; the store is emptied at the start of each dispatch test, or a count of
  what the queue completed is answered by somebody else's leftovers.

## [1.12.0] - 2026-08-20

A test harness rather than a fix: two database connections, so the guards this
plugin depends on can be tested where they mean something.

Every concurrency test until now interleaved two objects on one connection. That
proves the ordering of statements and nothing about exclusion — a compare-and-swap
only excludes a second session, and `GET_LOCK` is held by the connection, so
taking the same lock twice on one connection succeeds twice. Three of the four
findings in the twelfth review were concurrency defects a harness like this would
have caught first.

### Added

- **A two-connection test harness.** `Two_Connection_Test_Case` leaves the
  per-test transaction behind and cleans up by watermark instead, because an
  uncommitted row is invisible to the other worker and a locked one blocks it.
  `Worker` runs plugin code against a connection of its own by swapping the
  `$wpdb` global, so the code under test behaves as it would in another request.
  Eight tests cover lock exclusivity and scoping, one run per occurrence across
  two workers, step claims, payload fencing after a claim is taken, and the
  spend lock a late charge takes.

  Two of them are verified by removal — take out the guard and they fail — and
  the harness itself is verified by collapsing the two workers onto one
  connection and watching the lock test fail, which is the shape of the mistake
  it exists to prevent.

- **`autoscribe_lock_wait_seconds`**, filtering how long a worker waits for a
  lock another worker holds. A busy database may want longer; a test proving two
  workers exclude each other wants far less than ten seconds to find out.

### Fixed

- **A second connection now inherits the table properties other code registers
  on the global one.** Action Scheduler reads its table names off `$wpdb` rather
  than building them, so without this a worker's queue call failed from inside
  somebody else's store — which is exactly what the first run of the new tests
  found.

## [1.11.0] - 2026-08-20

A twelfth external review, against 1.10.0. Four findings and two smaller
corrections, all confirmed and all fixed.

The high finding is in code added the same day: recovering a prompt that had
fallen out of the queue introduced a way for two callers to queue the same
occurrence, which is a worse failure than the one it fixed. Two run rows for one
occurrence means two reservations, two sets of provider calls, and two articles —
and the claims that stop two workers spending on a run cannot see it, because
they are scoped to a run row.

### Fixed

- **Two callers could queue the same occurrence, and two actions could open two
  paid runs.** Three paths arm a prompt — saving it, concluding a run, and the
  new sweep — and each asked "is anything queued?" and then queued, which is two
  statements that both answer "no". Arming is now serialised per prompt by a
  named lock, and the guard that matters is at the point that costs money: a
  prompt with a run in flight does not open a second one, whatever dispatched the
  action.

- **A failed open-run query read as "no run in flight".** `get_var()` answers
  null both for "no such row" and for "the query did not run". The accounting
  guard learned this in 1.9.0; the scheduling code repeated it. The check is
  three-valued now, and unknown counts as running, because the cost of being
  wrong in that direction is a paid duplicate.

- **The recovery scan could exclude prompts permanently.** It selected the first
  two hundred enabled prompts on every pass, so a site with more than that had
  the rest left out of recovery for ever rather than merely delayed. It pages by
  prompt ID with a durable cursor, and wraps when it reaches the end.

- **A late charge could still invalidate a budget total after the final check.**
  1.9.0 narrowed this and its changelog entry overstated the result. Money
  arriving on a *closed* run now takes the same spend lock the budget check holds
  from its final check through the reservation, so it cannot land inside that
  window. Money arriving on an open run takes no lock and never did: its cost is
  worked out when the run closes, and the reservation is what the cap sees until
  then.

### Changed

- **The recovery scan asks two questions per page instead of two per prompt.**
  Active prompt actions and open runs are read in bulk, with the same
  database-store check and public-API fallback the run-step lookup uses.

- **A prompt that cannot be queued is reported.** The sweep discarded that error,
  and nothing else would have mentioned it: there is no run to fail and no row to
  write it on. One notice an hour, like the other operational alerts.

- **The prompt editor no longer needs JavaScript to be visible.** Every tab panel
  was hidden by stylesheet and revealed by script, so a blocked or late script
  left the configuration form blank. The hiding is now scoped to a class the
  script adds, and the fallback is every panel visible, stacked.

## [1.10.0] - 2026-08-20

Reported from a live site: a daily prompt showed controls for weekdays, days of
the month and ordinal weeks, and did not run when it said it would. Three
defects, one of which explains the second half of that.

### Fixed

- **An enabled prompt could fall out of the queue and stay out of it.** A prompt
  is armed in exactly two places — when it is saved, and when one of its runs
  concludes — and nothing ever asked the standing question of whether an enabled
  prompt was actually queued. Action Scheduler records an action killed by a PHP
  timeout as failed and does not retry it, so a request that died before its run
  row existed left no queued action, no open run, and nothing for the stall sweep
  to find: the prompt stopped, silently, until somebody opened the editor and
  pressed Update.

  The five-minute sweep now arms any enabled prompt that has nothing queued and
  no run in flight. It leaves alone anything that is queued, running, disabled, or
  whose schedule does not validate.

- **The editor promised runs that nothing was going to perform.** The "Next run"
  readout computed the next occurrence from the schedule; it never asked the queue
  whether anything was armed. A prompt that had fallen out of the queue therefore
  displayed a confident date. It now reports the queued time when there is one,
  and says "Not queued yet" when there is not.

- **Every schedule field showed for every schedule type.** A daily prompt was
  asked for a weekday, a day of the month, an ordinal week, an interval and a
  cron expression — five controls, four with no effect on it and none saying so.
  The field list has recorded which types each parameter belongs to since 0.7.0;
  nothing read it. The applicable fields now show and the rest are hidden, both as
  the page is rendered and as the control changes.

### Changed

- **The time field names the timezone rather than showing an offset.** "+00:00"
  beside a time reads as decoration; it is not. A prompt set to six o'clock on a
  site whose timezone is UTC runs at six UTC, which is one in the morning in
  Chicago. The label now says which timezone and where it is set.

## [1.9.0] - 2026-08-20

An eleventh external review, against 1.8.0. Four findings, all confirmed and all
fixed, plus three smaller corrections the review noted.

Three of the four are the same class of mistake as the round before: a rule
applied to the case that prompted it and not to the neighbouring one. The price
came from one read except for the floor, which was read again. Failure was
handled for writes but not for the reads that decide whether a run may spend.
Repair covered the rows a cap sums, but only until the moment the sum was taken.

### Fixed

- **A cost floor raised mid-price could be settled under.** The floor is what a
  run must pay when a worker died inside a paid call. It was read with the
  counters and then read again a statement later, so a claim released in between
  raised it after the price was worked out — and the close wrote a figure below
  it and cleared the row. The floor now comes from the same read as everything
  else; releasing a claim raises `usage_revision`, so a close holding an older
  price marks the row; and every terminal statement marks a row whose floor is
  above the cost being written.

- **A database read failure could authorise a paid run.** An unreadable
  stale-row query looked like an empty backlog and an unreadable `SUM()` looked
  like a month with no spending — the most permissive possible reading of a
  fault, arrived at silently. Both are now three-valued: yes, no, or unreadable.
  The budget guard refuses on the third, as it already did for a repair it could
  not complete, and `confirm_reservation()` refuses too.

- **A charge could become stale between the repair and the sum.** The spend lock
  serialises budget checks against each other, not against the workers that
  record late charges — those take no lock on purpose, because a provider that
  answered has charged whoever asked. The guard now requires its total to have
  been taken while nothing was outstanding, checks that afterwards rather than
  assuming it, and repairs and sums again if something arrived in between.
  Refusal, not authorisation, is what happens if the two never agree.

  *Corrected in 1.11.0:* this narrowed the race rather than closing it. A charge
  arriving after the final check could still land before the reservation. See the
  1.11.0 entry.

- **The migration could make no progress and repeat for ever.** Its cursor was a
  local variable, so every request restarted at zero, and a request only records
  the schema version when the migration finishes — so a table with more
  candidates than one request inspects read the same rows on every request and
  never reached a real legacy key beyond them. The cursor is remembered between
  requests now, and the candidate query matches the encoded key rather than any
  occurrence of the words, so a title or a source URL containing them is not a
  candidate at all.

### Changed

- **The build's provenance check is derived from what is packaged.** It compares
  the staged tree with `HEAD` after staging, rather than testing a hand-kept list
  of paths beforehand — so an uncommitted note the archive excludes cannot refuse
  a build, and any packaged file that differs from the commit does.

- **The 1.8.0 entry said `usage_revision` was added in 1.8.0.** The column
  arrived in 1.7.0; 1.8.0 bumped the schema version so a failed migration would
  be retried. The entry is corrected.

- The uninstaller removes the sweep and migration cursor options.

## [1.8.0] - 2026-08-20

A tenth external review, against 1.7.0. Four findings, all confirmed and all
fixed.

The first of them is the invariant from 1.7.0 not actually holding: a price and
the revision that certifies it were read by two separate statements, so a charge
landing between them got a price computed without it and a revision that said the
price was current. The comment in that code argued the ordering made it safe. It
made it certain.

MINOR rather than PATCH: the budget guard's refusal is narrowed to the cap it
protects, the grounded-call migration changes shape, and the Run Log shows a new
state.

### Fixed

- **A charge landing inside the measurement could certify itself.** The counters
  were read in one query and `usage_revision` in another. Everything a price is
  made of — status, counters, grounded count, floor, and revision — now comes
  from a single read, so the revision belongs to the figure rather than merely
  accompanying it.

- **A close whose measurement failed reported a settled run.** A price worked out
  from a read that did not happen is not a price. Both terminal statements now
  mark the row when the measurement could not be taken, so a repair pass prices
  it rather than the row being closed on a figure nothing stands behind.

- **The unclaimed close marked the row in a second, unchecked statement.** It is
  one prepared statement now, like the claimed close, so a process stopping
  between the two can no longer close a run and lose the record that its price
  was short.

- **The grounded-call migration did not raise the revision.** It changes a
  counter that costs money, so a run being closed while it ran could be priced
  without the surcharge and closed as settled.

- **The migration could report both false success and false failure.** A failed
  read looked like an empty table, so an install could record a completed
  migration it never performed; and a payload that merely contained the text
  `grounded_calls` was re-read on every page and every later request, putting a
  scan and a `dbDelta()` pass on every request the site served. It pages by ID
  cursor now, treats a query error as a failure, decides completion from decoded
  keys, and confirms the new column exists before recording the version.

- **One irrelevant unpriced row could stop every future run.** The budget guard
  repaired the whole table before reading whether any cap was enabled, so a
  damaged row from another prompt or an earlier month could refuse a run that no
  cap applied to. It now reads the caps first, returns early when none is set,
  and scopes repair to exactly the rows the enabled cap sums.

- **The Run Log shows "Accounting pending"** on a run whose recorded charge has
  not been priced yet, which is what the refusal message says to look for.

- **The 1.7.0 changelog entry was dated a day early**, and its published archive
  was built before that entry's final edit. The runtime code in the published
  1.7.0 archive matches its tag; only `CHANGELOG.md` differs. The build now
  refuses to run against a working copy with uncommitted changes to packaged
  files, so an archive cannot be built from a tree that is not the one committed.

### Changed

- The schema version is bumped so that an install which recorded version 7 after
  a failed read retries the grounded-call move. No column is added: the
  `usage_revision` column arrived in 1.7.0.

## [1.7.0] - 2026-08-20

A ninth external review, against 1.6.0. Four findings, all confirmed and all
fixed.

Three of them are the same boundary examined from three sides: money recorded at
a moment when nothing was looking. 1.6.0 marked a closed run whose charge arrived
late; this covers the charge that arrives while the run is closing, the backlog
that one repair batch cannot clear, and the upgrade that treated two counts from
different periods as two copies of one.

MINOR rather than PATCH: the runs table gains a column, the budget guard can now
refuse to authorise a run, and the grounded-call migration is replaced.

### Fixed

- **A charge landing while a run was being closed escaped the accounting.** The
  closing worker measures the cost, another worker's call returns and records its
  tokens — the row is still open at that instant, so nothing marks it — and the
  close then writes the figure measured before those tokens existed. The money
  was on the row and nothing knew the price was short.

  Every write that records money now raises a `usage_revision`, and every
  terminal transition carries the revision its figure was priced from. A run
  whose counters moved in between is marked by the close itself, and the repair
  passes price it.

- **The budget check could authorise a run against a total it knew was short.**
  It repaired one batch of twenty-five unpriced runs and then summed, so a
  twenty-sixth stayed out of the figure the cap was compared against. It now
  drains the whole backlog in bounded pages before summing, and refuses the run
  outright if it cannot: a cap that cannot be worked out stops spending rather
  than passing it.

- **A reconciliation that changed nothing reported success.** A charge landing
  while the price was being worked out makes the compare-and-swap match no rows,
  which means the figure is already out of date — the row stays marked, and the
  caller is now told so rather than being told the books balance.

- **The grounded-call migration collapsed two counts into one.** The payload
  holds calls made before 1.5.0 and the column holds calls made after, which are
  different periods rather than two copies of one number. The migration adds them
  and removes the legacy key in the same conditional write, so it is safe to
  repeat; it covers closed runs as well, flagging them for repricing, and it
  pages until nothing is left rather than recording a schema version for a
  migration that did not finish.

- **The README promised a repair deadline the queue cannot keep.** "At most five
  minutes" is the sweep's schedule, not a bound on when Action Scheduler runs it.
  The text now says what is actually guaranteed — nothing spends until the total
  is complete — describes the sweep as best effort, and says where to find a
  past-due sweep action.

- **The build could package a stray archive inside the plugin.** `.gitignore`
  hides a zip in the working copy from `git status`, and `.distignore` did not
  exclude one, so a copy of the previous release found its way into the staged
  plugin and doubled the download. It was caught before shipping by the size
  looking wrong. Archives are excluded now, and the build refuses to continue if
  one is staged anyway.

### Added

- A `usage_revision` column on the runs table, migrated by the existing schema
  version check.

## [1.6.0] - 2026-08-19

An eighth external review, against 1.5.0. Three findings, all confirmed and all
fixed.

The theme is durability rather than correctness this time: 1.5.0 made late
provider charges reach the monthly cap, and this makes them reach it even when
the process recording them does not survive to the end.

MINOR rather than PATCH: the runs table gains a column and an index, and the
schema migration now moves data rather than only adding columns.

### Fixed

- **A charge recorded on a closed run could stay unpriced for ever.** Recording
  money and pricing it are two statements — the rate table is PHP, not SQL — so a
  process that died between them left the tokens on the row and the settled cost
  behind them. The monthly cap sums the settled cost, so the spending was
  invisible to it.

  The statement that records money now also marks a closed row as owing a price,
  which makes the gap recoverable rather than silent. Reconciliation clears the
  mark as a compare-and-swap on the counters it measured, so an increment landing
  mid-measurement leaves the row marked rather than half-priced. Two passes come
  back for rows nobody else did: the budget guard, which repairs before it sums
  and while it holds the spend lock, so a run cannot pass a cap on a total known
  to be short; and the stall sweep, so a site that has stopped generating still
  settles its books.

- **A run that crossed the 1.5.0 upgrade could lose one search charge.** The
  grounded-call count moved from the payload to a column, and the two were
  compared rather than added: a run with one legacy call that made one more had
  the column go from zero to one, and the larger of one and one is one. The
  migration copies the legacy count into the column for runs still in flight, so
  every later increment adds to a count that already includes it. Settled runs
  are left alone, because their money was accounted for under the old reading.

- **The README's stale-worker paragraph contradicted itself.** It said every
  write from such a worker was refused and then that the money counters were the
  exception, and it reached the same conclusion twice. It is now written once, as
  the rule it describes: state writes are refused, money writes are accepted, and
  a late charge is priced by the next repair pass.

### Added

- A `cost_stale` column and its index on the runs table, migrated by the existing
  schema version check.

## [1.5.0] - 2026-08-19

A seventh external review, against 1.4.0 — the first to come back a conditional
pass. Three findings, all confirmed and all fixed.

The one that matters is the other half of a decision made in 1.3.0. Usage
counters are deliberately unfenced, because a provider that answered has charged
for the answer whoever asked; what nothing did was carry that money through to
the figure the monthly cap actually reads. The spending reached the run log and
stopped there.

MINOR rather than PATCH: the runs table gains a column and a closed run's settled
cost can now move.

### Fixed

- **Usage recorded after a run closed did not reach the monthly cap.** The cap
  sums `cost_cents`, which a closed run computed before the late counters
  existed. A worker returning after its run was given up on could record its
  tokens or its image and change nothing the cap could see — two pictures billed,
  one counted.

  Every usage increment is now followed by a reconciliation: on an open run it
  matches nothing, and on a closed one it re-measures the row from the rates the
  run recorded and raises `cost_cents` with `GREATEST`, so two late increments
  cannot lose each other and the figure can only move up.

- **A grounded call made after a run closed could not be recorded at all.** The
  search surcharge lived in the payload document, which is fenced by the claim
  and by the run being open — correct for state, wrong for money. It is a column
  and an atomic increment now, like the token and image counters, and it is
  priced into the same reconciliation.

- **The README and the sixth response overstated the fence.** Both said a closed
  run could not be written to at all, which was never true of the usage counters
  and was true of nothing else once they were exempt. Both now say what the code
  does: state writes are fenced, money is accepted from any worker at any time,
  and a late charge raises the closed run's cost.

- **The preview-threshold constant's comment contradicted the code.** It said the
  queued-run threshold was not used for previews; `preview_threshold()` takes the
  larger of the two. The comment now describes the rule the code implements.

### Changed

- **The final-publication regression test reaches the guard it is named for.**
  It previously stopped at finalisation's own claim, so the ownership re-check
  immediately before the post's status transition was covered by nothing. The
  test now closes the run in the gap between the claim and that check, and fails
  with the post published when the check is removed.

### Added

- A `grounded_calls` column on the runs table, migrated by the existing schema
  version check. The 1.4.x payload value is still read as a floor.

## [1.4.0] - 2026-08-19

A sixth external review, against 1.3.0. Four findings, all confirmed and all
fixed. The reasoning for each is in `CODEX-REVIEW-RESPONSE.md`.

The high finding is the same mistake as the two before it, and worth naming
plainly: a guard that was correct as far as it was taken and was not taken far
enough. Ownership of a step was defined as the row and the claim token, and the
missing third of it — the run still being open — is what let a worker keep
writing to a run that recovery had already closed.

MINOR rather than PATCH: previews recover on their own threshold, prompt
validation observes a new hook, and the queue's bulk read now depends on which
store is active.

### Fixed

- **A worker whose run had been closed under it could still change the closed
  row, and could still publish.** A terminal sweep closes a run *at* the claim it
  observed and leaves the marker in place, so the worker it closed found its
  token unchanged and believed it still owned the step. It could write the
  article identity, the post link, the cost, the payload, and the completed step
  to a row the run log had already reported as failed — and from finalisation it
  could transition the post to published, putting an article on the site for a
  run that had failed.

  Ownership is now one predicate — the row, the run still running, and the claim
  token — carried by every claimed write and by both claim questions, which ask
  it in a single query so two reads cannot disagree. Finalisation re-asks it
  immediately before the post's status transition. A worker that closed the run
  itself is not treated as having lost it: ending a run is not losing it, and its
  own error is still the run's real outcome.

- **A preview could be reported as failed while the person was still waiting for
  it.** A preview runs synchronously and never has a queued action, so age is its
  only liveness signal — and the queued-run threshold, which a filter can lower
  to two minutes, is well inside what one preview can legitimately take. Previews
  now recover on their own threshold of thirty minutes, which a site can raise but
  cannot lower below the queued-run threshold.

- **Deleting a prompt's fallback image left fallback mode with nothing to fall
  back to.** The validator watched added and updated meta and not deleted meta,
  which is the write that produces the exact state the rule exists to prevent. It
  observes `deleted_post_meta` now.

- **The queue's bulk read checked that the standard table exists, not that the
  active store uses it.** A site running the legacy post store, or one of its own,
  can leave the table behind — and reading it would report an empty active set.
  The store is asked what it is. The hybrid store counts, because it is a
  migration wrapper whose destination is the database store and is what a stock
  install runs while it migrates.

### Added

- `autoscribe_preview_stall_threshold`, filtering how long a preview may run
  before the sweep treats it as abandoned.

## [1.3.0] - 2026-08-19

A fifth external review, against 1.2.0. Six findings, all confirmed and all
fixed. The reasoning for each is in `CODEX-REVIEW-RESPONSE.md`.

Two of them are the same shape as the previous round's: a guard that was correct
where it was applied and was not applied everywhere it was needed. The cost floor
protected the run nobody minds losing and not the restart everybody wants to
succeed; the worker claim fenced two writes while the comments described it as
ownership of the whole step.

MINOR rather than PATCH: the runs table gains a column, previews record what they
are, `Run` and `Taxonomy_Applier` change shape, and cross-field prompt validation
now applies to writes that never touched the editor.

### Fixed

- **A paid call could still disappear when the run that made it was restarted.**
  A worker killed inside a provider call has been charged for it and has recorded
  nothing. Version 1.2.0 kept the reservation as a floor when a sweep gave up on
  such a run, but not when the sweep restarted it — and a successful restart
  settles from what the replacement spent, so the first call left the monthly
  total entirely.

  Releasing an interrupted claim now raises a `cost_floor` on the run in the same
  conditional statement, and every settlement afterwards — success, failure, or
  skip — is held at or above it. A run that stalled *between* steps has nothing
  outstanding and still gives its reservation back in full.

- **A superseded worker could still change almost everything.** The claim fenced
  payload and position writes. The article identity, the post link, the settled
  cost, the terminal transition, the post itself, its terms, and its featured
  image were all written unconditionally, so a worker that had been swept and
  replaced could overwrite or close its replacement's run.

  Every run-row write a claimed step makes is now conditional on that claim,
  including the skip and failure transitions a step performs itself. The two
  places that write to WordPress rather than to the run row — post assembly and
  image attachment — re-ask for the claim immediately after their provider call
  and before their first side effect. That narrows the window rather than closing
  it, which is stated where it matters rather than implied.

- **Token and image counters are incremented by the database rather than
  overwritten.** They were read, added to in PHP, and written back whole, so two
  workers on one run each wrote a total computed before the other's and one
  call's spending vanished. Two images bought for one run were also recorded as
  one. Usage is deliberately the one write a lost claim does not stop: a provider
  that answered has charged for it whoever asked.

- **A post could be published without the taxonomy its prompt names.**
  `wp_set_post_terms()` returns the term IDs it meant to write without inspecting
  the insert that writes them, and silently skips a term ID that no longer
  exists — so a refused relationship and a deleted category both looked exactly
  like success. The terms are now read back off the post and compared with what
  was asked for.

- **An abandoned preview was recovered as though it were an article.** A preview
  makes paid calls and creates no post. The stall sweep did not distinguish one,
  so it scheduled the ordinary step handler, which found no step left to take,
  treated the run as ready to publish, and concluded the *prompt*: a failure
  notice, a retry decision, and a re-armed schedule, for a button somebody
  pressed once. Previews now record what kind of run they are, the sweep closes
  them and nothing else, and the queued handler refuses to finish one.

- **Preview also settles at the rates it was checked against.** It opened its run
  row by hand and so had none of the models and rates snapshot 1.2.0 gave every
  other run. It now opens through the same code, and reports a refused close
  rather than discarding it.

### Changed

- **One sweep reads the queue's active actions once.** The statement cannot
  filter by run — Action Scheduler stores the arguments as JSON — so calling it
  once per page read the same rows again for every page, up to twenty times a
  sweep on exactly the busy sites the paging exists for. It is also joined to the
  plugin's own action group now.

- **Cross-field prompt validation is shared rather than living in the editor.**
  The rules that refuse grounding for a provider without a search tool, and
  fallback image mode without an image, sat behind the editor's nonce check — so
  they applied to one of the several ways prompt meta is written. The 1.2.0
  response claimed otherwise; `Prompt_Validator` is that claim made true. It runs
  on save and at the end of any request that writes one of the keys it reads,
  which is late enough that a writer setting its fields one at a time is not
  fought half way through.

### Added

- A `cost_floor` column on the runs table, migrated by the existing schema
  version check.

## [1.2.0] - 2026-08-19

A fourth external review, against 1.1.3. Eight findings: seven confirmed and one
rejected on evidence retrieved from the provider's own documentation on the day
of the review. The reasoning for each is in `CODEX-REVIEW-RESPONSE.md`.

The theme is the one the previous round started and did not finish: a write whose
result nobody consumed. Closing a run answered a Boolean, and the two ways of
answering false — somebody else closed it, and the database refused the write —
are opposite situations that were being treated as one. Everything below follows
from separating them.

MINOR rather than PATCH: the runs table gains a column, `Run` and the SEO adapter
contract change shape, and fallback image mode now refuses a configuration it
used to accept.

### Fixed

- **A refused terminal write behaved like a completed run.** The queue mailed the
  failure, armed the next occurrence, and left the run open — so when the stall
  sweep later closed the row properly, both happened again. Worse, a run left open
  that way loses whatever usage was held only in memory, so a paid call could
  vanish from the month-to-date total the section 7.4 cap reads.

  Closing now answers one of three things: this caller closed it, somebody else
  had already closed it, or the write failed. Every ending in the queue driver
  passes that answer to the one place that decides what happens next. A lost race
  stands down silently; a refused write reports an operational fault, leaves the
  run recoverable, and announces nothing.

- **A run stopped mid-step could settle below what it had spent.** A worker killed
  during a paid call may have been charged for work no counter records. Settling
  such a run from its counters alone writes a figure known to be incomplete. The
  reservation is now kept as a floor in that case only — where a claim was
  interrupted, or where the failure being closed is an unrecorded charge. A run
  that stalled between steps still gives its reservation back in full.

- **Two stall sweeps could restart the same run, and either could erase a
  worker's output.** The restart count lived in the payload document — the same
  JSON every step reads whole and writes whole — so counting a restart carried a
  stale copy of that document back over whatever a worker had recorded in
  between: a topic, an article, its sources, or an image outcome. The next step
  then repeated a paid call or failed on state that was no longer there.

  The count now has its own column and is incremented conditionally, which makes
  it the sweeper's claim as well as its counter. Payload writes and the position
  write are conditional on the claim the worker holds, so a worker that has been
  swept and replaced can no longer write over its replacement — it stands down
  instead, without closing a run that now belongs to somebody else.

- **Fallback image mode could publish with no image at all.** Section 6 defines
  the mode as a promise that there is always a picture. If the fallback ID was
  zero, named a deleted attachment, or WordPress refused the thumbnail write, the
  run published without one — silently becoming optional mode in exactly the case
  the mode was chosen for. An unattachable fallback now fails the run and leaves
  the draft for a person, and the prompt editor refuses to store fallback mode
  unless the ID names an image in the media library.

- **A post could be published with no link to the run that produced it.** Section
  10's `_autoscribe_run_id`, the deduplication topic key, the SEO metadata, the
  categories, the tags, and the run log's own copy of the title were all written
  and none of the results inspected. Every one of them is now read back or
  checked, and a post that cannot carry them stays a draft rather than being
  published incomplete while the run reports success.

- **Finalisation had no claim.** It was the only part of a run that did not, so
  two queued actions could both transition the post and both write a settled cost
  before one of them lost the close race. Nothing was charged twice, but every
  plugin listening for a publish ran twice. It now claims the run's position like
  any step.

### Changed

- **A run fixes its models and rates when it opens, and uses them throughout.** A
  blank model field resolves through the adapter's suggestion list, which is code
  rather than configuration, so a plugin upgrade could change the model a run in
  flight was using — the topic proposed by one model and the article written by
  another. The pricing table could likewise be edited between the budget check and
  the settlement, changing what an open reservation gave back. Both are now
  recorded on the run at the moment it opens; an edit applies to the next run.

- **The Google adapter's model list carries the date its catalog was checked.**
  The order is unchanged: `gemini-3.7-flash` is still first, confirmed against
  Google's model catalog and migration guide on 19 August 2026, both of which name
  it as the current stable Flash model and the migration target. The docblock now
  records where that came from and when, so the next person to touch the list
  knows what to re-check.

- **Documentation corrections.** The installation link named the 1.0.0 zip;
  `DECISIONS.md` still claimed one provider call per queued request, which the
  README and the pipeline document had already corrected; the README's list of
  knowingly unmet requirements was incomplete; and a code comment pointed at the
  README for a grounding warning that lives in `INSTRUCTIONS.md`. The README now
  carries that warning itself.

### Added

- A `sweeps` column on the runs table, migrated by the existing schema version
  check. A run opened by 1.1.x and still in flight keeps its count, which is read
  from the payload as a floor.

## [1.1.3] - 2026-08-19

One fix, against the guard 1.1.2 introduced.

### Fixed

- **A run the sweeper had already recovered once could never be closed, and held
  its budget reservation for ever.** "Nothing has completed yet" has two
  spellings in the run log: a run that has never advanced stores SQL NULL, and
  one whose first-step claim was released stores an empty string, because that is
  what its completed position is. The conditional close added in 1.1.2 treated an
  observed empty position as NULL, so it matched neither.

  A run recovered at its first step therefore became unclosable. Every later
  sweep read the same position, refused the write, and left the run `running` —
  and its reserved cost counted against the monthly cap indefinitely, so a
  handful of them could stop the site generating anything at all. The sweeper's
  own recovery was what made the run unrecoverable.

  The close now compares with `COALESCE( step, '' )`, matching the claim it is
  paired with, so both spellings resolve to the same position.

## [1.1.2] - 2026-08-19

One more finding against the concurrency guard 1.1.1 introduced, and the last of
five corrective rounds on it. Nothing else changed.

### Fixed

- **A stall sweep could close a run while a worker was performing a paid step.**
  Re-asking the queue before giving up narrowed the window without closing it:
  another sweep can record the final restart, this one can see the new count and
  find no action queued, and that restart can be armed and claimed before this
  one writes. Closing the run then cancelled a provider call already in flight.

  The terminal write is now tied to the position the sweep observed, rather than
  to a separate queue read taken beside it. A worker that has claimed the step
  wins; one that has not claimed yet finds the run closed and stands down without
  spending anything. Both outcomes are safe, which a check standing next to a
  write could not guarantee however narrow the gap between them looked.

## [1.1.1] - 2026-08-19

A third external review, against 1.1.0. Ten findings; nine confirmed and one
rejected on evidence. The reasoning for each is in `CODEX-REVIEW-RESPONSE.md`.

Two of these are financial-control defects that pre-date the pipeline split, and
one of them is the same shape as several the split already fixed: a write whose
result nobody consumed. The audit that found those was scoped to methods that
already returned a value, so the ones still returning `void` were never in it.

### Fixed

- **A provider call that was charged for could vanish from the monthly total.**
  Recording token and image usage returned nothing, so a refused write left the
  step to finish and the next queued action to load a fresh run and read the row.
  The charge was real; the record of it was not. Both writes now report, and a
  step that cannot store what it just spent stops the run — the object that made
  the call is the object that settles it, so the charge is still booked.
- **A run could publish, fail to close, and announce itself anyway.** The review
  email went out and the next occurrence was armed off a transition that had not
  happened, and the stall sweeper would later find the still-open row and do both
  again. Every ending is now one conditional update that only an open run
  accepts, and nothing is announced until it succeeds.
- **Two workers could both perform the same step.** The per-step guards are reads
  — two workers could both find no stored article and both buy one. A
  compare-and-swap on the run's position now decides between them before either
  spends.

  Two defects in that guard were found before release and are fixed with it. A
  worker that lost the claim reported it the same way as a finished sequence, so
  instead of standing down it finished the run — closing a run with no article
  early on, or publishing before the winner had attached the image. And a claim
  left behind by a killed worker could never be taken again, because the next
  worker read the position with the claim marker stripped and asked for a value
  the column no longer held; the stall sweeper releases an abandoned claim before
  restarting, which it can do safely because it has already established that
  nothing is advancing the run.
- **A refused featured-image write was a fatal error, not a handled one.** The
  error was built and then overwritten by the attachment ID a line later, so the
  code below it called a method on an integer — `required` mode could not fail,
  `fallback` could not fall back, and `optional` could not shrug. All three
  crashed instead. Introduced by the featured-image verification above and caught
  before release.
- **Two new guards were cancelling each other out.** Putting force review into
  the abort fingerprint stopped a run whenever the switch moved in either
  direction, which meant the rule that keeps the stricter of the opening and
  closing settings could never be reached — and tightening a safety catch would
  have killed the run it was meant to protect. Force review is governed by that
  rule alone; the fingerprint covers the settings where continuing under a
  changed value is simply wrong.
- **A concurrent sweep could free a live worker's claim.** Two sweeps overlap;
  the first releases an abandoned claim and arms a restart; the restart takes the
  step; and the second, still acting on what it saw, releases that live claim and
  lets a third worker perform the same paid step beside it. It matched because a
  released and retaken claim produced an identical marker. Claims now carry a
  token, and the release names the claim the sweeper saw when it judged the run
  idle rather than reading whatever is there when the update lands — so a claim
  taken since carries a different token and the update matches nothing. Checking
  and then re-reading, which an earlier attempt did, leaves a window between the
  two however narrow it looks.
- **A run at its restart limit could be closed while a worker was on it.** The
  limit was evaluated against the candidate scan, which can be many pages old by
  then: another sweep may have counted the restart that reaches the limit, armed
  it, and left a worker part-way through a paid call. Activity is re-asked before
  anything terminal now, not only before a release.
- **A release the database refused still spent one of the run's restarts.** The
  restart it armed was guaranteed to lose an unchanged claim, so two such
  failures gave up on a run that was recoverable. A refused release is now
  distinguished from having nothing to release, and leaves the run for the next
  sweep.
- **Losing the race to close a run was reported as a failure.** The winner had
  already sent the review mail and armed the next occurrence, and the loser's
  error then had the handler send a failure notice and re-arm on top — the
  duplicate announcement the check was added to prevent, arriving by the other
  door. It is now its own outcome and the loser stands down.
- **A site default model changed mid-run applied to the rest of that run**, so a
  run could finish under a model its budget was never checked for. The site
  defaults a run depends on are part of what it was checked against.
- **Force review could be switched off under a run already in progress**, and the
  article it began under review would publish. An open run now keeps the stricter
  of the setting it started under and the setting at the end: turning review on
  mid-run still takes effect, turning it off never applies to work already under
  way.
- **A featured image could be reported as attached when it was not.**
  `set_post_thumbnail()` returns false both when it fails and when the post
  already carries that thumbnail, so its result cannot tell a refusal from a
  no-op. The post is asked what its thumbnail is instead — which is what section
  6's `required` mode is about, since a run reporting success without a featured
  image has published the thing that mode exists to prevent. Attachment metadata
  is verified the same way.
- **The stall sweep made one queue query per candidate run** — up to two thousand
  round trips against the queue it exists to watch. One query per page now.
- **The monthly budget warning could be sent twice** by two runs finishing
  together. The month is claimed with an insert, which only one caller can win.

### Changed

- Release documentation no longer claims that each queued request makes one
  provider call. It does not: the topic step asks again after a collision and the
  article step makes one repair call, so a step can make two calls at up to 120
  seconds each. What the split buys is that a killed request costs a step rather
  than an article, and that the sweeper restarts what was killed.
- Release documentation no longer says "Run now" answers in the request that
  asked. It queues, and always has — `DECISIONS.md` D-19 records why. Preview is
  the one that answers synchronously.
- The README version table said 1.0.0. It had been wrong since 1.0.1.

## [1.1.0] - 2026-08-19

**The generation pipeline is split across queued requests.** Section 5 of the
project brief asked for this and 1.0.x did not do it; both external audits raised
it, and it was the last requirement of substance left unmet. The work is scoped
in `docs/PIPELINE-SPLIT.md`, which also records what each phase found.

**What changes for a site.** A scheduled run is advanced one step per queued
request rather than running end to end in one. Each request now carries at most
far less work, so a host that cuts requests off early costs a step rather than a
whole article. The cost is wall-clock time: a generated article arrives some
minutes after its scheduled time rather than seconds after it, and a site whose
queue only advances on visits needs the system cron described in the README more
than it did before. Preview still answers in the request that asked for it; Run
now queues, as it always has.

**Upgrade note.** Runs in flight when the plugin is upgraded finish under the old
behaviour; nothing needs migrating. The runs table is unchanged.

### Fixed

- **A second writer to `runs.payload` would have destroyed the first one's
  data.** `Run::record_sources()` encoded a fresh single-key object over
  whatever the column held — correct while it was the only writer, and silently
  destructive the moment it was not. Section 5 makes a second writer the design
  rather than an accident, so every payload write now goes through
  `Run::merge_payload()`, which merges at the top level. The grounding sources
  recorded under section 7.1 were the data that would have been lost.

- **A grounded call the run could not record was not charged for.** The marker's
  own failure path undid its purpose: the grounded response had arrived and been
  paid for, the write remembering it was refused, and settlement then read back a
  zero and dropped the surcharge. The run now remembers the call in memory
  whether or not the write lands — a refused write does not un-make a request the
  provider has already answered — and the action that fails the run is the action
  that made it.
- **Abandoning a run left the prompt's attempt counter raised.** The counter
  lives on the prompt because a retry opens a new run and the count has to
  survive across rows. Every terminal path clears it except the two that abandon
  a run when the prompt is gone or switched off, so a prompt disabled part-way
  through a retry series and later switched back on resumed mid-series and
  quietly got fewer attempts than it should. The cleanup is shared with the
  prompt's own lifecycle, because the ordinary way a prompt is switched off is
  while nothing is executing — the only queued action is a pending retry, saving
  the prompt cancels it, and no queue callback runs at all.
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

- **Recovery for runs the queue stopped advancing.** Splitting the pipeline did
  not give this on its own: Action Scheduler records a killed action as failed
  and stops, so a step killed by a PHP timeout left a run open with nothing
  queued to advance it and nothing that would ever pick it up. A recurring sweep
  restarts such a run twice and then gives up on it.

  Whether a run is stalled is decided by whether anything is queued or running to
  advance it, not by how long it has been open — age cannot tell a stalled run
  from a slow one, and a legitimate run takes several queue passes. Age is used
  only to keep the sweeper away from runs too young to judge. Fifteen minutes by
  default, filterable through `autoscribe_stall_threshold`.

  The scan pages past healthy runs rather than reading a fixed number of the
  oldest: a busy queue can hold more healthy open runs than one batch, and
  reading only the oldest would re-read the same healthy rows every sweep and
  never reach a newer stalled one — leaving it holding its reservation for as
  long as the backlog lasted. Where one sweep stopped reading is remembered, so a
  backlog wider than a single sweep does not hide the runs beyond it either. A
  prompt that has been switched off is treated like one that has been deleted, so
  giving up on its run does not arm it again and leave a disabled prompt showing
  a next-run time.

- **Giving up on a run releases what it reserved.** This is the point of the
  sweep rather than a detail of it. The estimated cost is written onto a run
  before its first paid call so that concurrent runs can see it, and the section
  7.4 cap reads every open run's reservation — so a run abandoned mid-flight held
  its estimate against the monthly cap for ever. The cap would fill with money
  nobody spent and prompts would start skipping for no visible reason. **That
  failure mode did not exist before the split**; it is the debt the split took
  on.

### Changed

- **A scheduled run is advanced one queued action per step.** Section 5 asks for
  this because a run takes 30 to 120 seconds and a host with a short
  `max_execution_time` terminates it part-way. What a kill costs is now one step
  rather than an article — though not one provider call: the topic step asks
  again after a collision and the article step makes one repair call, so a single
  step can make two.

  The new `autoscribe_run_step` hook carries a run ID and nothing else, and the
  position is read from `runs.step` — one hook rather than one per step, so the
  queue never holds routing the run row could contradict. "Run now" and Preview
  keep their existing behaviour — Preview answers in its own request, Run now
  queues — and both drivers advance the same sequence.

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

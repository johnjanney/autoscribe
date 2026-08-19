# Scope — splitting the generation pipeline across queue steps

**Answers:** FR-05 / AS-02, the last unmet requirement of section 5 of the brief.
**Status:** Complete, shipped as 1.1.0. Every phase is done; the ✅ notes in §6
record what each one actually found, which was not always what it expected to.
The four ❓ decisions in §9 were answered — see the notes there.
**Written against:** `697d754`, version 1.0.5.

---

## 1. The finding, and what it actually asks for

Section 5 of the brief says to split the run into separate Action Scheduler
steps, because "this is the difference between code that works on localhost and
code that works on shared hosting". Today one queued action runs the whole
pipeline: up to four text calls at a 120-second timeout each, an image
generation call, a bounded image download, attachment metadata generation, and
two post writes. A host with a 30-second `max_execution_time` kills it part-way.

Both audits confirmed it. It has been deferred twice as "a rewrite, not a patch".
That was the right call to make quickly, but it turns out to have overstated the
work — see the next section.

---

## 2. What already exists (this is smaller than it looks)

**The steps are already separate classes**, with clean signatures, no shared
mutable state beyond the `Run` row, and each returning a value or a `WP_Error`:

| Class | Input | Output |
|---|---|---|
| `Step_Budget_Check` | prompt, run | `true` \| error |
| `Step_Propose_Topic` | prompt, run, adopted post id | `{title, topic_key}` \| error |
| `Step_Generate_Body` | prompt, run, topic | `Article` \| error |
| `Step_Assemble_Post` | prompt, article, run | post id \| error |
| `Step_Generate_Image` | prompt, article, run | `Image_Result` \| error |

`Generator::run()` is a sequencer over those five, plus a finalise tail (status
transition, cost settlement, review email). `Queued_Run_Handler::handle()` wraps
that with retry and re-arm.

So this is **not** a decomposition. It is replacing one sequencer with a
queue-driven one, and the step classes should come through essentially untouched.
That matters for a second reason: Preview (`Actions::preview()`) calls three of
these steps synchronously and must keep doing so, because it is an admin request
that has to return an article to the screen. Any design that rewrites the steps
themselves breaks Preview or forces it to duplicate them.

**Also already present:** the `runs.payload` LONGTEXT column, reserved by section
3.2 for exactly this; `runs.step`, already recording the last completed step; and
`Run::STATUS_RUNNING`.

---

## 3. What is actually missing

Five things, in rough order of difficulty:

1. **A merged payload document.** The column exists but is currently *owned* by
   `record_sources()`, which writes `{"sources": [...]}` over the whole column.
   Step state has to merge into that document rather than clobber it.
2. **A dispatcher and per-step hooks**, so each step is its own queued action.
3. **Idempotency guards keyed by run ID**, so a step that already ran does not
   run again. Section 5 asks for this explicitly. Nothing implements it today
   because nothing needed it.
4. **A resume path for killed steps.** See §5.3 — this is the part most likely
   to be assumed rather than built.
5. **A retry model that reuses the run row.** Today a retry opens a *new* row
   (recorded as decision D-10, whose stated reason is precisely that steps are
   not idempotent). Step-level resume inverts that. Knock-on effects in §6.

---

## 4. Proposed design

### 4.1 Queue topology

One hook per step, each action carrying only `run_id`:

```
autoscribe_run_prompt      (existing; becomes "open the run and dispatch")
  └─ autoscribe_run_step   args: { run_id }
```

A single `autoscribe_run_step` hook with the step name read from `runs.step` is
preferable to six hooks. It keeps `Scheduler::cancel()` able to clear a prompt's
whole chain with one `as_unschedule_all_actions()` call, and it keeps the Run Log
readable. The dispatcher decides what runs next from the row, so the queue never
holds stale routing.

Each step, on success: write its output to the payload, set `runs.step`, enqueue
the next action for *now*. Action Scheduler will run it in the next queue pass —
typically within a minute of real time, which is the cost of this change (§8).

### 4.2 The payload document

```json
{
  "sources":   ["https://..."],
  "topic":     { "title": "...", "topic_key": "..." },
  "article":   { "title": "...", "topic_key": "...", "excerpt": "...", "content_html": "...", ... },
  "image":     { "attachment_id": 123 },
  "status_override": null
}
```

`Article` needs `to_array()` / `from_array()`. It is already a value object over
a `fields` array, so this is thin. `content_html` can reach tens of kilobytes;
LONGTEXT is sized for it.

`Run::record_sources()` must become a merging write. Everything that touches
payload should go through one `Run::merge_payload( array $patch )` so the
clobbering bug cannot come back.

### 4.3 Idempotency

Each step checks the payload for its own output before doing anything:

- `propose_topic` — returns the stored topic if present.
- `generate_body` — returns the stored article if present. **This is the
  expensive one**; without it a re-dispatch pays for a full article again.
- `assemble_post` — if `runs.post_id` is set and the post exists, update it
  rather than insert. It already updates when a draft was adopted, so the branch
  exists.
- `generate_image` — if the payload holds an attachment ID and the attachment
  exists, skip.

`budget_check` is the exception: it must **not** be skipped on re-entry, but it
must not double-reserve either. Reserving is already an absolute write
(`cost_cents = estimate`, not `+=`), so re-running it is safe as written.

### 4.4 Resume — the part that needs stating plainly

**Splitting the pipeline does not, by itself, give resume.** When PHP is killed
mid-step, Action Scheduler marks that action failed and stops; it does not retry.
That is not speculation — it is why `Retry_Policy` exists at all, and its
docblock says so.

So after the split, a host that kills a step still leaves a run stuck in
`running` forever, exactly as today. What changes is only that the *window* for
being killed is one step instead of the whole article.

Resume needs a **stall sweeper**: a recurring action that finds rows in `running`
whose last write is older than some threshold, and re-dispatches them at their
recorded step. `Run_Retention` already establishes the pattern of a recurring
housekeeping action, so there is somewhere to put it.

This is the single most important piece to build, and the easiest to skip while
believing the job is done.

### 4.5 Retry, re-arm, and the terminal step

> **Settled in phase 4.** Making finalise a queued step of its own needed no
> change: by the time the chain reaches it, the action that publishes has already
> done nothing but cheap checks — the image step finished in the action before.
> Adding it to `Pipeline::STEPS` would have meant carrying `status_override` in
> the payload and changing what `next_step()` means for a Preview run, in
> exchange for no behaviour a site could observe. The re-arm half was the real
> work, and it found the attempt counter leaking on the two paths that abandon a
> run.


The finalise tail — status transition, `settle_cost()`, `succeed()`, review
email, budget warning — becomes the last step. Re-arming the next occurrence
must happen there **and** on every terminal failure path, including the
sweeper's give-up path. If it does not, a prompt silently stops forever, which is
the exact failure section 4.3 exists to prevent.

---

## 5. Consequences and risks

**The reservation leak (new failure mode, high).** Today reservation and
settlement happen inside one request; an abandoned run is rare. After the split,
any run stuck mid-chain holds its estimated cost against the monthly cap
indefinitely — the cap silently fills with money nobody spent, and prompts start
getting `skipped_budget` for no visible reason. The sweeper must release the
reservation when it gives up on a run. **This risk is created by the split and
does not exist today.**

**Decision D-10 does not invert after all.** The expectation was that step
resume would force the same run row to be reused across retries. It has not: the
step chain lives *within* one run, and a retry still opens a new row exactly as
D-10 says. Resume within a run is what phase 5's sweeper will use, and it works
on the row the chain already owns. D-10 stands as written.

**Draft adoption is touched.** `adoptable_draft()` keys off the immediately
preceding run row and its attempt number. Reusing a row within a run does not
change that, but the interaction needs a test rather than an argument — three
releases in a row were spent on this mechanism already.

**Preview must stay synchronous.** It calls three steps directly and returns an
article to the screen. Keep the step classes callable both ways; do not push
queue concerns into them.

**Latency grows.** A run that takes 90 seconds in one request will take several
minutes across six queue passes. For scheduled publishing this is irrelevant, but
"Run now" becomes noticeably less immediate and the UI copy should say so.

---

## 6. Work breakdown

Each phase is independently landable and leaves the plugin working.

| # | Phase | Size | Notes |
|---|---|---|---|
| 1 | ✅ `merge_payload()`, fix `record_sources()` clobbering, `Article::to_array()/from_array()` | Small | Done. Took three PRs: the document, then the cache semantics, then propagating the write failure to a caller that discarded it |
| 2 | ✅ Idempotency guards in the five steps + tests that each step run twice does the work once | Medium | Done. `Step_Assemble_Post` was already idempotent; the image work moved out of `Generator` into `Step_Generate_Image` so its guard could be re-entered and therefore tested |
| 3a | ✅ Extract the sequence into `Pipeline`; `Generator` drives it in a loop | Medium | Done. Pure refactor — every existing test passes unchanged, which is the safety net |
| 3b | ✅ Dispatcher, `autoscribe_run_step` hook, queued path advances one step per action | Medium | Done. `Run::post_id()` had to start reading the row — it returned an in-memory property that only the object which wrote it ever had |
| 4 | ✅ Re-arm on every terminal path | Small | Done. The finalise *step* turned out to need no work — see below |
| 5 | ✅ **Stall sweeper** + reservation release on give-up | Medium | Done. Staleness is decided by whether anything is queued to advance the run, not by age — age alone cannot tell a stalled run from a slow one |
| 6 | ✅ Docs: `DECISIONS.md` D-10 and D-09b, README known-limitations, `INSTRUCTIONS.md` on latency and filters | Small | Done, and shipped as 1.1.0 |

Phases 1 and 2 are worth doing whether or not the rest follows.

---

## 7. Tests this needs

The existing suite drives `Generator::run()` end to end and will keep passing
if the dispatcher is transparent — that is the main safety net. Beyond it:

- Each step run twice performs its work once (five tests).
- A run interrupted after each step and re-dispatched completes correctly and
  makes no duplicate provider call (five tests).
- The sweeper picks up a stalled run, and releases its reservation when it gives
  up.
- The next occurrence is armed after success, after terminal failure, and after
  a sweeper give-up.
- Payload merging does not lose `sources`.
- **An Action Scheduler dispatch test.** Currently no test drives the queue —
  the handler is called directly, which is recorded in the README. A split
  pipeline is exactly where that gap starts to matter.

---

## 8. What this buys, and what it does not

**Buys:** a killed request costs one step instead of the whole article, and the
sweeper restarts what was killed. It does **not** buy one provider call per
request — the topic step asks again after a collision and the article step makes
one repair call, so a step can make two calls at up to 120 seconds each. A
30-second host can still lose a step; it no longer loses the article. A failed step repeats
only that step rather than re-paying for the whole pipeline. `runs.step` becomes
a genuine progress indicator.

**Does not buy:** automatic recovery from a killed process — that is the sweeper
(§4.4), not the split. Nor does it reduce total provider cost for a successful
run, or make the pipeline faster; it makes it slower in wall-clock terms.

---

## 9. Decisions, and how they were answered

1. ~~**Sweeper threshold.**~~ **15 minutes, filterable** through
   `autoscribe_stall_threshold`, with a two-minute floor. In the end the
   threshold turned out not to be the important part: age cannot tell a stalled
   run from a slow one, so staleness is decided by whether anything is queued to
   advance the run, and age only keeps the sweeper away from runs too young to
   judge.
2. ~~**Sweeper give-up policy.**~~ **Two restarts, then fail and release**, as
   suggested. Restarts are counted *before* being armed: a restart recorded and
   then not armed is swept again and counted again, converging on giving up,
   while one armed and then not recorded would restart for ever.
3. ~~**Run-now latency.**~~ **Kept the synchronous driver.** Note this is about
   the *driver*, not the button: Run now queues an action and always has (D-19),
   and Preview is the caller that runs the sequence in its own request. The
   concern
   was two sequencers drifting apart, so there is only one — `Pipeline` owns the
   order and both drivers advance it. `Generator` loops it inside one request;
   the queue driver advances it one action at a time. Neither knows the order.
4. ~~**Ship as 1.1.0.**~~ **Shipped as 1.1.0.** Two new hooks
   (`autoscribe_run_step`, `autoscribe_sweep_runs`), a new filter, and a new
   recurring action. The retry model turned out *not* to change — see the note on
   D-10 in `DECISIONS.md`.

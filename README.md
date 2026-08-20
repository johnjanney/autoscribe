# AutoScribe

**A WordPress plugin that generates and publishes posts from scheduled AI prompts.**

[![CI](https://github.com/johnjanney/autoscribe/actions/workflows/ci.yml/badge.svg)](https://github.com/johnjanney/autoscribe/actions/workflows/ci.yml)

A prompt is a custom post type holding instructions, a schedule, a provider and
model, a monthly spend cap, and the taxonomy and SEO settings for whatever it
produces. When a prompt's schedule fires, AutoScribe asks the configured model
for a topic, checks that topic against what the site has already published,
generates the article, optionally generates a featured image, sanitizes
everything, and inserts the post as a draft or publishes it.

| | |
|---|---|
| **Version** | 1.13.1 |
| **Requires WordPress** | 6.4 |
| **Requires PHP** | 8.1 |
| **License** | GPL-2.0-or-later |
| **Tested against** | PHP 8.1, 8.2, 8.3 |

## Documentation

| Document | What's in it |
|---|---|
| **[INSTRUCTIONS.md](INSTRUCTIONS.md)** | How to install, configure, and use the plugin |
| **[CHANGELOG.md](CHANGELOG.md)** | Release history and the versioning policy |
| **[DECISIONS.md](DECISIONS.md)** | Why the plugin is built the way it is |
| **[CONTRIBUTING.md](CONTRIBUTING.md)** | Development setup, standards, and the release process |

---

## You are responsible for what gets published

This plugin writes articles with a large language model and can publish them to
a live site without a human reading them first. Models state things that are
wrong with complete confidence, and no amount of prompting reliably prevents it.

The safe default is on: every prompt starts in **review** mode, holding its
output as a draft. There is also a global **Force human review** switch in
Settings that overrides every prompt at once and cannot be bypassed by a manual
run or a WP-CLI argument.

If you turn automatic publishing on, the output is yours — legally, editorially,
and reputationally. Treat that as a deliberate decision rather than a default.

---

## What it does

- **Two independent provider slots.** Text from Anthropic, OpenAI, Google, or
  DeepSeek; images from OpenAI or Google. They are chosen separately, because
  Anthropic and DeepSeek generate no images and a single provider setting would
  make Claude-plus-Nano-Banana impossible.
- **Six schedule types**, including "the second Tuesday of the month". Daylight
  saving, month ends, and leap days are handled by the calendar, not guessed.
- **Structured output with validation.** The model returns one JSON object,
  which is validated against a schema; a malformed reply gets exactly one repair
  attempt before the run is abandoned.
- **Everything is sanitized before it reaches the database.** Script, style, and
  iframe blocks are removed with their contents, dangerous URI schemes are
  rejected, and the body goes through `wp_kses_post()`.
- **Web search grounding** through each provider's own server-side search tool,
  with the source URLs recorded on the run.
- **Duplicate-topic avoidance** that rejects a repeat before paying to write the
  article, not after.
- **SEO metadata** through Yoast, Rank Math, or SEOPress, detected at runtime.
- **Monthly spend caps**, per prompt and site-wide, enforced before any paid call.
- **A run log** with status, tokens, estimated spend, and a retry action.

---

## Installing

Download the plugin zip from the
[latest release](https://github.com/johnjanney/autoscribe/releases) and install
it through **Plugins → Add New → Upload Plugin**. The file is named for the
version you are downloading — this link stays correct when the version does not.

A `git clone` into `wp-content/plugins` will **not** work on its own — the
plugin has Composer dependencies that are not committed. Run `composer install
--no-dev` in the plugin directory, or use the release zip, which has them
bundled.

Full setup, including the cron configuration that schedules actually depend on,
is in **[INSTRUCTIONS.md](INSTRUCTIONS.md)**.

---

## Third-party services

**AutoScribe sends data to third-party AI providers.** It cannot do its job
otherwise. This section lists every external endpoint the plugin contacts, what
is sent, and when.

Nothing is sent until you supply an API key and a prompt configured to use that
provider. A site with no keys configured makes no external requests at all.

### Text generation

Exactly one text provider is contacted per prompt run — whichever that prompt is
configured to use.

| Provider | Endpoints contacted |
| --- | --- |
| Anthropic | `https://api.anthropic.com/v1/messages`<br>`https://api.anthropic.com/v1/models/{model}` |
| OpenAI | `https://api.openai.com/v1/responses`<br>`https://api.openai.com/v1/models/{model}` |
| Google | `https://generativelanguage.googleapis.com/v1beta/interactions`<br>`https://generativelanguage.googleapis.com/v1beta/models/{model}` |
| DeepSeek | `https://api.deepseek.com/chat/completions`<br>`https://api.deepseek.com/models` |

The `models` endpoints are read-only capability checks. An administrator
contacts them by pressing "Test connection" on the Settings screen. They confirm
that the model ID still exists, so that a retirement surfaces as a clear message
on that screen. They send no site content, and they are not contacted during a
generation run — a run does not preflight its model.

**Sent on a generation call:**

- The system prompt and user prompt stored on the prompt post, verbatim.
  Whatever you type into a prompt is sent to the provider.
- The requested article's target word count, as an output token ceiling.
- The JSON schema the response must conform to.
- **Titles and topic keys of your own recently published, drafted, pending, and
  scheduled posts.** The topic-proposal call sends these so the model can avoid
  proposing something the site already covers. The number included is the
  prompt's look-back setting. Only titles and topic keys — never post bodies.
- On a rejected proposal, the title that collided, so the re-ask can name it.
- On a malformed response, the model's own previous reply, so the repair call
  can correct it.
- Your API key, in the provider's authentication header.
- A `User-Agent` header of the form
  `AutoScribe/{version} (+https://your-site.example/)`. **This contains your
  site's URL and is sent on every request**, including the capability checks.

If a prompt has grounding enabled and the provider supports it, that provider's
own web search tool runs server-side on the provider's infrastructure. The
provider then reaches sites of its own choosing; AutoScribe has no visibility
into and no control over which.

### Image generation

Contacted only when a prompt is configured to generate a featured image.

| Provider | Endpoints contacted |
| --- | --- |
| OpenAI | `https://api.openai.com/v1/images/generations`<br>`https://api.openai.com/v1/models/{model}` |
| Google | `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`<br>`https://generativelanguage.googleapis.com/v1beta/models/{model}` |

**Sent:** an image description derived from the generated article, the requested
size, your API key, and the same `User-Agent` header carrying your site URL. The
generated image is returned to your server and sideloaded into the media
library; it is not hot-linked.

### What is never sent

- Existing post bodies, page content, or any other stored content beyond the
  titles and topic keys described above.
- User accounts, email addresses, comments, or any personal data.
- WordPress options, settings, or database contents.
- Anything at all beyond the endpoints listed above. AutoScribe contacts no
  analytics, telemetry, licensing, or update service of its own.

### Their terms, not ours

Data you send becomes subject to the receiving provider's terms and privacy
policy, including whatever retention and model-training practices those set out.
Read them before configuring a provider:

- Anthropic — <https://www.anthropic.com/legal/consumer-terms> · <https://www.anthropic.com/legal/privacy>
- OpenAI — <https://openai.com/policies/terms-of-use> · <https://openai.com/policies/privacy-policy>
- Google — <https://ai.google.dev/gemini-api/terms> · <https://policies.google.com/privacy>
- DeepSeek — <https://platform.deepseek.com/downloads/DeepSeek%20Terms%20of%20Use.html> · <https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html>

Retention and training-use defaults differ substantially between these providers
and change over time. If that matters for your content, verify the current terms
directly rather than relying on this list.

---

## Uninstalling

Deleting the plugin removes its table, its options, its capabilities, and every
prompt. It does **not** remove the posts and images it generated — those are
your content.

Two meta keys are deliberately left behind so you can act on that content later:
`_autoscribe_generated` on every generated attachment, and `_autoscribe_run_id`
on every generated post. Without them you would keep the content but lose any
way to tell it apart from the rest of the site.

---

## Status and known limitations

Version 1.11.0. Twelve external audits have been run against this plugin, and all
twelve found real defects; the findings, the fixes, and the findings rejected
with evidence are in `CODEX-REVIEW.md` and `CODEX-REVIEW-RESPONSE.md`. 411 tests
run against PHP 8.1, 8.2, and 8.3 on every push.

Six things this plugin does not do the way the brief describes, all of them
deliberate and all of them below: **Run now** queues rather than streaming its
result; the next-run readout reflects the saved schedule rather than updating
live; the duplicate-topic similarity threshold defaults to 78 percent where the
brief names 82; the Settings screen's save path has no automated coverage;
Action Scheduler's own dispatch is not exercised by any test; and there is no
screenshot in this README. Everything else worth knowing before you enable
unattended publishing is listed with them.

The first item is the one that matters most:

- **The monthly budget cap is enforced by a named database lock around the check
  and the reservation.** Concurrent workers on the same database serialise
  through it, so the cap holds against the batch execution Action Scheduler
  performs. Two limits remain: a run already past the check cannot be recalled,
  and the reserved figure is an estimate, so a run that costs more than estimated
  overshoots by the difference. On a database where the lock cannot be taken the
  plugin falls back to a weaker row-order re-check that narrows the race without
  closing it. Your provider's own spending limit is the only hard ceiling; treat
  this cap as a brake, not a wall.
- **A prompt that falls out of the queue is put back within a few sweeps.**
  A prompt is armed when it is saved and when one of its runs finishes, so an
  action killed part way could leave an enabled prompt with nothing queued and
  nothing to notice. The scheduled sweep now arms any enabled prompt that has
  nothing queued and no run in flight. It works through prompts in pages, so a
  site with many of them gives each one a turn rather than recovering the same
  first page every time. Until it reaches a prompt, the editor says "Not queued
  yet" rather than showing a next run nothing was going to perform — the readout
  reports the queue, not the calendar.
- **A scheduled run is spread across several queued requests, so it takes
  minutes rather than seconds.** One step per request is what keeps a host with a
  short `max_execution_time` from killing a whole article, and the cost is
  wall-clock time: each step waits for the queue's next pass. If your queue only
  runs when someone visits the site, set up the system cron described above, or a
  scheduled run will crawl.

  **Preview** still answers in the request that asked for it. **Run now** queues
  a run and returns immediately; the run log reports the outcome. It has always
  worked that way — section 9.2 of the brief asks for a streamed result and
  `DECISIONS.md` records why it queues instead.
- **A single step can still exceed a short request limit.** The topic step asks
  again when its first proposal collides, and the article step makes one repair
  call when a response does not validate, so one step can make two provider
  calls at up to 120 seconds each. Splitting the pipeline reduced how much a
  killed request costs — one step rather than the article — and the stall sweeper
  restarts what was killed. It did not make any single request short enough to
  guarantee survival on a 30-second host.
- The Settings screen's save path and its connection-test controls have no
  automated coverage. The prompt editor's save path does.
- The "Next run" readout in the prompt editor reflects the *saved* schedule. It
  does not update as you change the schedule controls — save to see the effect.
  Section 9.2 asks for a live readout.
- The duplicate-topic similarity threshold defaults to 78 percent, where section
  7.2 names 82. `similar_text()` compares characters rather than meaning, so the
  numeric check is a backstop behind the already-covered list sent to the model;
  it is set slightly wider on purpose. The `autoscribe_topic_similarity_threshold`
  filter sets it to anything you like.
- Key storage refuses to run where `AUTH_KEY` and `SECURE_AUTH_KEY` are missing
  or still placeholders, and refuses to *read* records stored that way by version
  1.0.0. That path has no automated coverage, because the salts are PHP constants
  and a test cannot un-define them.
- Concurrency is tested on two real database connections
  (`tests/Support/Two_Connection_Test_Case.php`), which is what makes the locks
  and compare-and-swap guards testable at all. Those tests interleave two
  sessions deterministically rather than running them in parallel: one PHP
  process still executes one statement at a time, so a true wall-clock race is
  still not exercised.
- Action Scheduler is driven by one test class only
  (`tests/Scheduling/Queue_DispatchTest.php`), which hands a prompt to the real
  queue runner and asserts on what comes out: the post, the run row, the next
  occurrence, and whether anything was left failed. Everything else advances a
  run one action at a time with a fresh handler each pass, which is what the
  queue does without asking the queue. The dispatch tests run the runner
  in-process, so what they do not cover is what only a second process can show:
  two runners claiming from the store at once, and the delay between an action
  being armed and the system cron arriving to run it.
- CI runs against MySQL only. A MySQL/MariaDB divergence was found and fixed
  during development; nothing guards against the reverse.
- Spend figures are estimates computed from reported token usage against a
  pricing table you maintain. They are not billing data.
- **A worker that has been restarted can still finish its provider call.** The
  stall sweep decides a worker is gone by whether anything is queued for its run,
  which a slow worker inside a 120-second call looks exactly like. The rule from
  there divides state from money.

  Every *state* write from such a worker is refused — its article, post link, and
  step all require the run to be open and the claim to still be its own, so a
  finished run cannot be changed afterwards. The two things it writes to
  WordPress rather than to the run row, the post and the featured image, re-check
  immediately before they start; the instant between that check and the write
  cannot be fenced, so in a rare interleaving one run can pay for two articles'
  worth of calls and keep one.

  Every *money* write is accepted, from any worker, at any time, open or closed:
  a call that was billed happened whoever made it. Token, image, and search
  counters are additive rather than overwritten, and a charge that arrives after
  the run closed raises what that run is recorded as having cost. Pricing what a
  counter records is a second statement, so it can be interrupted — the row is
  marked as owing a price by the same write that records the money, so an
  interrupted pricing is late rather than lost.

  Two things clear that mark. **The budget check clears all of it before it sums**,
  while it holds the spend lock, and refuses to authorise the run if it cannot:
  a cap that cannot be worked out stops spending rather than passing it. That
  refusal is scoped to what the enabled cap actually sums — one prompt's month,
  or the site's month — and a site with no cap set is never held up by it. A run
  waiting to be priced shows as "Accounting pending" in the Run Log. **The
  stall sweep clears a batch in the background**, which is what settles the books
  on a site that has stopped generating. That sweep is *scheduled* every five
  minutes; when it actually runs is up to Action Scheduler and your cron setup,
  so treat it as best effort rather than a deadline. If figures look stale, look
  for a past-due `autoscribe_sweep_runs` action under **Tools → Scheduled
  Actions**, or run `wp action-scheduler run --hooks=autoscribe_sweep_runs`. The
  guarantee that matters does not depend on it: nothing spends until the total is
  complete.
- **A run whose ending the database will not accept is left open on purpose.**
  Reporting a run as finished when the write that finishes it was refused is how
  one article becomes two emails and two schedules, so the queue says nothing and
  arms nothing; the stall sweep settles the row within about fifteen minutes and
  reports it then. You get one email an hour while the fault lasts, under the
  subject "AutoScribe could not record that a run had finished". It means the
  runs table, not the prompt.
- **Web search grounding is a prompt-injection surface, and human review is the
  control for it.** A grounded call has the provider fetch pages and put their
  text into the model's context; the plugin never sees that text before the model
  reads it. Retrieved content is fenced and the model is told to treat everything
  inside as data rather than instructions, which reduces the risk and does not
  eliminate it. Keep review mode on for grounded prompts. The longer version, with
  what is stored and where to look afterwards, is in
  [INSTRUCTIONS.md](INSTRUCTIONS.md).
- There is no screenshot in this README yet, which section 12 of the brief asks
  for.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

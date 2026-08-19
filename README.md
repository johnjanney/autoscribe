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
| **Version** | 1.0.0 |
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

Download `autoscribe-1.0.0.zip` from the
[latest release](https://github.com/johnjanney/autoscribe/releases) and install
it through **Plugins → Add New → Upload Plugin**.

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

Version 1.0.2. Two external audits have been run against this plugin, and both
found real defects; the findings, the fixes, and the two findings rejected with
evidence are in `CODEX-REVIEW.md` and `CODEX-REVIEW-RESPONSE.md`. 215 tests run
against PHP 8.1, 8.2, and 8.3 on every push.

Three brief requirements are knowingly not met: the single-action pipeline, the
live next-run readout, and the screenshot. They are listed below with everything
else worth knowing before you enable unattended publishing.

The first two items are the ones that matter most:

- **The generation pipeline runs as one Action Scheduler action, not one action
  per step as section 5 of the brief requires.** A run takes 30 to 120 seconds
  inside a single PHP request, so a host with a short `max_execution_time` can
  terminate it part-way, and the queue cannot resume from the step that was
  interrupted — a retry re-runs from the beginning, and pays for it. A draft left
  behind by a failed run is adopted by that run's own retry rather than
  duplicated, so this costs you repeated provider calls, not repeated posts. This
  is the one outstanding architectural gap, and it is planned work rather than a
  decision to skip.
- **The monthly budget cap is enforced by a named database lock around the check
  and the reservation.** Concurrent workers on the same database serialise
  through it, so the cap holds against the batch execution Action Scheduler
  performs. Two limits remain: a run already past the check cannot be recalled,
  and the reserved figure is an estimate, so a run that costs more than estimated
  overshoots by the difference. On a database where the lock cannot be taken the
  plugin falls back to a weaker row-order re-check that narrows the race without
  closing it. Your provider's own spending limit is the only hard ceiling; treat
  this cap as a brake, not a wall.
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
- No test drives Action Scheduler itself; the queued run handler is tested by
  calling it directly.
- CI runs against MySQL only. A MySQL/MariaDB divergence was found and fixed
  during development; nothing guards against the reverse.
- Spend figures are estimates computed from reported token usage against a
  pricing table you maintain. They are not billing data.
- There is no screenshot in this README yet, which section 12 of the brief asks
  for.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

# Using AutoScribe

Everything from installing the plugin to running your first prompt, and what to
do when something goes wrong.

- [Install](#install)
- [Add your API keys](#add-your-api-keys)
- [Make the scheduler actually work](#make-the-scheduler-actually-work)
- [Set the safety net first](#set-the-safety-net-first)
- [Write your first prompt](#write-your-first-prompt)
- [Test it before you schedule it](#test-it-before-you-schedule-it)
- [The prompt editor, tab by tab](#the-prompt-editor-tab-by-tab)
- [The run log](#the-run-log)
- [Settings](#settings)
- [WP-CLI](#wp-cli)
- [Troubleshooting](#troubleshooting)
- [Uninstalling](#uninstalling)

---

## Install

Download `autoscribe-{version}.zip` from the
[releases page](https://github.com/johnjanney/autoscribe/releases), then in
WordPress go to **Plugins → Add New → Upload Plugin**, choose the file, and
activate.

Cloning the repository into `wp-content/plugins` is not enough on its own. The
plugin depends on Action Scheduler and a cron-expression library, and `vendor/`
is not committed. If you clone, run:

```bash
composer install --no-dev --optimize-autoloader
```

Activation creates the run-log table, registers the `autoscribe_manage_prompts`
capability, and grants it to Administrator.

---

## Add your API keys

You need a key for at least one text provider. Image generation is optional.

### The recommended way: `wp-config.php`

Add the constants for the providers you use, above the `/* That's all, stop
editing! */` line:

```php
define( 'AUTOSCRIBE_ANTHROPIC_KEY',    'sk-ant-...' );
define( 'AUTOSCRIBE_OPENAI_KEY',       'sk-...'     );
define( 'AUTOSCRIBE_GOOGLE_KEY',       '...'        );
define( 'AUTOSCRIBE_DEEPSEEK_KEY',     'sk-...'     );
define( 'AUTOSCRIBE_OPENAI_IMAGE_KEY', 'sk-...'     );
define( 'AUTOSCRIBE_GOOGLE_IMAGE_KEY', '...'        );
```

The key never enters the database and never appears in a database backup. A key
set this way wins over anything stored in the settings screen, and the settings
screen will tell you so.

You can use the same OpenAI key for both `AUTOSCRIBE_OPENAI_KEY` and
`AUTOSCRIBE_OPENAI_IMAGE_KEY`; they are separate settings so that text and
images can come from different accounts if you want.

### The other way: the settings screen

**AutoScribe → Settings** has a password field per provider. Keys entered there
are encrypted with libsodium before storage.

Be clear about what that buys you: it protects a leaked database dump. It does
**not** protect against someone who can read files on your server, because the
encryption key is derived from your `wp-config.php` salts. Anyone who can read
the database can usually read `wp-config.php` too.

**If you ever rotate your `AUTH_KEY` or `SECURE_AUTH_KEY` salts, stored keys
become unreadable.** The plugin detects this and reports the key as needing
re-entry rather than failing with something cryptic. Keys held in constants are
unaffected — another reason to prefer them.

### Set a default model per provider

Each provider needs a default model ID in Settings. Model IDs are plain editable
text, never a fixed dropdown, because providers retire them on their own
schedule and a hard-coded ID would break the plugin the day that happened.

The **Test connection** control needs a model to probe with, so set the model
before testing.

---

## Make the scheduler actually work

**Skip this and your prompts will fire late, erratically, or not at all.**

WordPress's built-in cron only runs when somebody loads a page. On a quiet site
that can mean a schedule drifts for days. Fix it once:

**1.** In `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

**2.** Add a real system cron entry (`crontab -e`):

```cron
* * * * * curl -s https://your-site.example/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

Every minute is right. Action Scheduler decides what is actually due; the cron
entry only gives it a heartbeat.

**AutoScribe → Settings → System health** tells you whether `DISABLE_WP_CRON` is
set and whether Action Scheduler is loaded. Check it after making these changes.

If your host provides its own cron UI, point it at the same URL at the same
frequency.

---

## Set the safety net first

Before you create anything, go to **AutoScribe → Settings** and decide two
things.

**Force human review.** Turning this on holds every generated post as a draft,
regardless of how any individual prompt is configured. It cannot be bypassed by
"Run now" or by a WP-CLI argument. Leave it on until you have seen several
articles you would have been happy to publish.

**Global monthly cap.** A number in cents. Once the month's estimated spend
reaches it, runs stop before making any paid call. Zero means no cap, which is
rarely what you want on a site that runs unattended.

Set a notification email too, under Housekeeping. You get one warning per month
when spend passes 80% of the cap — one, not one per run.

---

## Write your first prompt

**AutoScribe → Add New Prompt.**

Give it a title (for you, not for the model), then work through the tabs. The
minimum to get something out of it:

| Tab | Field | Suggestion for a first run |
|---|---|---|
| Content | Text provider | Whichever key you configured |
| Content | Text model | Your default, or override it here |
| Content | System prompt | Who the writer is and what rules they follow |
| Content | Topic instruction | What to write about |
| Schedule | Enabled | Leave **off** until you have tested it |
| Publishing | On completion | Hold as a draft for review |
| Limits | Monthly cap | Something small while you experiment |

A workable system prompt looks like this:

> You write for a specialty coffee blog read by home baristas who already know
> the basics. Be specific and practical. Use metric units. Never invent studies,
> statistics, or quotes. If you are unsure of a fact, leave it out rather than
> hedging.

And a topic instruction:

> Write about one aspect of espresso extraction that home baristas commonly get
> wrong. Choose a different aspect each time.

**Leave "Enabled" off for now.** A disabled prompt is removed from the queue
entirely, which is what you want while you are still testing.

---

## Test it before you schedule it

Two controls at the bottom of the prompt editor:

**Preview** runs the pipeline as far as generating the article and shows you the
result **without creating a post**. This is the one to use while you are
tuning a prompt.

**Run now** queues a real run that creates a post. The queue picks it up within
a minute if your cron is set up correctly, and then works through the run one
step at a time — see "How long a run takes" below.

Both cost money and both appear in the run log. Preview is charged against the
budget exactly like a real run, because it makes the same calls.

Iterate on Preview until the output is what you want, then use Run now once to
confirm the whole path works — including the featured image and the taxonomy —
and only then turn **Enabled** on.

---

## The prompt editor, tab by tab

### Content

- **Text provider / Text model** — who writes it. The model field is free text
  with suggestions; you can type a model the plugin has never heard of.
- **System prompt** — persona and rules. Sent on every call.
- **Topic instruction** — what to write about.
- **Target word count** — becomes an output token ceiling, so treat it as a
  budget rather than a promise.
- **Use web search grounding** — lets the provider search the web server-side.
  Read the warning below before enabling this.
- **Append a Sources list** — adds the URLs a grounded call used to the bottom
  of the article. The URLs are recorded on the run either way.

> **Grounding is a prompt-injection surface.** The search runs on the provider's
> own infrastructure, and the pages it retrieves enter the model's context as
> untrusted third-party text. A hostile page can carry instructions aimed at the
> model. AutoScribe never sees that text before the model reads it, so it cannot
> wrap it in delimiters or filter it — nothing the plugin does protects you here.
>
> What the plugin does wrap is the data it supplies itself: the titles and topic
> keys of your own recent posts go into the topic proposal call inside an
> explicitly labelled untrusted-data block, because anyone who can author a post
> can write a title, and that is a wider group than the people allowed to manage
> prompts.
>
> Keep human review on when grounding is on. It is the only control that covers
> what the provider retrieved.

DeepSeek has no web search. The control tells you so rather than letting you
save a configuration that cannot run.

### Schedule

- **Enabled** — off means the prompt is not queued at all.
- **Repeats** — the six schedule types:

| Type | You set | Example |
|---|---|---|
| Every day | Time | 06:00 daily |
| Every week | Weekday, time | Mondays at 06:00 |
| Monthly, on a date | Day, time | The 15th |
| Monthly, on a weekday | Which week, weekday, time | The second Tuesday |
| Every N hours | Hours | Every 72 hours |
| Cron expression | Expression | `0 6 * * 1-5` |

All times are in **your site's timezone**, shown next to the time field.

A day-of-month past the end of a short month rolls back to that month's last
day — the 31st becomes 28 or 29 in February, not 3 March. Daylight saving is
handled: a time that does not exist on the spring-forward day moves forward an
hour, and one that happens twice on the fall-back day takes the first.

The **Next run** readout under the editor shows exactly when it will fire.

If your site is offline when a run was due, AutoScribe runs once when it comes
back and moves on. It never backfills a week of missed articles at once.

### Image

- **Featured image** — what to do about images:

| Mode | On generation failure |
|---|---|
| Required | Fail the run, keep the post as a draft, notify. Never publishes without an image. |
| Fallback | Attach the fallback image and carry on. |
| Optional | Publish with no featured image, log a warning. |
| None | Skip image generation entirely. |

- **Image provider / model** — chosen independently of the text provider.
  Anthropic and DeepSeek generate no images.
- **House style suffix** — appended to every image prompt, so all your articles
  share a look without editing each prompt. Something like
  `Muted colour palette, soft natural light, no text or logos.`
- **Fallback image ID** — the attachment ID to use in Fallback mode.

Generated images get a `_autoscribe_generated` flag so you can find and bulk
delete them later.

### Publishing

- **On completion** — draft for review, or publish immediately. The global
  Force human review setting overrides this.
- **Create as** — post or page.
- **Categories** — always come from here. The model is never allowed to invent
  a category.
- **Tags** — three modes:
  - *No tags*
  - *Fixed list* — the tags you specify, every time
  - *Suggested by the model* — matched against existing tags first, and capped
    at three genuinely new tags per post. Without that cap the tag list becomes
    unusable within a month.
- **Author** — the user generated posts are attributed to.

### Limits

- **Monthly cap (cents)** — for this prompt. Zero means no per-prompt cap; the
  global cap still applies and always wins.
- **Duplicate look-back** — how many recent posts a proposed topic is compared
  against. 50 is a sensible default.

---

## The run log

**AutoScribe → Run Log** lists every run: date, prompt, status, title, a link to
the post, models used, tokens, estimated spend, attempt number, and any error.

Filter by prompt, status, or month. Failed runs get a **Retry** action, which
queues a fresh run rather than resuming the old one.

Statuses:

| Status | Meaning |
|---|---|
| `running` | In progress |
| `success` | Finished, post created |
| `failed` | Errored. Check the error column |
| `skipped_budget` | Stopped before spending, because a cap was reached |
| `skipped_duplicate` | The topic was already covered, so the article was never written |

### How long a run takes

A scheduled run is not one long request. It is a short one per step — budget
check, topic, article, post, image, publish — each queued separately, so a
generated article arrives some minutes after its scheduled time rather than
seconds after it.

That is deliberate, and it is what makes the plugin survivable on ordinary shared
hosting. A whole run takes 30 to 120 seconds of provider time, so a host that
cuts requests off at 30 seconds would kill an undivided run part-way, every time.
Splitting it means a killed request costs one step rather than the article.

It is a reduction in exposure rather than a guarantee. A single step can still
run long: the topic step asks again when its first proposal collides, and the
article step makes one repair call when the response does not validate, so a step
can make two provider calls back to back, each allowed up to 120 seconds. A host
with a hard 30-second limit can still terminate one — the run is then picked up
again rather than lost, which is the part that changed.

The practical consequences:

- **Set up the system cron described in the README.** Without it the queue only
  advances when somebody visits the site, and a run that needs six passes will
  crawl — or stop entirely on a quiet site.
- **Preview and Run now are unaffected in feel.** Preview answers in the request
  that asked for it. Run now queues, as it always has.
- **A run that stops part-way is picked up again.** If your host kills a request,
  nothing is left half-finished for ever: an automatic sweep restarts the run,
  and after two attempts gives up on it, releases whatever it had set aside
  against your monthly budget, and records why in the run log.

  A run log entry saying it "stopped part-way" means a request was terminated
  before it finished. **The system cron above does not fix that** — cron makes
  the queue advance, and this run's request was killed while it was already
  advancing. Look at the host's PHP limits instead: `max_execution_time` first,
  then memory. If the host will not raise them, a provider or model that answers
  faster is the other lever.

---

A failed run is retried automatically up to three times, after 5 minutes, then
30 minutes, then an hour — but only when the failure was a transport-level one:
the network dropped, the provider rate-limited the request, or the provider was
unavailable. Everything else is treated as permanent and not retried, including
anything the plugin does not recognise, because a retry costs money and most
failures cost the same money to reach the same answer. A bad API key, a retired
model, a refusal, a duplicate topic, and a budget breach are all in that group.
Use the **Retry** action in the Run Log when you have fixed the cause yourself.

Run history is pruned after 90 days by default. Change that under **Settings →
Housekeeping**; zero keeps everything.

---

## Settings

**AutoScribe → Settings.**

- **Providers** — API key and default model per provider, with a status line
  telling you where each key comes from.
- **Publishing and budget** — Force human review, the global monthly cap, and
  month-to-date spend.
- **Pricing** — dollars per million input tokens, per million output tokens, per
  image, per grounded request. **The plugin never fetches prices.** If you do
  not maintain this table, the spend figures drift from your real bill.
- **Housekeeping** — notification email and run-log retention.
- **System health** — whether Action Scheduler is loaded, whether
  `DISABLE_WP_CRON` is set, and whether libsodium is available.

---

## WP-CLI

```bash
wp autoscribe run <prompt-id> [--status=<status>]
```

Runs one prompt immediately, bypassing its schedule but not its budget cap, and
reports the run ID, post ID, attachment ID, and resulting status. `--status`
overrides the post status the prompt would use — though Force human review still
wins over it.

Find prompt IDs with:

```bash
wp post list --post_type=autoscribe_prompt --fields=ID,post_title
```

---

## Troubleshooting

**Nothing runs on schedule.** Almost always the cron setup. Check **Settings →
System health**. If `DISABLE_WP_CRON` is not set, or no system cron is hitting
`wp-cron.php`, schedules will drift on a low-traffic site. See
[Make the scheduler actually work](#make-the-scheduler-actually-work).

**"No key configured" when a key is set.** If you rotated your WordPress salts,
stored keys can no longer be decrypted. Re-enter the key, or move it to a
`wp-config.php` constant.

**Runs stop with `skipped_budget`.** The monthly cap was reached. Check
month-to-date spend in Settings. Remember the figure is an estimate from your
pricing table, so if that table is stale the cap triggers at the wrong point.

**Runs stop with `skipped_duplicate`.** The model proposed a topic the site
already covers, twice in a row. This is working as intended — it stops you
paying for an article you would have thrown away. Make the topic instruction
broader, or raise the look-back.

**The post has no featured image.** Check the image mode, and that the image
provider has its own key and model. Anthropic and DeepSeek cannot generate
images regardless of what the text provider is set to.

**Generated articles are repetitive.** Raise the duplicate look-back, and make
the topic instruction ask for variety explicitly.

**Spend looks wrong.** The pricing table is yours to maintain. Compare against
your provider's dashboard and update the rates.

**A model ID stopped working.** Providers retire models. Put the new ID in the
prompt's model field or in Settings — nothing needs a plugin update.

---

## Filters

Three settings are adjustable in code, for the cases where a site's needs differ
from the defaults. Put these in a small must-use plugin rather than a theme's
`functions.php`, so they survive a theme change.

```php
// Treat topics as duplicates only when they are very similar indeed.
add_filter( 'autoscribe_topic_similarity_threshold', fn() => 90 );

// Retry on one more provider error code you have seen come and go.
add_filter( 'autoscribe_transient_error_codes', function ( array $codes ) {
    $codes[] = 'autoscribe_provider_error';

    return $codes;
} );

// Wait half an hour before treating an unattended run as stalled.
add_filter( 'autoscribe_stall_threshold', fn() => 30 * MINUTE_IN_SECONDS );
```

`autoscribe_transient_error_codes` is the one to be careful with. Every code you
add is a decision to spend money making the same call again, which is why the
default list holds only the three failures that mean the request never reached
the provider at all.

`autoscribe_stall_threshold` has a floor of two minutes, because a value below
one provider timeout would have the sweeper competing with runs that are simply
working.

---

## Uninstalling

Deactivating changes nothing except cancelling queued actions. Your prompts,
runs, and settings survive a deactivate/reactivate cycle.

**Deleting** the plugin removes the run-log table, all options, the capability,
and every prompt.

It does **not** delete the posts and images it generated. Those are your
content. Two markers are left on them so you can find them afterwards:

```bash
# Generated posts
wp post list --post_type=post --meta_key=_autoscribe_run_id --fields=ID,post_title

# Generated images
wp post list --post_type=attachment --meta_key=_autoscribe_generated --fields=ID,post_title
```

# Project Brief — AutoScribe

**A WordPress plugin that generates and publishes posts from scheduled AI prompts.**

| Field | Value |
|---|---|
| Working name | AutoScribe (placeholder — verify the slug is free before you commit) |
| Slug | `autoscribe` |
| Text domain | `autoscribe` |
| PHP namespace | `AutoScribe\` |
| Function/option prefix | `autoscribe_` |
| License | GPL-2.0-or-later |
| Distribution | Public GitHub repo. Author uses it on own sites. No WordPress.org submission planned. |
| Minimum PHP | 8.1 |
| Minimum WordPress | 6.4 |
| Author | John Janney (`johnjanney`) |
| Build tool | Claude Code CLI |
| Date | 16 August 2026 |

---

## 1. Purpose

The plugin does four things:

1. It connects to an AI text provider and an AI image provider.
2. It runs saved prompts on independent schedules.
3. It turns each model response into a WordPress post with a featured image.
4. It publishes the post, or holds it as a draft for a human to review.

---

## 2. Design decisions and the reasons for them

Read this section first. It contains four corrections that change the architecture. Do not
design around them.

### 2.1 Text provider and image provider are separate settings

Anthropic has no image generation endpoint. DeepSeek's hosted API has no image generation
endpoint either — its Janus-Pro image models are open weights that you self-host or reach
through a third party such as fal.ai. Neither key that a user pastes into a settings field
can produce a picture.

Therefore the plugin must hold **two independent provider slots**. A user must be able to
select Claude for the article and Google Nano Banana for the featured image.

Confirmed provider capability (August 2026):

| Provider | Text | Images | Web search grounding |
|---|---|---|---|
| Anthropic (Claude) | Yes | **No** | Yes (server-side `web_search` tool) |
| OpenAI | Yes | Yes (`gpt-image-2`) | Yes |
| Google (Gemini) | Yes | Yes (Nano Banana family) | Yes (Google Search grounding) |
| DeepSeek | Yes | **No** | **No** |

Do **not** build an adapter for Google Imagen. Google deprecated it and shut it down on
17 August 2026. Use the Nano Banana models instead (`gemini-3.1-flash-image`,
`gemini-3-pro-image`).

### 2.2 Model IDs are configuration, not constants

Model names change every few months. `gpt-image-2` replaced `gpt-image-1.5` in April 2026.
Gemini image models renamed twice in a year.

Store each model ID as an **editable text field** with a dropdown of suggested values. Never
hard-code a model ID in a place where a retirement breaks the plugin. Show the last known
good default, and let the user overwrite it.

### 2.3 Use the REST APIs. Do not use MCP.

MCP connects an AI client to tools. In this plugin, WordPress *is* the client. It holds the
prompt, calls the model, and writes the post. An MCP layer would add a persistent Node or
Python process and a transport that most PHP hosts do not provide, and it would add no
capability.

Use `wp_remote_post()` behind a provider adapter interface. Each new provider becomes one
class file.

MCP is still useful here, but in the opposite direction and in a later version: expose
WordPress *as* an MCP server so Claude Code can drive the site. That is out of scope for v1.

### 2.4 WP-Cron cannot run this. Use Action Scheduler.

Two hard limits:

- WP-Cron fires on page loads. On a low-traffic site, a schedule drifts or stops.
- WP-Cron recurring schedules are fixed intervals. "The second Tuesday of the month" is not
  a fixed interval.

Also, a full generation run takes 30–120 seconds. That risks a PHP timeout inside a single
cron hook.

Use **Action Scheduler** (bundled through Composer). It gives you a queue, retries with
backoff, and a run log. Schedule each occurrence as a **single action** that computes and
arms its own next occurrence when it completes.

Ship documentation that tells the user to set `DISABLE_WP_CRON` to `true` in `wp-config.php`
and to add a real system cron entry that hits `wp-cron.php` every minute.

---

## 3. Architecture

### 3.1 File layout

```
autoscribe/
├── autoscribe.php              # Plugin header, guards, bootstrap only
├── uninstall.php
├── LICENSE                     # GPL-2.0
├── README.md
├── CHANGELOG.md
├── CONTRIBUTING.md
├── composer.json
├── phpcs.xml.dist
├── .editorconfig
├── languages/autoscribe.pot
├── src/
│   ├── Plugin.php              # Container, hook registration
│   ├── Activation.php          # Table creation, capability registration
│   ├── Providers/
│   │   ├── Text_Provider_Interface.php
│   │   ├── Image_Provider_Interface.php
│   │   ├── Provider_Registry.php
│   │   ├── Text/Anthropic.php
│   │   ├── Text/OpenAI.php
│   │   ├── Text/Google.php
│   │   ├── Text/DeepSeek.php
│   │   ├── Image/OpenAI_Image.php
│   │   ├── Image/Google_Image.php
│   │   └── Image/Null_Image.php
│   ├── Prompts/
│   │   ├── Prompt_Post_Type.php
│   │   ├── Prompt.php          # Typed value object over the CPT
│   │   └── Prompt_Meta_Box.php
│   ├── Scheduling/
│   │   ├── Schedule.php        # Value object: type + parameters
│   │   ├── Next_Run_Calculator.php
│   │   └── Scheduler.php       # Action Scheduler wrapper
│   ├── Pipeline/
│   │   ├── Run.php             # Typed value object over the runs table
│   │   ├── Step_Budget_Check.php
│   │   ├── Step_Gather_Context.php
│   │   ├── Step_Propose_Topic.php
│   │   ├── Step_Generate_Body.php
│   │   ├── Step_Generate_Image.php
│   │   └── Step_Assemble_Post.php
│   ├── Cost/
│   │   ├── Pricing_Table.php
│   │   └── Budget_Guard.php
│   ├── SEO/
│   │   ├── SEO_Adapter_Interface.php
│   │   ├── Yoast_Adapter.php
│   │   ├── Rank_Math_Adapter.php
│   │   ├── SEOPress_Adapter.php
│   │   └── Null_Adapter.php
│   ├── Security/
│   │   ├── Key_Store.php
│   │   └── Content_Sanitizer.php
│   └── Admin/
│       ├── Settings_Page.php
│       ├── Runs_List_Table.php
│       └── Assets.php
└── tests/
```

### 3.2 Data model

**Prompts → custom post type `autoscribe_prompt`.** You get CRUD, a list table, revisions,
capability mapping, and nonce infrastructure at no cost. The row count stays small. A custom
table would buy nothing.

Prompt meta keys (all prefixed `_autoscribe_`):

| Key | Type | Notes |
|---|---|---|
| `text_provider` | string | Provider slug |
| `text_model` | string | Editable model ID |
| `system_prompt` | string | Persona and rules |
| `user_prompt` | string | The topic instruction |
| `target_word_count` | int | |
| `schedule_type` | string | See §4 |
| `schedule_params` | array | See §4 |
| `next_run_ts` | int | Cached, for display only. The queue is the source of truth. |
| `post_status_mode` | string | `review` (draft) or `auto` (publish) |
| `post_type` | string | Default `post` |
| `category_ids` | array | |
| `tag_mode` | string | `fixed`, `ai`, or `none` |
| `fixed_tags` | array | |
| `author_id` | int | |
| `image_mode` | string | See §6 |
| `image_provider` | string | |
| `image_model` | string | |
| `image_style_suffix` | string | Appended to every image prompt for brand consistency |
| `fallback_image_id` | int | Attachment ID |
| `grounding_enabled` | bool | |
| `dedupe_lookback` | int | Number of past posts to compare against. Default 50. |
| `monthly_budget_cents` | int | 0 means "no per-prompt cap" |
| `enabled` | bool | |

**Runs → custom table `{prefix}autoscribe_runs`.** The row count grows without bound, and
you need to query by prompt, date, and status, and sum cost. A custom table is justified here.

```sql
CREATE TABLE {prefix}autoscribe_runs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  prompt_id     BIGINT UNSIGNED NOT NULL,
  post_id       BIGINT UNSIGNED NULL,
  status        VARCHAR(20) NOT NULL,   -- queued|running|success|failed|skipped_budget|skipped_duplicate
  step          VARCHAR(40) NULL,       -- last completed step
  topic_key     VARCHAR(191) NULL,
  title         TEXT NULL,
  text_model    VARCHAR(100) NULL,
  image_model   VARCHAR(100) NULL,
  input_tokens  INT UNSIGNED DEFAULT 0,
  output_tokens INT UNSIGNED DEFAULT 0,
  image_count   SMALLINT UNSIGNED DEFAULT 0,
  cost_cents    INT UNSIGNED DEFAULT 0,
  attempt       TINYINT UNSIGNED DEFAULT 1,
  error         TEXT NULL,
  payload       LONGTEXT NULL,          -- intermediate state between steps, JSON
  started_at    DATETIME NOT NULL,
  finished_at   DATETIME NULL,
  PRIMARY KEY (id),
  KEY prompt_started (prompt_id, started_at),
  KEY status_idx (status),
  KEY topic_key_idx (topic_key)
);
```

Add a retention setting. Delete run rows older than N days by a daily Action Scheduler job.
Default 90 days.

**Settings → one option `autoscribe_settings` (array).** API keys are stored separately.
See §8.1.

---

## 4. Scheduling

### 4.1 Schedule types

| Type | Parameters | Example |
|---|---|---|
| `daily` | `time` | Every day at 06:00 |
| `weekly` | `weekday`, `time` | Every Monday at 06:00 |
| `monthly_date` | `day_of_month`, `time` | The 15th of each month |
| `monthly_ordinal` | `ordinal`, `weekday`, `time` | The second Tuesday of each month |
| `interval` | `hours` | Every 72 hours |
| `cron_expression` | `expression` | Advanced users only |

`ordinal` accepts `first`, `second`, `third`, `fourth`, `last`.

### 4.2 Next-run calculation

Use `DateTimeImmutable` with the site timezone from `wp_timezone()`. Never use the server
default timezone.

PHP relative date strings solve the hard case directly. Do not write a custom calendar
algorithm.

```php
$tz  = wp_timezone();
$now = new DateTimeImmutable( 'now', $tz );

// "second tuesday of next month" at 06:00
$next = ( new DateTimeImmutable( 'second tuesday of next month', $tz ) )
    ->setTime( 6, 0 );
```

Guard for the `monthly_date` case where the day does not exist. The 31st of February must
roll to the last day of the month, not to March.

Handle daylight saving transitions. If a target local time does not exist on a spring-forward
day, move it forward by one hour. If it occurs twice on a fall-back day, take the first.

Write unit tests for the calculator. It is the single highest-risk piece of logic in the
plugin. Cover: month boundaries, leap years, `last` ordinal, DST both directions, and a
schedule created in the past.

### 4.3 Arming the queue

```php
$hook = 'autoscribe_run_prompt';
$args = [ 'prompt_id' => $prompt_id ];

if ( false === as_next_scheduled_action( $hook, $args, 'autoscribe' ) ) {
    as_schedule_single_action( $next_ts, $hook, $args, 'autoscribe' );
}
```

Rules:

- Re-arm the next occurrence at the **end** of a run, whether it succeeded or failed.
- Re-arm on prompt save, and cancel the old action first.
- Cancel all actions for a prompt when the prompt is trashed or disabled.
- **Do not backfill.** If the site was offline for a week, run once and move on. Never queue
  seven articles at once.

---

## 5. Generation pipeline

Split the run into separate Action Scheduler steps. Each step is a short request. This is the
difference between code that works on localhost and code that works on shared hosting.

```
autoscribe_run_prompt
  └─ Step_Budget_Check      → abort as skipped_budget, or continue
  └─ Step_Gather_Context    → recent posts, categories, optional grounding results
  └─ Step_Propose_Topic     → model returns title + topic_key only
       └─ duplicate check   → abort as skipped_duplicate, or continue
  └─ Step_Generate_Body     → model returns the full JSON payload
  └─ Step_Generate_Image    → image provider call, media sideload
  └─ Step_Assemble_Post     → wp_insert_post, taxonomy, SEO meta, notification
```

Each step:

- Reads its input from `runs.payload`, writes its output back to `runs.payload`.
- Is **idempotent**, keyed by `run_id`. A retried step must not create a second post.
- Updates `runs.step` on success.
- Throws on failure. Action Scheduler retries. Cap at 3 attempts, then mark `failed` and
  send a notification.

### 5.1 Structured output contract

Instruct the model to return **one JSON object and nothing else** — no prose, no Markdown
fences. Then strip any fences defensively, decode, and validate against a schema.

```json
{
  "title": "string",
  "topic_key": "lowercase-hyphenated-slug",
  "excerpt": "string, max 55 words",
  "content_html": "string, semantic HTML, h2/h3/p/ul/ol/blockquote only",
  "seo_title": "string, max 60 characters",
  "meta_description": "string, max 155 characters",
  "focus_keyword": "string",
  "suggested_tags": ["string"],
  "image_prompt": "string, a description of the featured image",
  "image_alt": "string, max 125 characters"
}
```

On a validation failure, send one repair request that includes the decode error. On a second
failure, mark the run `failed`. Do not retry endlessly — each retry costs money.

OpenAI and Google support strict JSON schema modes. Use them when the provider capability
flag says so. Fall back to prompt-and-validate for the rest.

Ask for the `image_prompt` in the **same call** as the article. The picture then matches the
article, and you avoid a second text call.

### 5.2 Content sanitisation — mandatory

Model output is untrusted input. Never insert it raw.

- Run `content_html` through `wp_kses_post()` before `wp_insert_post()`.
- Strip `<script>`, `<style>`, `<iframe>`, and all `on*` attributes.
- Reject any content containing a `data:` or `javascript:` URI.
- Truncate `meta_description` and `seo_title` to their limits. Do not trust the model to obey
  a character count.

---

## 6. Featured image

Each prompt carries an `image_mode`:

| Mode | Behaviour on generation failure |
|---|---|
| `required` | Fail the run. Save the post as a draft. Notify. Never publish without the image. |
| `fallback` | Attach `fallback_image_id`. Continue and publish. |
| `optional` | Publish with no featured image. Log a warning. |
| `none` | Skip image generation entirely. |

Implementation notes:

- Build the final image prompt as `image_prompt + " " + image_style_suffix`. The suffix gives
  you a consistent house style across every article without editing each prompt.
- Providers return either base64 data or a short-lived URL. Handle both.
- Sideload with `wp_insert_attachment()`, then `wp_generate_attachment_metadata()`, then
  `wp_update_attachment_metadata()`, then `set_post_thumbnail()`.
- **Gotcha:** in a cron or CLI context, the image functions are not loaded. Require them
  explicitly:

```php
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
```

- Set the attachment `post_excerpt` to the caption and the `_wp_attachment_image_alt` meta to
  `image_alt`.
- Add a `_autoscribe_generated` meta flag on the attachment so a user can find and bulk-delete
  AI images later.

---

## 7. The four power features

### 7.1 Web search grounding

Use each provider's own server-side search tool. Do not build a separate scraper.

| Provider | Mechanism |
|---|---|
| Anthropic | `web_search` server tool in the `tools` array |
| OpenAI | Web search tool |
| Google | Google Search grounding |
| DeepSeek | **Not available.** Disable the checkbox and show the reason in the UI. |

The provider adapter exposes `supports_web_search(): bool`. The prompt editor reads that flag
and disables the control with an explanation. Never let a user save a configuration that
cannot run.

**Security note.** Grounded content is untrusted third-party text entering the model context.
This is a prompt-injection surface. Wrap retrieved content in explicit delimiters and instruct
the model to treat everything inside as data, never as instructions. This reduces the risk. It
does not eliminate it. Document the residual risk in the README, and make "human review" the
recommended default when grounding is on.

Store the source URLs the model used in `runs.payload`, and optionally append a "Sources"
block to the post.

### 7.2 Duplicate-topic avoidance

Anthropic has no embeddings API, so an embeddings-based approach is not portable across all
four providers. Use a deterministic method instead. It is cheaper and it works everywhere.

1. Query the last `dedupe_lookback` published posts in the prompt's target categories. Collect
   `post_title` and the `_autoscribe_topic_key` meta.
2. Inject that list into the `Step_Propose_Topic` call under a heading such as
   "Already covered — propose something different."
3. The model returns only a `title` and a `topic_key` at this stage. This call is small and
   cheap.
4. Check the returned `topic_key` against the stored keys. Flag a collision when either:
   - the key matches exactly, or
   - `similar_text()` against any stored key exceeds 82 percent, or
   - `post_exists( $title )` returns a post ID.
5. On a collision, re-ask once and name the collision explicitly.
6. On a second collision, mark the run `skipped_duplicate` and stop. Do not spend money on a
   body you will throw away.

Make the 82 percent threshold a filterable constant so it can be tuned per site.

### 7.3 SEO metadata and taxonomy

Detect the active SEO plugin at runtime and pick an adapter. Fall back to `Null_Adapter` when
none is present, and store the values under `_autoscribe_` keys so nothing is lost.

**Verify every meta key against a live install before shipping.** Published sources disagree,
particularly on whether Rank Math uses a leading underscore. Confirm by writing a value in the
plugin UI and reading `wp_postmeta` directly.

Starting points to verify:

| Plugin | Title | Description | Focus keyword |
|---|---|---|---|
| Yoast SEO | `_yoast_wpseo_title` | `_yoast_wpseo_metadesc` | `_yoast_wpseo_focuskw` |
| Rank Math | `rank_math_title` | `rank_math_description` | `rank_math_focus_keyword` |
| SEOPress | `_seopress_titles_title` | `_seopress_titles_desc` | `_seopress_analysis_target_kw` |

Taxonomy handling by `tag_mode`:

- `fixed` — apply `fixed_tags` only.
- `ai` — take `suggested_tags` from the payload. **Match against existing terms first.** Only
  create a new term when no close match exists, or the tag list becomes unusable within a
  month. Cap new-term creation at 3 per post.
- `none` — apply no tags.

Categories always come from `category_ids`. Do not let the model invent categories.

### 7.4 Cost caps

**Pricing table.** Store per-model cost as an editable option: dollars per million input
tokens, dollars per million output tokens, and dollars per image. Seed the defaults, and show
a visible notice that prices change and that the user must verify them. Never present the
figure as authoritative.

**Accounting.** Every provider returns token usage in its response. Record `input_tokens`,
`output_tokens`, and `image_count` on the run row, then compute `cost_cents`.

**Enforcement.**

- `Step_Budget_Check` runs first, before any paid call.
- Sum `cost_cents` for the current calendar month, in the site timezone.
- Check the per-prompt cap, then the global cap. The global cap wins.
- On breach, write the run as `skipped_budget` and stop. Do not run a partial job.
- Send one email at 80 percent of the global cap per month. One only — not one per run.
- Add a "Spend" column to the runs list table and a month-to-date total on the settings page.

Treat the figure as an estimate, and label it that way in the UI. Provider billing is the
authority.

---

## 8. Security

### 8.1 API key storage — be honest about the limits

Support two methods, and prefer the first:

1. **Constants in `wp-config.php`.** `AUTOSCRIBE_ANTHROPIC_KEY`, `AUTOSCRIBE_OPENAI_KEY`, and
   so on. The key never enters the database and never appears in a database backup. This is
   the recommended method. Document it prominently in the README.
2. **Database storage,** encrypted with `sodium_crypto_secretbox()` using a key derived from
   the `AUTH_KEY` and `SECURE_AUTH_KEY` salts.

State the limitation plainly in the README: method 2 is obfuscation, not real protection. An
attacker who can read the database can usually read `wp-config.php` too. It protects against a
leaked database dump. It does not protect against server compromise.

Never echo a stored key back into the settings form. Show a masked placeholder and a "Replace"
control.

### 8.2 Other controls

- Register a custom capability `autoscribe_manage_prompts`. Map it to Administrator on
  activation. Do not use `manage_options` for everything.
- `check_admin_referer()` on every form submission and every AJAX handler.
- `current_user_can()` on every handler. Do not rely on menu visibility.
- Sanitise every input with the correct function. Escape every output at the point of output.
- Set a `timeout` on every `wp_remote_post()` call. Use 120 seconds for generation, 30 for
  everything else.
- Add a `user-agent` header that identifies the plugin and version.

### 8.3 Third-party service disclosure

Because the repo is public, include a clear section in `README.md` that lists, for each
provider: the service name, the endpoint the plugin contacts, exactly what data is sent
(prompt text, and recent post titles when duplicate avoidance is on), and links to the
provider's terms of service and privacy policy.

This is a WordPress.org requirement for hosted plugins. It is also correct practice for a
self-hosted one, and it costs you nothing to do it now.

---

## 9. Admin interface

### 9.1 Menu

```
AutoScribe
├── Prompts          (CPT list table)
├── Add New Prompt
├── Run Log          (custom list table)
└── Settings
```

### 9.2 Prompt editor

Group the meta box into tabs: **Content**, **Schedule**, **Image**, **Publishing**, **Limits**.

Required controls:

- A **"Run now"** button that queues an immediate run and streams the result. This is the
  single most important control in the plugin. Nobody will wait a week to find out that a
  prompt is broken.
- A **"Preview"** button that runs the pipeline through `Step_Generate_Body` and shows the
  output without creating a post. Charge it against the budget and log it.
- A live "Next run: Tuesday, 8 September 2026, 6:00 AM CDT" readout that updates as the
  schedule controls change.
- The site timezone shown next to every time field.

### 9.3 Run Log

Columns: date, prompt, status, title, post link, model, tokens, estimated cost, attempt, error.
Filter by prompt, status, and month. Add a "Retry" row action for failed runs.

### 9.4 Settings page

- Provider credentials (masked, with a "Test connection" button per provider).
- Default model IDs per provider.
- Global monthly budget cap.
- Pricing table editor.
- Notification email address.
- Run-log retention days.
- A system health panel: is `DISABLE_WP_CRON` set, is Action Scheduler running, when did the
  queue last process, is libsodium available.

---

## 10. Human review

Per-prompt setting `post_status_mode`:

- `review` → insert with `post_status = 'draft'`. Send an email to the notification address
  with the title, the first 200 characters, and a direct edit link. Add an admin notice with a
  count of pending AI drafts.
- `auto` → insert with `post_status = 'publish'`.

Add a **global override** in Settings: "Force human review on all prompts." It must win over
every per-prompt setting. This is the safety catch for the moment a provider changes behaviour
or a prompt starts producing garbage.

Set the `_autoscribe_run_id` meta on every created post. It links the post back to its run row
and makes the whole system auditable.

---

## 11. Build phases

Build in this order. Each phase must be working and committed before the next starts.

**Phase 1 — Foundation**
Plugin scaffold, Composer, PHPCS, activation and deactivation hooks, runs table, the
`autoscribe_prompt` CPT, capabilities, `uninstall.php`. No AI calls yet.
*Done when:* the plugin activates and deactivates cleanly, and PHPCS passes with zero errors.

**Phase 2 — Provider layer**
The two interfaces, the registry, all four text adapters and both image adapters, the key
store, and the "Test connection" control.
*Done when:* every configured provider returns a successful test response, and a wrong key
produces a clear error message rather than a fatal error.

**Phase 3 — Single-shot generation**
Structured output contract, validation, the repair retry, content sanitisation, `wp_insert_post`,
featured image sideload, the "Run now" button.
*Done when:* pressing "Run now" produces a real post with a real featured image.

**Phase 4 — Scheduling**
`Next_Run_Calculator` with full unit tests, Action Scheduler integration, the split pipeline
steps, retry handling, the Run Log.
*Done when:* three prompts on three different schedule types all fire at the correct local
times across a month boundary and a DST transition.

**Phase 5 — Power features**
Web search grounding with capability flags, duplicate avoidance, SEO adapters, taxonomy
handling, the pricing table and budget guard.
*Done when:* a budget cap stops a run before any paid call, and a duplicate topic is caught
before the body is generated.

**Phase 6 — Release**
README with the service disclosure, CHANGELOG, i18n and the `.pot` file, GitHub Actions CI
running PHPCS and PHPUnit, a `v1.0.0` tag, and a build script that produces a clean installable
zip.

---

## 12. Repository quality standards

Since the code will be public:

- **PHPCS** with the `WordPress` and `WordPress-Docs` rulesets. Zero errors is the merge gate.
- **PHPUnit** with `wp-phpunit`. Full coverage of `Next_Run_Calculator`, `Budget_Guard`, the
  JSON validator, and `Content_Sanitizer`. Mock every HTTP call — never hit a live API in a test.
- **GitHub Actions** running PHPCS and PHPUnit on PHP 8.1, 8.2, and 8.3.
- **Semantic versioning.** Keep the version string in exactly one place and read it from there.
- **i18n.** Wrap every user-facing string. Generate `autoscribe.pot` with WP-CLI.
- **Composer.** Bundle Action Scheduler as `woocommerce/action-scheduler`. Guard the
  initialisation with `if ( ! class_exists( 'ActionScheduler' ) )`, because WooCommerce may
  already have loaded it. Commit `vendor/` or add a build step — a plain `git clone` into
  `wp-content/plugins` must produce a working plugin.
- **README** must include: what it does, an honest statement that it generates AI content and
  that the user is responsible for what gets published, the service disclosure from §8.3, the
  `wp-config.php` key setup, the system cron setup, and a screenshot.

---

## 13. Out of scope for v1

Record these so they do not creep in:

- Multisite support
- Bulk generation (many posts per run)
- Image generation for in-body images, not only the featured image
- Internal link insertion between generated posts
- Content refresh (regenerating an existing post)
- Social media distribution
- A WordPress MCP server (see §2.3 — a good v2 project)
- Any provider beyond the four named
- Custom post type targets beyond `post` and `page`

---

## 14. Open items to resolve during Phase 2

- Confirm the exact current model IDs for each provider from first-party documentation on the
  day you write each adapter. Do not trust any figure in this brief, including the ones in §2.1.
- Confirm the Rank Math meta key prefix by direct inspection of `wp_postmeta`.
- Decide whether OpenAI text goes through `/v1/responses` or `/v1/chat/completions`. Check
  which one carries the current web search tool.
- Verify the shape of the token usage object in each provider response. They differ.

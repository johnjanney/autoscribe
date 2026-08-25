# The other three fields, revised

Companion to [PEPTIDE-SYSTEM-PROMPT.md](PEPTIDE-SYSTEM-PROMPT.md), which holds
the system prompt itself. Together they are a worked example of an AutoScribe
prompt written against the plugin's actual contract — the ten response fields,
the allowed HTML, and the output ceiling — rather than against a chat interface.
Neither file ships in the zip; `docs` is excluded by `.distignore`.

## Topic instruction

```
The topic for this article is ____________.

Write about the topic and no other topic. If the topic already
appears in the already-covered list, choose a different angle, search intent,
title, and section structure for the topic — never a different topic.
A comparative article may name a related topic only in contrast to the topic,
which remains the subject of the title and the majority of the article.

Target length: about 1,200 words including the References and FAQ sections.
```

## Image house style suffix

```
Premium 16:9 scientific editorial style, clean modern biotechnology aesthetic,
realistic 3D detail, controlled lighting, subtle depth of field, no text. No
people, syringes, pills, product packaging, or medical procedures.
```

This is appended verbatim to whatever the model puts in `image_prompt`, so it
must be style only — no subject, no placeholder, no second set of instructions.

## Prompt settings to change

| Setting | Value | Why |
|---|---|---|
| Target word count | 1,200 | Drives the output ceiling: `2,560 + 4 × words` = 7,360 tokens. The old ~795 gave 5,740, and the article you actually asked for does not fit. |
| Web search grounding | **On** | The prompt bans invented citations and asks for linked references. Without search the model can satisfy only one of those. Google supports grounding. |
| Tag mode | `ai` | Otherwise `suggested_tags` is generated and discarded. Caps at 3 new terms created per post. |
| Post status mode | `review` (draft) | Grounded content is third-party text entering the model context. Keep a human in front of publication while the new prompt settles. |

## What to watch on the first run

With grounding on, the plugin drops strict JSON schema mode — Google will not
reliably do both — and instead spells the schema out in the system prompt. The
contract is then persuasion rather than enforcement, so the first grounded run is
the one worth reading closely for a stray field or a Markdown fence.

If a run still fails, the error now names the ceiling and the tokens spent.
Raise it from `functions.php` rather than assuming a provider fault:

```php
add_filter(
    'autoscribe_body_output_ceiling',
    static function ( $ceiling, $prompt ) {
        return 316 === $prompt->id() ? 12000 : $ceiling;
    },
    10,
    2
);
```

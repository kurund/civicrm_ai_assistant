# AI Assistant (prototype)

Provider-agnostic AI productivity features for CiviCRM. Flagship feature:
**natural-language → SearchKit search** with display-intent detection and iterative
refinement.

## What it does

- `Ai.prompt` (APIv4) - the reusable LLM primitive every feature builds on.
- `Ai.searchKit` (APIv4) - turns a request like _"lapsed United Kingdom donors over $100"_ into
  a transient SearchKit query + a display spec, runs a permission-checked preview, and lets
  you refine it conversationally. Nothing is saved unless you choose to.
- **Entity auto-detection** - The target entity (Contact, Contribution, Membership, …)
  is inferred from the prompt by a small, dedicated LLM
  classification call that tolerates typos and informal wording, before the query is built.
  A deterministic keyword router is the offline fallback, and Contact is the safe default.
  The detected entity is shown as a badge and locked while you refine the same draft. Pass
  `entity` explicitly to override.
- **Print and CSV export** - On the AI Search page (`civicrm/ai-search`), **Print** prints
  the results table without the page chrome, and **Export CSV** downloads the results as a
  UTF-8 CSV that opens cleanly in Excel. The preview shows up to `ai_preview_limit` rows; when
  there are more, **Print all** and **Export CSV** fetch every row through the
  permission-checked API.

## Provider configuration

Default provider is **OpenRouter** (`https://openrouter.ai/api/v1`) because it is
OpenAI-compatible and has a free tier - paste one API key and go. Point the base URL at
OpenAI, Azure, or a local **Ollama/vLLM** server to change provider; no code changes.

Configure at **Administer → Customize Data and Screens → AI Assistant Settings**
(`civicrm/admin/settings/ai-assistant`) - a metadata-driven page grouped into Provider,
Privacy & PII, and Limits sections. Or set values via the `Setting` API:

| Setting                | Default                        |
| ---------------------- | ------------------------------ |
| `ai_provider_base_url` | `https://openrouter.ai/api/v1` |
| `ai_model`             | a `:free` OpenRouter slug      |
| `ai_api_key`           | _(you supply)_                 |
| `ai_redact_pii`        | `TRUE`                         |
| `ai_log_prompts`       | `FALSE`                        |
| `ai_preview_limit`     | `25`                           |
| `ai_max_tokens`        | `2048`                         |

## PII posture (read this)

- The NL→query feature sends only **schema + your prompt** to the model - **never records**.
- The only **guarantee** that PII never leaves your infra is pointing the base URL at a
  **local model**. The default free tier is cloud and may log prompts - treat it as
  evaluation/low-sensitivity only.
- `Redactor` masks structured identifiers (emails/phones) best-effort; it cannot catch plain
  names.

## Usage example

```php
$result = \Civi\Api4\Ai::searchKit()
  ->setPrompt('how many donors in the United Kingdom gave more than £100 last year')
  ->execute()
  ->first();
// $result['display']['type']  => 'single'  (a count -> one number)
// $result['api_params']       => [...]      (transient draft, not saved)
// $result['preview']          => [...]
```

Refine the same draft:

```php
\Civi\Api4\Ai::searchKit()
  ->setPrompt('actually list them with their email, sorted by amount')
  ->setApiParams($result['api_params'])
  ->setDisplay($result['display'])
  ->setMessages([['role' => 'user', 'content' => 'how many donors in the United Kingdom...']])
  ->execute();
// display flips from 'single' to 'table'
```

## Install (dev)

```bash
cv ext:enable ai_assistant
cv api4 Setting.set +v ai_api_key=YOUR_OPENROUTER_KEY
cv api4 Ai.searchKit +v prompt='active members in Exeter'
```

## Layout

```
Civi/Api4/Ai.php                          APIv4 entity
Civi/Api4/Action/Ai/Prompt.php            generic completion
Civi/Api4/Action/Ai/SearchKit.php         NL -> query + display + preview
Civi/AiAssistant/LlmService.php           assembly, redaction, logging
Civi/AiAssistant/Provider/*               OpenAI-compatible transport
Civi/AiAssistant/Redactor.php             best-effort PII masking
Civi/AiAssistant/SchemaContext.php        schema-grounding + entity catalog (no records)
Civi/AiAssistant/EntityRouter.php         prompt -> entity routing (LLM-first, keyword fallback)
Civi/AiAssistant/QueryNormalizer.php      deterministic shape repair (pure)
Civi/AiAssistant/QueryValidator.php       schema-driven field validation (getFields)
```

## How a query is made safe

The model proposes; deterministic code disposes. If the model's reply is not valid JSON, the
request is retried once before an error is shown. After the LLM returns `api_params`:

1. **QueryNormalizer** (pure, unit-tested) repairs shape mistakes without touching the DB:
   - strips disallowed aliases off plain fields;
   - coerces `orderBy` into APIv4's `{field: dir}` form;
   - wraps flat `BETWEEN`/`IN` values into the required nested array;
   - rewrites explicit joins into APIv4's `["Entity AS alias", "INNER"|"LEFT"|"EXCLUDE", [a, "=", b]]`
     form (object form, lowercase or SQL-style join types, missing alias or join type).
2. **QueryValidator** checks every `select`/`where`/`groupBy`/`orderBy` field reference
   against the real schema (APIv4 `getFields`) and **drops** anything that doesn't exist,
   reporting it in `warning`. Implicit joins (`contact_id.display_name`) and explicit join
   aliases (`membership.status_id:name`) are both resolved. It also:
   - **Joins:** drops a join onto an entity the assistant may not query, a join whose alias
     shadows a field, or one whose ON condition references unknown fields. ON operands that
     are values rather than fields are marked as literals.
   - **EXCLUDE joins:** moves `where` filters on an EXCLUDE alias into that join's ON
     condition. APIv4 adds `alias.id IS NULL` for EXCLUDE, so the same filter in `where`
     could never match (e.g. "donated last year but not this year").
   - **Duplicate rows:** a join onto a one-to-many entity repeats the base row per match,
     so a non-aggregate query with such a join is grouped by the base `id`.
   - **Inflated aggregates:** with two or more one-to-many joins, rows multiply. `COUNT`
     becomes `COUNT(DISTINCT ...)`, `GROUP_CONCAT` gains `DISTINCT`, `MIN`/`MAX` and `AVG`
     grouped by the base `id` are kept, and anything that cannot be computed exactly (such as
     `SUM`) is removed with a warning rather than shown wrong.
   - **APIv4 runtime errors:** renames an aggregate alias that collides with a field name
     (`SUM(total_amount) AS total_amount`), rewrites ordering by a bare alias to the
     underlying expression (`total` → `SUM(total_amount)`), and adds every non-aggregated
     selected field to `groupBy` for `ONLY_FULL_GROUP_BY`.
3. The repaired query runs with `checkPermissions = TRUE` for the preview, capped at
   `ai_preview_limit` rows (`preview_truncated` says whether more exist). `api_params` keeps
   the model's own limit, so **Print all** and **Export CSV** on the AI Search page fetch
   every row, still through the permission-checked API.

So a hallucinated field or malformed clause is removed deterministically rather than failing
the query, and a number the query cannot compute correctly is never shown.

## Development

Claude Code was also used in developing this extension.

# AI Assistant (prototype)

Provider-agnostic AI productivity features for CiviCRM. Flagship feature:
**natural-language → SearchKit search** with display-intent detection and iterative
refinement.

## What it does

- `Ai.prompt` (APIv4) — the reusable LLM primitive every feature builds on.
- `Ai.searchKit` (APIv4) — turns a request like _"lapsed United Kingdom donors over $100"_ into
  a transient SearchKit query + a display spec, runs a permission-checked preview, and lets
  you refine it conversationally. Nothing is saved unless you choose to.
- **Entity auto-detection** — The target entity (Contact, Contribution, Membership, …)
  is inferred from the prompt by a small, dedicated LLM
  classification call that tolerates typos and informal wording, before the query is built.
  A deterministic keyword router is the offline fallback, and Contact is the safe default.
  The detected entity is shown as a badge and locked while you refine the same draft. Pass
  `entity` explicitly to override.

## Provider configuration

Default provider is **OpenRouter** (`https://openrouter.ai/api/v1`) because it is
OpenAI-compatible and has a free tier — paste one API key and go. Point the base URL at
OpenAI, Azure, or a local **Ollama/vLLM** server to change provider; no code changes.

Configure at **Administer → Customize Data and Screens → AI Assistant Settings**
(`civicrm/admin/settings/ai-assistant`) — a metadata-driven page grouped into Provider,
Privacy & PII, and Limits sections. Or set values via the `Setting` API:

| Setting                | Default                        |
| ---------------------- | ------------------------------ |
| `ai_provider_base_url` | `https://openrouter.ai/api/v1` |
| `ai_model`             | a `:free` OpenRouter slug      |
| `ai_api_key`           | _(you supply)_                 |
| `ai_redact_pii`        | `TRUE`                         |
| `ai_log_prompts`       | `FALSE`                        |
| `ai_preview_limit`     | `25`                           |

## PII posture (read this)

- The NL→query feature sends only **schema + your prompt** to the model — **never records**.
- The only **guarantee** that PII never leaves your infra is pointing the base URL at a
  **local model**. The default free tier is cloud and may log prompts — treat it as
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

The model proposes; deterministic code disposes. After the LLM returns `api_params`:

1. **QueryNormalizer** (pure, unit-tested) repairs shape mistakes without touching the DB —
   strips disallowed aliases off plain fields, coerces `orderBy` into APIv4's `{field: dir}`
   form, caps the limit.
2. **QueryValidator** checks every `select`/`where`/`groupBy`/`orderBy` field reference
   against the real schema (APIv4 `getFields`, resolving one-level implicit joins) and
   **drops** anything that doesn't exist, reporting it in `warning`.
3. The repaired query runs transiently with `checkPermissions = TRUE` for the preview.

So a hallucinated field or malformed clause is removed deterministically rather than failing
the query — the LLM's correctness matters less over time.

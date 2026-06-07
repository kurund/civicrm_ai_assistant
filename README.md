# AI Assistant (prototype)

Provider-agnostic AI productivity features for CiviCRM. Flagship feature:
**natural-language → SearchKit search** with display-intent detection and iterative
refinement.

> Status: **alpha prototype**. The design rationale lives in
> `../docs/ai-assistant-extension-design.md`.

## What it does

- `Ai.prompt` (APIv4) — the reusable LLM primitive every feature builds on.
- `Ai.searchKit` (APIv4) — turns a request like *"lapsed California donors over $100"* into
  a transient SearchKit query + a display spec, runs a permission-checked preview, and lets
  you refine it conversationally. Nothing is saved unless you choose to.

## Provider configuration

Default provider is **OpenRouter** (`https://openrouter.ai/api/v1`) because it is
OpenAI-compatible and has a free tier — paste one API key and go. Point the base URL at
OpenAI, Azure, or a local **Ollama/vLLM** server to change provider; no code changes.

Configure at **Administer → Customize Data and Screens → AI Assistant Settings**
(`civicrm/admin/settings/ai-assistant`) — a metadata-driven page grouped into Provider,
Privacy & PII, and Limits sections. Or set values via the `Setting` API:

| Setting | Default |
|---|---|
| `ai_provider_base_url` | `https://openrouter.ai/api/v1` |
| `ai_model` | a `:free` OpenRouter slug |
| `ai_api_key` | _(you supply)_ |
| `ai_redact_pii` | `TRUE` |
| `ai_log_prompts` | `FALSE` |
| `ai_preview_limit` | `25` |

## PII posture (read this)

- The NL→query feature sends only **schema + your prompt** to the model — **never records**.
- The only **guarantee** that PII never leaves your infra is pointing the base URL at a
  **local model**. The default free tier is cloud and may log prompts — treat it as
  evaluation/low-sensitivity only.
- `Redactor` masks structured identifiers (emails/phones) best-effort; it cannot catch plain
  names. See §7 of the design doc.

## Usage example

```php
$result = \Civi\Api4\Ai::searchKit()
  ->setPrompt('how many donors in California gave more than $100 last year')
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
  ->setMessages([['role' => 'user', 'content' => 'how many donors in California ...']])
  ->execute();
// display flips from 'single' to 'table'
```

## Install (dev)

```bash
cv ext:enable ai_assistant
cv api4 Setting.set +v ai_api_key=YOUR_OPENROUTER_KEY
cv api4 Ai.searchKit +v prompt='active members in New York'
```

## Status

Scaffolded with `civix` (boilerplate in `ai_assistant.civix.php` is civix-generated — don't
hand-edit). The settings page exists (metadata-driven `CRM_Admin_Form_Generic`). The
remaining UI to build is the **AngularJS search/refine widget** at `civicrm/ai-search`
(see design doc §6); its nav link is intentionally omitted until that page exists.

## Layout

```
info.xml                                  manifest
ai_assistant.php                          hooks (container, permission, nav)
xml/Menu/ai_assistant.xml                 settings page route
settings/ai_assistant.setting.php         provider/privacy settings (tagged to the page)
CRM/AiAssistant/Form/Settings.php         admin settings page (metadata-driven)
Civi/Api4/Ai.php                          APIv4 entity
Civi/Api4/Action/Ai/Prompt.php            generic completion
Civi/Api4/Action/Ai/SearchKit.php         NL -> query + display + preview
Civi/AiAssistant/LlmService.php           assembly, redaction, logging
Civi/AiAssistant/Provider/*               OpenAI-compatible transport
Civi/AiAssistant/Redactor.php             best-effort PII masking
Civi/AiAssistant/SchemaContext.php        schema-grounding (no records)
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

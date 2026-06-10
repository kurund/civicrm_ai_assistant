<?php

use CRM_AiAssistant_ExtensionUtil as E;

$group = [
  'group_name' => 'AI Assistant Preferences',
  'group' => 'ai_assistant',
  'is_domain' => 1,
  'is_contact' => 0,
  'add' => '0.1',
];

// Helper to tag a setting onto the admin page, in a section, at a weight.
$page = fn(string $section, int $weight): array => [
  'settings_pages' => ['ai_assistant' => ['section' => $section, 'weight' => $weight]],
];

return [
  'ai_provider_base_url' => $group + $page('provider', 10) + [
    'name' => 'ai_provider_base_url',
    'type' => 'String',
    'html_type' => 'text',
    'default' => 'https://openrouter.ai/api/v1',
    'title' => E::ts('Provider base URL (OpenAI-compatible)'),
    'description' => E::ts('E.g. https://openrouter.ai/api/v1 (default, has a free tier), https://api.openai.com/v1, or http://localhost:11434/v1 for a local Ollama model.'),
    'help_text' => NULL,
  ],
  'ai_model' => $group + $page('provider', 20) + [
    'name' => 'ai_model',
    'type' => 'String',
    'html_type' => 'text',
    // Free OpenRouter slugs as a fallback chain (primary first). Free models
    // rotate in/out — if these 404, pick current ones at https://openrouter.ai/models
    'default' => 'deepseek/deepseek-v4-flash:free,google/gemma-4-31b-it:free',
    'title' => E::ts('Model'),
    'description' => E::ts('Model slug. For OpenRouter free models use a ":free" slug. Tip: list several comma-separated (primary first) and OpenRouter will fall back to the next when one is rate-limited. Browse https://openrouter.ai/models.'),
    'help_text' => NULL,
  ],
  'ai_api_key' => $group + $page('provider', 30) + [
    'name' => 'ai_api_key',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'title' => E::ts('API key'),
    'description' => E::ts('Bearer token for the provider. Leave blank for keyless local servers. Stored encrypted where the crypto service is configured.'),
    'help_text' => NULL,
  ],
  'ai_referer' => $group + $page('provider', 40) + [
    'name' => 'ai_referer',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'title' => E::ts('Attribution: site URL (optional)'),
    'description' => E::ts('Sent as the OpenRouter HTTP-Referer header for leaderboard attribution. Ignored by other providers.'),
    'help_text' => NULL,
  ],
  'ai_title' => $group + $page('provider', 50) + [
    'name' => 'ai_title',
    'type' => 'String',
    'html_type' => 'text',
    'default' => 'CiviCRM',
    'title' => E::ts('Attribution: app title (optional)'),
    'description' => E::ts('Sent as the OpenRouter X-OpenRouter-Title header. Ignored by other providers.'),
    'help_text' => NULL,
  ],
  'ai_request_timeout' => $group + $page('provider', 60) + [
    'name' => 'ai_request_timeout',
    'type' => 'Integer',
    'html_type' => 'number',
    'default' => 60,
    'title' => E::ts('Request timeout (seconds)'),
    'description' => E::ts('Local and free-tier models can be slow.'),
    'help_text' => NULL,
  ],
  'ai_redact_pii' => $group + $page('privacy', 10) + [
    'name' => 'ai_redact_pii',
    'type' => 'Boolean',
    'html_type' => 'toggle',
    'default' => TRUE,
    'title' => E::ts('Redact structured PII before sending'),
    'description' => E::ts('Best-effort masking of emails, phones and similar identifiers in prompts/context before any external call. Cannot reliably catch plain names; use a local model for guaranteed isolation.'),
    'help_text' => NULL,
  ],
  'ai_log_prompts' => $group + $page('privacy', 20) + [
    'name' => 'ai_log_prompts',
    'type' => 'Boolean',
    'html_type' => 'toggle',
    'default' => FALSE,
    'title' => E::ts('Audit-log prompts and responses'),
    'description' => E::ts('Record exactly what was sent and received, for transparency and incident review.'),
    'help_text' => NULL,
  ],
  // NOTE: ai_max_rows_context (cap on sample record rows sent to the model) was
  // removed in v0.1 — the NL->query feature sends no records, so nothing used it.
  // Re-add it alongside the first feature that sends row data (e.g. summarisation).
  'ai_preview_limit' => $group + $page('limits', 10) + [
    'name' => 'ai_preview_limit',
    'type' => 'Integer',
    'html_type' => 'number',
    'default' => 25,
    'title' => E::ts('Preview row limit'),
    'description' => E::ts('Maximum rows fetched when previewing a generated search.'),
    'help_text' => NULL,
  ],
  'ai_max_tokens' => $group + $page('limits', 20) + [
    'name' => 'ai_max_tokens',
    'type' => 'Integer',
    'html_type' => 'number',
    'default' => 1024,
    'title' => E::ts('Max response tokens'),
    'description' => E::ts('Upper bound on tokens the model may generate per call. Raise it if generated queries look truncated (common with local models, whose default output budget is small); lower it to cap cost.'),
    'help_text' => NULL,
  ],
];

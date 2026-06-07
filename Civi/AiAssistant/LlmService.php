<?php

namespace Civi\AiAssistant;

use Civi\AiAssistant\Provider\OpenAiCompatibleProvider;
use Civi\AiAssistant\Provider\ProviderInterface;

/**
 * High-level entry point for all AI features. Handles message assembly,
 * best-effort redaction, audit logging and error handling. Registered in the
 * container as 'ai.llm' (see ai_assistant_civicrm_container).
 *
 * Every productivity feature should call this rather than a provider directly.
 */
class LlmService {

  private ?ProviderInterface $provider = NULL;

  public function provider(): ProviderInterface {
    if ($this->provider === NULL) {
      // Swappable; only an OpenAI-compatible transport is shipped today.
      $this->provider = new OpenAiCompatibleProvider();
    }
    return $this->provider;
  }

  /**
   * Run a single completion.
   *
   * @param string|null $system  System prompt (instructions).
   * @param array $messages      User/assistant turns: [['role'=>'user','content'=>...], ...].
   * @param array $options       'json' => bool, 'temperature' => float, 'model' => string.
   *
   * @return string Raw assistant text (JSON string when 'json' => TRUE).
   */
  public function complete(?string $system, array $messages, array $options = []): string {
    $payload = [];
    if ($system !== NULL && $system !== '') {
      $payload[] = ['role' => 'system', 'content' => $system];
    }
    foreach ($messages as $m) {
      $payload[] = [
        'role' => $m['role'] ?? 'user',
        'content' => Redactor::scrub((string) ($m['content'] ?? '')),
      ];
    }

    $response = $this->provider()->chat($payload, $options);
    $this->maybeLog($payload, $response, $options);
    return $response;
  }

  /**
   * Decode a JSON completion, tolerating models that wrap JSON in prose or
   * ```json fences.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function completeJson(?string $system, array $messages, array $options = []): array {
    $options['json'] = TRUE;
    $raw = $this->complete($system, $messages, $options);
    $decoded = self::extractJson($raw);
    if ($decoded === NULL) {
      throw new \CRM_Core_Exception('The AI response could not be parsed as JSON.');
    }
    return $decoded;
  }

  /**
   * Pull the first JSON object out of a string.
   */
  public static function extractJson(string $raw): ?array {
    $raw = trim($raw);
    $decoded = json_decode($raw, TRUE);
    if (is_array($decoded)) {
      return $decoded;
    }
    // Strip ```json ... ``` fences or surrounding prose.
    if (preg_match('/\{.*\}/s', $raw, $m)) {
      $decoded = json_decode($m[0], TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
    }
    return NULL;
  }

  /**
   * Write an audit Activity when logging is enabled. Best-effort; never breaks
   * the user flow.
   */
  private function maybeLog(array $sentMessages, string $response, array $options): void {
    if (!\Civi::settings()->get('ai_log_prompts')) {
      return;
    }
    try {
      $detail = json_encode([
        'model' => $options['model'] ?? \Civi::settings()->get('ai_model'),
        'sent' => $sentMessages,
        'response' => $response,
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      \Civi::log('ai_assistant')->info('AI call', ['detail' => $detail]);
    }
    catch (\Throwable $e) {
      // Swallow: logging must never interfere with the feature.
    }
  }

}

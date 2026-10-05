<?php

namespace Civi\AiAssistant;

use Civi\AiAssistant\Provider\OpenAiCompatibleProvider;
use Civi\AiAssistant\Provider\ProviderInterface;

/**
 * Entry point for AI calls (service 'ai.llm'): redaction, logging, JSON decoding.
 */
class LlmService {

  private ?ProviderInterface $provider = NULL;

  public function provider(): ProviderInterface {
    if ($this->provider === NULL) {
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
   * ```json fences. An unparseable reply is retried once.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function completeJson(?string $system, array $messages, array $options = []): array {
    $options['json'] = TRUE;
    $decoded = self::extractJson($this->complete($system, $messages, $options));
    if ($decoded === NULL) {
      $messages[] = [
        'role' => 'user',
        'content' => 'Your previous reply was not valid JSON. Reply with ONLY the JSON object, no other text.',
      ];
      $decoded = self::extractJson($this->complete($system, $messages, $options));
    }
    if ($decoded === NULL) {
      throw new \CRM_Core_Exception('The AI response could not be read (it was not valid JSON), even after retrying. Please try again.');
    }
    return $decoded;
  }

  /**
   * Pull the first JSON object out of a model response, tolerating the ways
   * local/instruct models dress it up: a <think>...</think> reasoning preamble,
   * ```json ... ``` fences, or trailing commentary.
   */
  public static function extractJson(string $raw): ?array {
    $raw = trim($raw);

    // Drop a leading reasoning block some local models emit before the answer.
    $raw = preg_replace('#<think\b[^>]*>.*?</think>#is', '', $raw) ?? $raw;

    if (preg_match('/```(?:json)?\s*(.+?)```/is', $raw, $m)) {
      $raw = trim($m[1]);
    }

    $decoded = json_decode(trim($raw), TRUE);
    if (is_array($decoded)) {
      return $decoded;
    }

    // Prose around the JSON can contain more objects or stray braces.
    $candidate = self::firstJsonObject($raw);
    if ($candidate !== NULL) {
      $decoded = json_decode($candidate, TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
    }
    return NULL;
  }

  /**
   * Return the first brace-balanced {...} substring, or NULL. String-literal
   * aware, so a "}" inside a value does not close the object prematurely.
   */
  private static function firstJsonObject(string $s): ?string {
    $start = strpos($s, '{');
    if ($start === FALSE) {
      return NULL;
    }
    $depth = 0;
    $inString = FALSE;
    $escaped = FALSE;
    $len = strlen($s);
    for ($i = $start; $i < $len; $i++) {
      $ch = $s[$i];
      if ($inString) {
        if ($escaped) {
          $escaped = FALSE;
        }
        elseif ($ch === '\\') {
          $escaped = TRUE;
        }
        elseif ($ch === '"') {
          $inString = FALSE;
        }
        continue;
      }
      if ($ch === '"') {
        $inString = TRUE;
      }
      elseif ($ch === '{') {
        $depth++;
      }
      elseif ($ch === '}' && --$depth === 0) {
        return substr($s, $start, $i - $start + 1);
      }
    }
    return NULL;
  }

  /**
   * Log the exchange when ai_log_prompts is enabled.
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
    }
  }

}

<?php

namespace Civi\AiAssistant\Provider;

/**
 * Single provider covering the entire OpenAI-compatible ecosystem:
 * OpenRouter (default), OpenAI, Azure OpenAI, Ollama, vLLM, llama.cpp, etc.
 *
 * Switching provider is purely a settings change (base URL + model + key);
 * this class never needs editing.
 */
class OpenAiCompatibleProvider implements ProviderInterface {

  public function chat(array $messages, array $options = []): string {
    $baseUrl = rtrim((string) \Civi::settings()->get('ai_provider_base_url'), '/');
    $modelSetting = $options['model'] ?? (string) \Civi::settings()->get('ai_model');
    $apiKey = (string) \Civi::settings()->get('ai_api_key');
    $timeout = (int) (\Civi::settings()->get('ai_request_timeout') ?: 60);
    $maxTokens = (int) ($options['max_tokens'] ?? (\Civi::settings()->get('ai_max_tokens') ?: 1024));

    if ($baseUrl === '' || trim($modelSetting) === '') {
      throw new \CRM_Core_Exception('AI Assistant is not configured: set the provider base URL and model.');
    }

    // The model setting may be a comma-separated list. The first is the primary;
    // any others are OpenRouter fallbacks, tried in order when the primary is
    // unavailable or rate-limited (free models 429 often). Ignored by providers
    // that don't support the `models` field.
    $models = array_values(array_filter(array_map('trim', explode(',', $modelSetting))));
    $payload = [
      'model' => $models[0],
      'messages' => array_values($messages),
      'temperature' => $options['temperature'] ?? 0.2,
    ];
    // Cap (and reserve) output length. The standard OpenAI field; Ollama's
    // OpenAI-compatible layer maps it to num_predict, so a long JSON query is
    // not truncated mid-object (its default predict budget is small).
    if ($maxTokens > 0) {
      $payload['max_tokens'] = $maxTokens;
    }
    if (count($models) > 1) {
      $payload['models'] = $models;
    }
    if (!empty($options['json'])) {
      // OpenAI-compatible structured output hint. Providers that don't support
      // it ignore the field; we also instruct JSON in the system prompt.
      $payload['response_format'] = ['type' => 'json_object'];
    }

    $headers = ['Content-Type' => 'application/json'];
    if ($apiKey !== '') {
      $headers['Authorization'] = 'Bearer ' . $apiKey;
    }
    // Optional OpenRouter attribution headers (harmlessly ignored elsewhere).
    $referer = (string) \Civi::settings()->get('ai_referer');
    $title = (string) \Civi::settings()->get('ai_title');
    if ($referer !== '') {
      $headers['HTTP-Referer'] = $referer;
    }
    if ($title !== '') {
      $headers['X-OpenRouter-Title'] = $title;
    }

    $client = new \GuzzleHttp\Client([
      'timeout' => $timeout,
      'connect_timeout' => 10,
    ]);

    // Up to 2 attempts: one short retry on transient rate-limit/server errors
    // (e.g. free-model 429s).
    $response = NULL;
    $maxAttempts = 2;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
      try {
        $response = $client->post($baseUrl . '/chat/completions', [
          'headers' => $headers,
          'json' => $payload,
        ]);
        break;
      }
      catch (\GuzzleHttp\Exception\RequestException $e) {
        $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
        if (in_array($status, [429, 502, 503], TRUE) && $attempt < $maxAttempts) {
          sleep(2);
          continue;
        }
        if ($status === 429) {
          throw new \CRM_Core_Exception(
            'The AI model is rate-limited right now (HTTP 429). This is common on free models — '
            . 'try a different model, list fallback models (comma-separated) in settings, add provider credit, or use a local model.'
          );
        }
        throw new \CRM_Core_Exception('AI provider request failed: ' . $e->getMessage());
      }
      catch (\GuzzleHttp\Exception\GuzzleException $e) {
        throw new \CRM_Core_Exception('AI provider request failed: ' . $e->getMessage());
      }
    }

    $body = json_decode((string) $response->getBody(), TRUE);
    $content = $body['choices'][0]['message']['content'] ?? NULL;
    if (!is_string($content) || $content === '') {
      throw new \CRM_Core_Exception('AI provider returned an empty or unexpected response.');
    }
    return $content;
  }

}

<?php

namespace Civi\AiAssistant\Provider;

/**
 * Chat completions against any OpenAI-compatible endpoint (OpenRouter, OpenAI,
 * Azure, Ollama, vLLM, ...).
 */
class OpenAiCompatibleProvider implements ProviderInterface {

  public function chat(array $messages, array $options = []): string {
    $baseUrl = rtrim((string) \Civi::settings()->get('ai_provider_base_url'), '/');
    $modelSetting = $options['model'] ?? (string) \Civi::settings()->get('ai_model');
    $apiKey = (string) \Civi::settings()->get('ai_api_key');
    $timeout = (int) (\Civi::settings()->get('ai_request_timeout') ?: 60);
    $maxTokens = (int) ($options['max_tokens'] ?? (\Civi::settings()->get('ai_max_tokens') ?: 2048));

    if ($baseUrl === '' || trim($modelSetting) === '') {
      throw new \CRM_Core_Exception('AI Assistant is not configured: set the provider base URL and model.');
    }

    // Extra comma-separated models go in OpenRouter's `models` fallback list;
    // other providers ignore that field.
    $models = array_values(array_filter(array_map('trim', explode(',', $modelSetting))));
    $payload = [
      'model' => $models[0],
      'messages' => array_values($messages),
      'temperature' => $options['temperature'] ?? 0.2,
    ];
    // Ollama maps max_tokens to num_predict, whose small default truncates JSON.
    if ($maxTokens > 0) {
      $payload['max_tokens'] = $maxTokens;
    }
    if (count($models) > 1) {
      $payload['models'] = $models;
    }
    if (!empty($options['json'])) {
      $payload['response_format'] = ['type' => 'json_object'];
    }

    $headers = ['Content-Type' => 'application/json'];
    if ($apiKey !== '') {
      $headers['Authorization'] = 'Bearer ' . $apiKey;
    }
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
            'The AI model is rate-limited right now (HTTP 429). This is common on free models - '
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

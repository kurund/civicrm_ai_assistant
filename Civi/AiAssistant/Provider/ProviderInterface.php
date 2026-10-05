<?php

namespace Civi\AiAssistant\Provider;

/**
 * An LLM chat transport.
 */
interface ProviderInterface {

  /**
   * Send a chat-completion request and return the assistant's text reply.
   *
   * @param array $messages
   *   Ordered chat messages, e.g.
   *   [['role' => 'system', 'content' => '...'], ['role' => 'user', 'content' => '...']]
   * @param array $options
   *   Supported keys: 'temperature' (float), 'json' (bool, request JSON output),
   *   'model' (string, override the configured model).
   *
   * @return string
   *   Raw assistant message content (a JSON string when 'json' => TRUE).
   *
   * @throws \CRM_Core_Exception
   */
  public function chat(array $messages, array $options = []): string;

}

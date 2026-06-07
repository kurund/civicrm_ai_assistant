<?php

namespace Civi\Api4\Action\Ai;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Generic LLM completion — the reusable primitive.
 *
 * @method $this setSystem(?string $system)
 * @method string|null getSystem()
 * @method $this setMessages(array $messages)
 * @method array getMessages()
 * @method $this setJson(bool $json)
 * @method bool getJson()
 * @method $this setTemperature(float $t)
 */
class Prompt extends AbstractAction {

  /**
   * System prompt / instructions.
   * @var string|null
   */
  protected ?string $system = NULL;

  /**
   * Chat turns: [['role' => 'user', 'content' => '...'], ...].
   * @var array
   */
  protected array $messages = [];

  /**
   * Request JSON output.
   * @var bool
   */
  protected bool $json = FALSE;

  /**
   * @var float
   */
  protected float $temperature = 0.2;

  public function _run(Result $result) {
    if ($this->checkPermissions && !\CRM_Core_Permission::check('use ai assistant')) {
      throw new \Civi\API\Exception\UnauthorizedException('Permission denied: use ai assistant');
    }
    if (empty($this->messages)) {
      throw new \CRM_Core_Exception('Ai.prompt requires at least one message.');
    }

    /** @var \Civi\AiAssistant\LlmService $llm */
    $llm = \Civi::service('ai.llm');
    $options = ['temperature' => $this->temperature, 'json' => $this->json];

    if ($this->json) {
      $result[] = ['response' => $llm->completeJson($this->system, $this->messages, $options)];
    }
    else {
      $result[] = ['response' => $llm->complete($this->system, $this->messages, $options)];
    }
  }

}

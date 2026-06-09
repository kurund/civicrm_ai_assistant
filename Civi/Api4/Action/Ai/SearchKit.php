<?php

namespace Civi\Api4\Action\Ai;

use Civi\AiAssistant\EntityRouter;
use Civi\AiAssistant\SchemaContext;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Natural-language -> SearchKit query + display spec.
 *
 * Produces a TRANSIENT draft (nothing is persisted): an APIv4 `api_params`
 * object, a `display` spec (single|table|list|chart) inferred from the request,
 * a few preview rows, and a plain-language summary. Pass an existing `apiParams`
 * (and `display`, `messages`) back in to fine-tune the same draft iteratively.
 *
 * Safety: the model only emits a query spec; CiviCRM validates it and runs it
 * with checkPermissions = TRUE, so ACLs always apply and a bad/adversarial spec
 * fails safe. Only schema + the prompt are sent to the LLM — never records.
 *
 * @method $this setPrompt(string $prompt)
 * @method string getPrompt()
 * @method $this setEntity(?string $entity)
 * @method string|null getEntity()
 * @method $this setApiParams(?array $apiParams)
 * @method array|null getApiParams()
 * @method $this setDisplay(?array $display)
 * @method array|null getDisplay()
 * @method $this setMessages(array $messages)
 * @method array getMessages()
 */
class SearchKit extends AbstractAction {

  /**
   * Natural-language request, or a refinement instruction when refining.
   * @var string
   */
  protected string $prompt = '';

  /**
   * Target entity. Optional override; when NULL it is auto-detected from the
   * prompt (EntityRouter), defaulting to Contact.
   * @var string|null
   */
  protected ?string $entity = NULL;

  /**
   * Current draft to refine (NULL = generate a new query).
   * @var array|null
   */
  protected ?array $apiParams = NULL;

  /**
   * Current display spec to refine (NULL = let the model choose).
   * @var array|null
   */
  protected ?array $display = NULL;

  /**
   * Prior conversation turns for refinement context.
   * @var array
   */
  protected array $messages = [];

  private const ALLOWED_PARAM_KEYS = [
    'select', 'where', 'having', 'groupBy', 'join', 'orderBy', 'limit', 'offset',
  ];

  private const ALLOWED_DISPLAY_TYPES = ['single', 'table', 'list', 'chart'];

  public function _run(Result $result) {
    if ($this->checkPermissions && !\CRM_Core_Permission::check('use ai assistant')) {
      throw new \Civi\API\Exception\UnauthorizedException('Permission denied: use ai assistant');
    }
    if (trim($this->prompt) === '') {
      throw new \CRM_Core_Exception('Ai.searchKit requires a prompt.');
    }

    /** @var \Civi\AiAssistant\LlmService $llm */
    $llm = \Civi::service('ai.llm');

    $entity = $this->resolveEntity($llm);
    if (!SchemaContext::isAllowed($entity)) {
      throw new \CRM_Core_Exception("Entity not permitted for AI search: {$entity}");
    }

    $system = $this->buildSystemPrompt($entity);
    $messages = $this->buildMessages();

    $decoded = $llm->completeJson($system, $messages, ['temperature' => 0.1]);

    // 1. Normalize shape (strip bad aliases, fix orderBy form, cap limit).
    $apiParams = $this->sanitizeParams($decoded['api_params'] ?? []);
    // 2. Validate every field reference against the real schema; drop unknowns.
    $validated = \Civi\AiAssistant\QueryValidator::validate($entity, $apiParams);
    $apiParams = $validated['params'];
    $issues = $validated['issues'];

    $keys = array_map([\Civi\AiAssistant\QueryNormalizer::class, 'selectResultKey'], $apiParams['select']);
    $display = $this->sanitizeDisplay($decoded['display'] ?? NULL, $apiParams, $keys);
    $summary = (string) ($decoded['summary'] ?? '');
    $changed = (string) ($decoded['changed'] ?? '');

    // 3. Run it transiently for a preview (ACL-checked, capped).
    [$preview, $previewError] = $this->preview($entity, $apiParams);

    $warnings = $issues;
    if ($previewError !== NULL) {
      $warnings[] = $previewError;
    }

    $result[] = [
      'api_entity' => $entity,
      'api_params' => $apiParams,
      'display' => $display,
      'preview' => $preview,
      'summary' => $summary,
      'changed' => $changed,
      'warning' => $warnings ? implode(' ', $warnings) : NULL,
    ];
  }

  private function buildSystemPrompt(string $entity): string {
    $schema = SchemaContext::asPromptBlock($entity);
    $current = '';
    if (!empty($this->apiParams)) {
      $current = "\n\nThe user is REFINING this existing api_params (edit it, don't start over):\n"
        . json_encode($this->apiParams, JSON_UNESCAPED_SLASHES);
      if (!empty($this->display)) {
        $current .= "\nCurrent display spec:\n" . json_encode($this->display, JSON_UNESCAPED_SLASHES);
      }
    }

    return <<<TXT
You translate a user's request into a CiviCRM APIv4 query for the "{$entity}" entity, AND choose how to display the result. Output is consumed by APIv4 `{$entity}.get`, so it MUST follow APIv4 syntax EXACTLY.

Return ONLY a JSON object with these keys:
- "api_params": APIv4 get params. Allowed keys: select, where, having, groupBy, join, orderBy, limit, offset.
- "display": {"type": "single"|"table"|"list"|"chart", "columns": [{"label": string, "key": <select expression or its alias>}], "format": optional ("currency"|"integer")}.
- "summary": one sentence describing what the query returns.
- "changed": (only when refining) one sentence on what changed.

APIv4 syntax rules — follow precisely:
- "select": array of strings. ONLY function expressions may use "AS alias" — e.g. "COUNT(id) AS cnt", "SUM(total_amount) AS total". NEVER alias a plain field: write "contact_id.display_name", NOT "contact_id.display_name AS donor". When you use an aggregate, every non-aggregated selected field must also appear in groupBy.
- To read a field on a RELATED entity, use the foreign-key field, a dot, then the field: e.g. "contact_id.display_name", NOT "contact.display_name". Only use joins you are confident exist.
- In "display".columns and "orderBy", reference a plain field by its full path (e.g. "contact_id.display_name") and an aggregate by its alias (e.g. "total").
- "where": array of [field, operator, value]. Operators: "=", "!=", ">", "<", ">=", "<=", "IN", "NOT IN", "LIKE", "IS NULL", "IS NOT NULL", "BETWEEN". For IS NULL / IS NOT NULL omit the value: [field, "IS NOT NULL"].
- "orderBy": an OBJECT mapping a field or select-alias to "ASC" or "DESC". CORRECT: {"total": "DESC"}. WRONG: [["total","DESC"]].
- "groupBy": array of field names (no aliases).
- "limit": integer.
- Use ONLY field names from the list below (plus implicit-join paths described above). Do not invent fields.

Example (top contributors): {"select":["contact_id.display_name AS donor","SUM(total_amount) AS total"],"groupBy":["contact_id"],"orderBy":{"total":"DESC"},"limit":10}

Display intent rules:
- Counting / totals / averages / "how many" / "total" -> "single" with a single aggregate in select (e.g. "COUNT(id) AS total"); returns one row/one value.
- "list" / "show" / "who are" / "which" -> "table" with sensible columns.
- "by X" / grouped counts -> "table" with groupBy (or "chart" if a chart is requested).
- A single specific record -> "list".
- If ambiguous, default to "table".

Available fields for {$entity}:
{$schema}
{$current}
TXT;
  }

  private function buildMessages(): array {
    $messages = [];
    foreach ($this->messages as $m) {
      if (!empty($m['content'])) {
        $messages[] = ['role' => $m['role'] ?? 'user', 'content' => (string) $m['content']];
      }
    }
    $messages[] = ['role' => 'user', 'content' => $this->prompt];
    return $messages;
  }

  /**
   * Decide which entity to query. An explicit, permitted `entity` always wins
   * (the UI passes the locked entity back when refining). Otherwise, for a fresh
   * request, auto-detect it from the prompt; refinements default to the base
   * entity since routing on a "tweak" instruction is unreliable.
   */
  private function resolveEntity(\Civi\AiAssistant\LlmService $llm): string {
    if ($this->entity && SchemaContext::isAllowed($this->entity)) {
      return $this->entity;
    }
    if (!empty($this->apiParams)) {
      return 'Contact';
    }
    return EntityRouter::detect(
      $this->prompt,
      fn(string $prompt): string => $this->classifyEntity($llm, $prompt)
    );
  }

  /**
   * Ask the model which entity the request targets, before building the real
   * query — a cheap call that tolerates typos and informal wording. Best-effort:
   * any failure returns '' so EntityRouter falls back to keyword routing.
   */
  private function classifyEntity(\Civi\AiAssistant\LlmService $llm, string $prompt): string {
    $list = implode(', ', SchemaContext::$allowedEntities);
    $catalog = SchemaContext::catalogBlock();
    $system = "You route a CiviCRM search request to the single entity whose own records best answer it. "
      . "The request may contain typos, abbreviations or informal wording — infer intent. "
      . "Prefer Contact unless the request is fundamentally about another entity's records or aggregates. "
      . "Reply with ONLY JSON: {\"entity\": \"<one of: {$list}>\"}.\n\nEntities:\n{$catalog}";
    try {
      $decoded = $llm->completeJson($system, [['role' => 'user', 'content' => $prompt]], ['temperature' => 0]);
      return (string) ($decoded['entity'] ?? '');
    }
    catch (\Throwable $e) {
      return '';
    }
  }

  /**
   * Normalize query shape (no schema lookups): keep allowed keys, strip bad
   * aliases, coerce orderBy, drop empty where, and cap the limit. Field-level
   * validation against the real schema happens in QueryValidator.
   */
  private function sanitizeParams(array $params): array {
    $clean = array_intersect_key($params, array_flip(self::ALLOWED_PARAM_KEYS));
    if (empty($clean['select']) || !is_array($clean['select'])) {
      $clean['select'] = ['id'];
    }
    $clean['select'] = array_values(array_map(
      [\Civi\AiAssistant\QueryNormalizer::class, 'cleanSelectItem'],
      $clean['select']
    ));
    if (isset($clean['orderBy'])) {
      $clean['orderBy'] = \Civi\AiAssistant\QueryNormalizer::normalizeOrderBy($clean['orderBy']);
      if (!$clean['orderBy']) {
        unset($clean['orderBy']);
      }
    }
    if (isset($clean['where']) && !$clean['where']) {
      unset($clean['where']);
    }
    $cap = (int) (\Civi::settings()->get('ai_preview_limit') ?: 25);
    $clean['limit'] = min((int) ($clean['limit'] ?? $cap), $cap);
    return $clean;
  }

  /**
   * Validate the display spec and align its columns to the ACTUAL result keys,
   * so a stripped alias can't leave a column pointing at a nonexistent field.
   *
   * @param string[] $keys  Result keys derived from the cleaned select.
   */
  private function sanitizeDisplay(?array $display, array $apiParams, array $keys): array {
    $type = $display['type'] ?? 'table';
    if (!in_array($type, self::ALLOWED_DISPLAY_TYPES, TRUE)) {
      $type = 'table';
    }
    // A "single" display must come from a one-value query.
    if ($type === 'single' && count($keys) > 1) {
      $type = 'table';
    }

    // Rebuild columns positionally from the real keys, preserving the model's
    // labels/formats where it supplied them.
    $modelCols = is_array($display['columns'] ?? NULL) ? array_values($display['columns']) : [];
    $columns = [];
    foreach ($keys as $i => $key) {
      $col = ['key' => $key, 'label' => $modelCols[$i]['label'] ?? \Civi\AiAssistant\QueryNormalizer::prettifyLabel($key)];
      if (!empty($modelCols[$i]['format'])) {
        $col['format'] = $modelCols[$i]['format'];
      }
      $columns[] = $col;
    }

    return [
      'type' => $type,
      'columns' => $columns,
      'format' => $display['format'] ?? NULL,
    ];
  }

  /**
   * Run the draft transiently to produce preview rows. Never persists anything.
   * Returns [rows, warningOrNull].
   */
  private function preview(string $entity, array $apiParams): array {
    try {
      $rows = civicrm_api4($entity, 'get', $apiParams + ['checkPermissions' => TRUE]);
      return [$rows->getArrayCopy(), NULL];
    }
    catch (\Throwable $e) {
      // Return the draft anyway so the user can fine-tune it; surface the error.
      return [[], 'Preview could not run: ' . $e->getMessage()];
    }
  }

}

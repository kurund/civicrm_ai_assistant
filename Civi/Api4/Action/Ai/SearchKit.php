<?php

namespace Civi\Api4\Action\Ai;

use Civi\AiAssistant\EntityRouter;
use Civi\AiAssistant\SchemaContext;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Natural-language -> unsaved APIv4 query, display spec, preview rows and
 * summary. Pass apiParams, display and messages back in to refine the draft.
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
   * Target entity; auto-detected from the prompt when NULL.
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

    $apiParams = $this->sanitizeParams($decoded['api_params'] ?? []);
    $validated = \Civi\AiAssistant\QueryValidator::validate($entity, $apiParams);
    $apiParams = $validated['params'];
    $issues = $validated['issues'];

    $keys = array_map([\Civi\AiAssistant\QueryNormalizer::class, 'selectResultKey'], $apiParams['select']);
    $display = $this->sanitizeDisplay($decoded['display'] ?? NULL, $keys);
    $summary = (string) ($decoded['summary'] ?? '');
    $changed = (string) ($decoded['changed'] ?? '');

    [$preview, $previewTruncated, $previewError] = $this->preview($entity, $apiParams);

    $warnings = $issues;
    if ($previewError !== NULL) {
      $warnings[] = $previewError;
    }

    $result[] = [
      'api_entity' => $entity,
      'api_params' => $apiParams,
      'display' => $display,
      'preview' => $preview,
      'preview_truncated' => $previewTruncated,
      'summary' => $summary,
      'changed' => $changed,
      'warning' => $warnings ? implode(' ', $warnings) : NULL,
    ];
  }

  private function buildSystemPrompt(string $entity): string {
    $schema = SchemaContext::asPromptBlock($entity);
    $joinable = implode(', ', array_diff(SchemaContext::$allowedEntities, [$entity]));
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

APIv4 syntax rules - follow precisely:
- "select": array of strings. ONLY function expressions may use "AS alias" - e.g. "COUNT(id) AS cnt", "SUM(total_amount) AS total". NEVER alias a plain field: write "contact_id.display_name", NOT "contact_id.display_name AS donor". When you use an aggregate, every non-aggregated selected field must also appear in groupBy.
- An alias must NOT be the same as an existing field name. For SUM(total_amount) write "SUM(total_amount) AS total" (or "AS total_sum"), NEVER "AS total_amount".
- To read a field on a RELATED entity, use the foreign-key field, a dot, then the field: e.g. "contact_id.display_name", NOT "contact.display_name".
- A contact's email/phone/address are SEPARATE related records, not plain fields. Read the primary one via an implicit join: "contact_id.email_primary.email", "contact_id.phone_primary.phone", "contact_id.address_primary.street_address". There is NO "contact_id.email" or "contact_id.phone" field. (When the base entity IS Contact, drop the "contact_id." prefix: "email_primary.email".)
- In "display".columns and "orderBy", reference a plain field by its full path (e.g. "contact_id.display_name") and an aggregate by its alias (e.g. "total").
- "where": array of [field, operator, value]. Operators: "=", "!=", ">", "<", ">=", "<=", "IN", "NOT IN", "LIKE", "IS NULL", "IS NOT NULL", "BETWEEN". For IS NULL / IS NOT NULL omit the value: [field, "IS NOT NULL"]. For BETWEEN and IN the value is a SINGLE array: ["receive_date", "BETWEEN", ["2026-01-01", "2026-12-31"]], ["status_id", "IN", [1, 2]] - NOT [field, "BETWEEN", lo, hi].
- "orderBy": an OBJECT mapping a field or select-alias to "ASC" or "DESC". CORRECT: {"total": "DESC"}. WRONG: [["total","DESC"]].
- "groupBy": array of field names (no aliases).
- "limit": integer.
- Use ONLY field names from the list below (plus implicit-join paths described above and fields of entities you join). Do not invent fields.

Explicit joins - use when the request combines records of ANOTHER entity that points back at this one (e.g. contacts who have a membership AND a contribution):
- "join": array of ["Entity AS alias", "INNER"|"LEFT"|"EXCLUDE", [left, "=", right]]. INNER = must have a matching record, EXCLUDE = must NOT have one, LEFT = optional (to show its fields).
- Joinable entities: {$joinable}.
- The ON condition links the two by field names, e.g. ["id", "=", "membership.contact_id"] from Contact, or ["contact_id", "=", "membership.contact_id"] from Contribution.
- Refer to a joined entity's fields with the alias prefix: "membership.membership_type_id:label", "membership.status_id:name". Put filters on joined fields in "where", not in the ON condition - including the filters that define what an EXCLUDE join excludes (e.g. "no contribution THIS year": EXCLUDE "Contribution AS this_year" plus a where on this_year.receive_date).
- Joins repeat the base row once per match, so the result is grouped by the base "id" automatically. Never SUM/COUNT one joined entity while also INNER-joining a different one-to-many entity (the totals get multiplied); use COUNT(DISTINCT alias.id) for counts.

Example (top contributors): {"select":["contact_id.display_name AS donor","SUM(total_amount) AS total"],"groupBy":["contact_id"],"orderBy":{"total":"DESC"},"limit":10}

Example (Contact: donors who are also current members): {"select":["display_name","email_primary.email","membership.membership_type_id:label"],"join":[["Contribution AS contribution","INNER",["id","=","contribution.contact_id"]],["Membership AS membership","INNER",["id","=","membership.contact_id"]]],"where":[["contribution.contribution_status_id:name","=","Completed"],["membership.status_id:name","IN",["New","Current","Grace"]]]}

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
   * The explicit entity if permitted; otherwise Contact for a refinement, or
   * auto-detected for a new request.
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
   * Ask the model which entity the request targets; '' on any failure.
   */
  private function classifyEntity(\Civi\AiAssistant\LlmService $llm, string $prompt): string {
    $list = implode(', ', SchemaContext::$allowedEntities);
    $catalog = SchemaContext::catalogBlock();
    $system = "You route a CiviCRM search request to the single entity whose own records best answer it. "
      . "The request may contain typos, abbreviations or informal wording - infer intent. "
      . "Prefer Contact unless the request is fundamentally about another entity's records or aggregates. "
      . "A request that combines several record types about the same people (e.g. donors who are also members) is about Contact. "
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
   * Normalize query shape without schema lookups.
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
    if (isset($clean['where']) && is_array($clean['where'])) {
      $clean['where'] = array_values(array_filter(array_map(
        [\Civi\AiAssistant\QueryNormalizer::class, 'normalizeWhereClause'],
        $clean['where']
      )));
    }
    if (isset($clean['where']) && !$clean['where']) {
      unset($clean['where']);
    }
    $limit = (int) ($clean['limit'] ?? 0);
    if ($limit > 0) {
      $clean['limit'] = $limit;
    }
    else {
      unset($clean['limit']);
    }
    return $clean;
  }

  /**
   * Validate the display spec and align its columns to the ACTUAL result keys
   * ($keys, derived from the cleaned select), so a stripped alias can't leave a
   * column pointing at a nonexistent field.
   */
  private function sanitizeDisplay(?array $display, array $keys): array {
    $type = $display['type'] ?? 'table';
    if (!in_array($type, self::ALLOWED_DISPLAY_TYPES, TRUE)) {
      $type = 'table';
    }
    if ($type === 'single' && count($keys) > 1) {
      $type = 'table';
    }

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
   * Preview rows capped at ai_preview_limit. Returns [rows, truncated,
   * warningOrNull].
   */
  private function preview(string $entity, array $apiParams): array {
    $cap = (int) (\Civi::settings()->get('ai_preview_limit') ?: 25);
    $limit = (int) ($apiParams['limit'] ?? 0);
    $apiParams['limit'] = ($limit > 0 && $limit <= $cap) ? $limit : $cap + 1;
    try {
      $rows = civicrm_api4($entity, 'get', $apiParams + ['checkPermissions' => TRUE])->getArrayCopy();
      $truncated = count($rows) > $cap;
      return [array_slice($rows, 0, $cap), $truncated, NULL];
    }
    catch (\Throwable $e) {
      return [[], FALSE, 'Preview could not run: ' . $e->getMessage()];
    }
  }

}

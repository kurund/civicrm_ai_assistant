<?php

namespace Civi\AiAssistant;

/**
 * Pure, deterministic transforms that repair common APIv4 shape mistakes in a
 * model-generated query — no LLM, no database. Kept side-effect-free so it is
 * unit-testable without a CiviCRM bootstrap.
 */
class QueryNormalizer {

  /**
   * Operators APIv4 accepts in a where clause.
   */
  public const OPERATORS = [
    '=', '!=', '<>', '>', '<', '>=', '<=',
    'IN', 'NOT IN', 'LIKE', 'NOT LIKE', 'BETWEEN', 'NOT BETWEEN',
    'IS NULL', 'IS NOT NULL', 'IS EMPTY', 'IS NOT EMPTY', 'CONTAINS',
  ];

  /**
   * APIv4 forbids aliasing a plain field ("only expressions can have an alias").
   * Strip "AS alias" from a non-expression select item; keep it on functions.
   */
  public static function cleanSelectItem($item): string {
    $item = trim((string) $item);
    if (preg_match('/^(.*?)\s+AS\s+([A-Za-z0-9_]+)$/i', $item, $m)) {
      $base = trim($m[1]);
      return (strpos($base, '(') !== FALSE) ? ($base . ' AS ' . $m[2]) : $base;
    }
    return $item;
  }

  /**
   * The key APIv4 returns for a (cleaned) select item: the alias for an aliased
   * expression, otherwise the field path itself.
   */
  public static function selectResultKey($item): string {
    $item = trim((string) $item);
    if (preg_match('/\s+AS\s+([A-Za-z0-9_]+)$/i', $item, $m)) {
      return $m[1];
    }
    return $item;
  }

  /**
   * The underlying field a select item reads, with function wrapper and alias
   * removed. "SUM(total_amount) AS total" -> "total_amount";
   * "contact_id.display_name" -> "contact_id.display_name"; "COUNT(*)" -> "*".
   */
  public static function baseField($item): string {
    $item = trim((string) $item);
    // Drop trailing alias.
    $item = preg_replace('/\s+AS\s+[A-Za-z0-9_]+$/i', '', $item);
    // Unwrap a single function call: FN(expr) -> expr.
    if (preg_match('/^[A-Za-z_]+\s*\((.*)\)$/s', trim($item), $m)) {
      $item = trim($m[1]);
    }
    return $item;
  }

  /**
   * Coerce orderBy into APIv4's {field: direction} object form. Models often
   * emit SQL/SearchKit-style [[field, dir], ...] or ["field"] instead.
   */
  public static function normalizeOrderBy($orderBy): array {
    if (!is_array($orderBy)) {
      return [];
    }
    // Already associative: {"field": "DESC"}.
    if ($orderBy && array_keys($orderBy) !== range(0, count($orderBy) - 1)) {
      $out = [];
      foreach ($orderBy as $field => $dir) {
        $out[$field] = strtoupper((string) $dir) === 'DESC' ? 'DESC' : 'ASC';
      }
      return $out;
    }
    // List of [field, dir] pairs or bare "field" strings.
    $out = [];
    foreach ($orderBy as $clause) {
      if (is_array($clause) && isset($clause[0])) {
        $dir = isset($clause[1]) && strtoupper((string) $clause[1]) === 'DESC' ? 'DESC' : 'ASC';
        $out[(string) $clause[0]] = $dir;
      }
      elseif (is_string($clause)) {
        $out[$clause] = 'ASC';
      }
    }
    return $out;
  }

  /**
   * Is a select item an aggregate/function expression (vs. a plain field)?
   */
  public static function isExpression($item): bool {
    return strpos((string) $item, '(') !== FALSE;
  }

  /**
   * A readable column label from a field path: "contact_id.display_name" ->
   * "Display Name"; "total" -> "Total".
   */
  public static function prettifyLabel(string $key): string {
    $tail = strpos($key, '.') !== FALSE ? substr(strrchr($key, '.'), 1) : $key;
    return ucwords(str_replace('_', ' ', $tail));
  }

}

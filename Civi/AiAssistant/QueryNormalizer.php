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
   * The explicit alias of a select item ("SUM(x) AS total" -> "total"), or NULL
   * when the item has no "AS alias".
   */
  public static function selectAlias($item): ?string {
    if (preg_match('/\s+AS\s+([A-Za-z0-9_]+)$/i', trim((string) $item), $m)) {
      return $m[1];
    }
    return NULL;
  }

  /**
   * Replace a select item's alias: "SUM(total_amount) AS total_amount" with
   * "total_amount_calc" -> "SUM(total_amount) AS total_amount_calc". Returns the
   * item unchanged if it has no alias.
   */
  public static function renameAlias($item, string $newAlias): string {
    $item = trim((string) $item);
    return preg_replace('/(\s+AS\s+)[A-Za-z0-9_]+$/i', '${1}' . $newAlias, $item);
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
   * Repair a single where clause's SHAPE (not its field — that is the
   * validator's job). Returns a clean [field, OP, value?] clause, or NULL if it
   * is too malformed to use. Pure.
   *
   * Fixes the common model mistakes:
   *  - BETWEEN/IN given as a flat list ([field, "BETWEEN", lo, hi]) instead of a
   *    nested-array value ([field, "BETWEEN", [lo, hi]]) — APIv4 otherwise
   *    mis-reads the loose values as field names;
   *  - value-less operators (IS NULL, IS EMPTY, …) carrying a stray value;
   *  - a scalar value where IN expects an array.
   */
  public static function normalizeWhereClause($clause): ?array {
    if (!is_array($clause) || !isset($clause[0])) {
      return NULL;
    }
    $clause = array_values($clause);
    $field = $clause[0];
    $op = isset($clause[1]) ? strtoupper(trim((string) $clause[1])) : '=';

    if (in_array($op, ['IS NULL', 'IS NOT NULL', 'IS EMPTY', 'IS NOT EMPTY'], TRUE)) {
      return [$field, $op];
    }

    if (in_array($op, ['BETWEEN', 'NOT BETWEEN'], TRUE)) {
      $pair = (isset($clause[2]) && is_array($clause[2])) ? array_values($clause[2]) : array_slice($clause, 2);
      return count($pair) === 2 ? [$field, $op, $pair] : NULL;
    }

    if (in_array($op, ['IN', 'NOT IN'], TRUE)) {
      $vals = (isset($clause[2]) && is_array($clause[2])) ? array_values($clause[2]) : array_slice($clause, 2);
      return $vals ? [$field, $op, $vals] : NULL;
    }

    // Standard binary operator: [field, op, value].
    if (!array_key_exists(2, $clause)) {
      return NULL;
    }
    return [$field, $op, $clause[2]];
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
   * SQL aggregate functions APIv4 supports. A select that uses one forces every
   * non-aggregated selected field into groupBy (MySQL ONLY_FULL_GROUP_BY).
   */
  public const AGGREGATE_FUNCTIONS = [
    'SUM', 'COUNT', 'AVG', 'MIN', 'MAX', 'GROUP_CONCAT', 'STDDEV',
  ];

  /**
   * Is a select item an aggregate call, e.g. "SUM(total_amount) AS total"?
   */
  public static function isAggregate($item): bool {
    if (preg_match('/^([A-Za-z_]+)\s*\(/', trim((string) $item), $m)) {
      return in_array(strtoupper($m[1]), self::AGGREGATE_FUNCTIONS, TRUE);
    }
    return FALSE;
  }

  /**
   * A select item with its "AS alias" removed but the expression intact:
   * "SUM(total_amount) AS total" -> "SUM(total_amount)". (Unlike baseField,
   * which also unwraps the function.)
   */
  public static function stripAlias($item): string {
    return trim(preg_replace('/\s+AS\s+[A-Za-z0-9_]+$/i', '', trim((string) $item)));
  }

  /**
   * The plain fields that MUST appear in groupBy for a given select: when the
   * select contains an aggregate, every non-aggregated plain field has to be
   * grouped. Pseudoconstant suffixes are stripped (group by the raw field).
   * Returns [] when there is no aggregate (no grouping required). Pure.
   *
   * @param string[] $select
   * @return string[]
   */
  public static function requiredGroupBy(array $select): array {
    $hasAggregate = FALSE;
    $fields = [];
    foreach ($select as $item) {
      if (self::isAggregate($item)) {
        $hasAggregate = TRUE;
        continue;
      }
      // Leave non-aggregate expressions (e.g. YEAR(x)) for the model to group.
      if (self::isExpression($item)) {
        continue;
      }
      $field = preg_replace('/:[A-Za-z]+$/', '', self::baseField($item));
      if ($field !== '' && $field !== '*') {
        $fields[] = $field;
      }
    }
    return $hasAggregate ? array_values(array_unique($fields)) : [];
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

<?php

namespace Civi\AiAssistant;

/**
 * Validates a model-generated query against the REAL schema (APIv4 getFields)
 * and repairs it by dropping references to fields that don't exist — so a
 * hallucinated column can't fail (or worse, silently distort) the query.
 *
 * This is the deterministic backstop: the model proposes, CiviCRM's own
 * metadata disposes. Resolves one-level implicit joins via each field's
 * fk_entity, and tolerates `field:label` pseudoconstant suffixes.
 */
class QueryValidator {

  /** @var array<string,array> Per-entity field metadata, cached per request. */
  private static array $cache = [];

  /**
   * @return array<string,array> field name => metadata row
   */
  public static function fieldNames(string $entity): array {
    if (!isset(self::$cache[$entity])) {
      $map = [];
      try {
        foreach (civicrm_api4($entity, 'getFields', ['checkPermissions' => TRUE]) as $f) {
          if (!empty($f['name'])) {
            $map[$f['name']] = $f;
          }
        }
      }
      catch (\Throwable $e) {
        $map = [];
      }
      self::$cache[$entity] = $map;
    }
    return self::$cache[$entity];
  }

  /**
   * Does a (possibly dotted, possibly :suffixed) field path exist on $entity?
   * Implicit joins are resolved one segment at a time via fk_entity.
   */
  public static function fieldExists(string $entity, string $path): bool {
    $segments = explode('.', $path);
    $first = self::stripSuffix($segments[0]);
    if ($first === '') {
      return FALSE;
    }
    $names = self::fieldNames($entity);
    if (!$names) {
      // Metadata unavailable — don't block (fail open rather than mangle).
      return TRUE;
    }
    if (!isset($names[$first])) {
      return FALSE;
    }
    if (count($segments) === 1) {
      return TRUE;
    }
    $fk = $names[$first]['fk_entity'] ?? NULL;
    if (!$fk) {
      return FALSE;
    }
    return self::fieldExists($fk, implode('.', array_slice($segments, 1)));
  }

  private static function stripSuffix(string $seg): string {
    $pos = strpos($seg, ':');
    return $pos === FALSE ? $seg : substr($seg, 0, $pos);
  }

  /**
   * Validate & repair api_params. Returns ['params' => array, 'issues' => string[]].
   */
  public static function validate(string $entity, array $params): array {
    $issues = [];
    if (!self::fieldNames($entity)) {
      // Could not load metadata; skip rather than risk mangling a valid query.
      return ['params' => $params, 'issues' => $issues];
    }

    // SELECT — keep "*", and any item whose underlying field resolves.
    if (!empty($params['select']) && is_array($params['select'])) {
      $kept = [];
      foreach ($params['select'] as $sel) {
        $base = QueryNormalizer::baseField($sel);
        if ($base === '' || $base === '*' || self::fieldExists($entity, $base)) {
          $kept[] = $sel;
        }
        else {
          $issues[] = "Dropped unknown field in select: {$sel}";
        }
      }
      $params['select'] = $kept ?: ['id'];
    }

    // Aliases produced by select are valid orderBy/having references.
    $aliases = [];
    foreach ($params['select'] ?? [] as $sel) {
      $aliases[QueryNormalizer::selectResultKey($sel)] = TRUE;
    }

    // WHERE — drop malformed clauses, bad operators, unknown fields.
    if (!empty($params['where']) && is_array($params['where'])) {
      $kept = [];
      foreach ($params['where'] as $clause) {
        if (!is_array($clause) || !isset($clause[0])) {
          $issues[] = 'Dropped malformed where clause';
          continue;
        }
        $op = strtoupper((string) ($clause[1] ?? '='));
        if (!in_array($op, QueryNormalizer::OPERATORS, TRUE)) {
          $issues[] = "Dropped where clause with invalid operator: {$op}";
          continue;
        }
        if (!self::fieldExists($entity, (string) $clause[0])) {
          $issues[] = "Dropped where clause on unknown field: {$clause[0]}";
          continue;
        }
        $kept[] = $clause;
      }
      if ($kept) {
        $params['where'] = $kept;
      }
      else {
        unset($params['where']);
      }
    }

    // GROUP BY
    if (!empty($params['groupBy']) && is_array($params['groupBy'])) {
      $kept = [];
      foreach ($params['groupBy'] as $g) {
        if (self::fieldExists($entity, (string) $g)) {
          $kept[] = $g;
        }
        else {
          $issues[] = "Dropped unknown groupBy: {$g}";
        }
      }
      if ($kept) {
        $params['groupBy'] = $kept;
      }
      else {
        unset($params['groupBy']);
      }
    }

    // ORDER BY (already a {field: dir} map) — allow aliases or real fields.
    if (!empty($params['orderBy']) && is_array($params['orderBy'])) {
      $kept = [];
      foreach ($params['orderBy'] as $field => $dir) {
        if (isset($aliases[$field]) || self::fieldExists($entity, (string) $field)) {
          $kept[$field] = $dir;
        }
        else {
          $issues[] = "Dropped orderBy on unknown field: {$field}";
        }
      }
      if ($kept) {
        $params['orderBy'] = $kept;
      }
      else {
        unset($params['orderBy']);
      }
    }

    return ['params' => $params, 'issues' => $issues];
  }

}

<?php

namespace Civi\AiAssistant;

/**
 * Validates a model-generated query against the REAL schema (APIv4 getFields)
 * and repairs it by dropping references to fields that don't exist — so a
 * hallucinated column can't fail (or worse, silently distort) the query.
 *
 * This is the deterministic backstop: the model proposes, CiviCRM's own
 * metadata disposes. Resolves implicit joins via each field's fk_entity,
 * explicit-join aliases ("membership.status_id"), and tolerates `field:label`
 * pseudoconstant suffixes.
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
   * Implicit joins are resolved one segment at a time via fk_entity; a first
   * segment naming an explicit join alias resolves against that join's entity.
   *
   * @param array<string,string> $joins  Explicit join alias => entity.
   */
  public static function fieldExists(string $entity, string $path, array $joins = []): bool {
    $segments = explode('.', $path);
    $first = self::stripSuffix($segments[0]);
    if ($first === '') {
      return FALSE;
    }
    if (count($segments) > 1 && isset($joins[$segments[0]])) {
      return self::fieldExists($joins[$segments[0]], implode('.', array_slice($segments, 1)));
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
   * An alias derived from $alias that does not collide with any real field name
   * on the entity (e.g. "total_amount" -> "total_amount_calc").
   *
   * @param array<string,array> $names  Field metadata keyed by field name.
   */
  private static function nonCollidingAlias(string $alias, array $names): string {
    $candidate = $alias . '_calc';
    $i = 2;
    while (isset($names[$candidate])) {
      $candidate = $alias . '_calc_' . $i++;
    }
    return $candidate;
  }

  /**
   * Repair and check explicit joins: the joined entity must be one the assistant
   * may query, the alias must not shadow a base field, and every field in the ON
   * conditions must exist. A join that fails any check is dropped (fields that
   * reference its alias are then dropped by the regular field checks).
   *
   * @return array{0: array<string,array>, 1: array<string,string>, 2: string[]}
   *   [alias => valid join, alias => entity, issues]
   */
  private static function validateJoins(string $entity, $rawJoins): array {
    $joins = [];
    $aliases = [];
    $issues = [];
    $names = self::fieldNames($entity);
    foreach ((is_array($rawJoins) ? $rawJoins : []) as $raw) {
      $join = QueryNormalizer::normalizeJoin($raw);
      if ($join === NULL) {
        $issues[] = 'Dropped malformed join';
        continue;
      }
      [$joinEntity, $alias] = QueryNormalizer::joinEntityAlias($join);
      if (!SchemaContext::isAllowed($joinEntity) || !self::fieldNames($joinEntity)) {
        $issues[] = "Dropped join on entity not permitted for AI search: {$joinEntity}";
        continue;
      }
      if (isset($names[$alias]) || isset($aliases[$alias])) {
        $issues[] = "Dropped join with conflicting alias: {$alias}";
        continue;
      }
      $known = $aliases + [$alias => $joinEntity];
      $linksJoin = FALSE;
      $valid = TRUE;
      foreach ($join as $i => $cond) {
        if ($i < 2) {
          continue;
        }
        if (!in_array($cond[1], QueryNormalizer::OPERATORS, TRUE) || !self::fieldExists($entity, $cond[0], $known)) {
          $valid = FALSE;
          break;
        }
        if (!array_key_exists(2, $cond) || ($cond[3] ?? TRUE) === FALSE) {
          continue;
        }
        if (QueryNormalizer::isFieldOperand($cond[2]) && self::fieldExists($entity, $cond[2], $known)) {
          $linksJoin = $linksJoin || str_starts_with($cond[0], $alias . '.') || str_starts_with($cond[2], $alias . '.');
        }
        else {
          // APIv4 reads an ON operand as a field unless flagged as a literal.
          $join[$i][3] = FALSE;
        }
      }
      if (!$valid || !$linksJoin) {
        $issues[] = "Dropped join with invalid ON condition: {$join[0]}";
        continue;
      }
      $aliases[$alias] = $joinEntity;
      $joins[$alias] = $join;
    }
    return [$joins, $aliases, $issues];
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

    $names = self::fieldNames($entity);

    // JOIN — must be run before the field checks, which resolve its aliases.
    [$joinClauses, $joins, $joinIssues] = self::validateJoins($entity, $params['join'] ?? []);
    $issues = array_merge($issues, $joinIssues);

    // SELECT — keep "*", and any item whose underlying field resolves.
    if (!empty($params['select']) && is_array($params['select'])) {
      $kept = [];
      foreach ($params['select'] as $sel) {
        $base = QueryNormalizer::baseField($sel);
        if ($base === '' || $base === '*' || self::fieldExists($entity, $base, $joins)) {
          $kept[] = $sel;
        }
        else {
          $issues[] = "Dropped unknown field in select: {$sel}";
        }
      }
      $params['select'] = $kept ?: ['id'];
    }

    // An expression alias that equals a real field name is rejected by APIv4
    // ("Cannot use existing field name as alias", e.g. SUM(total_amount) AS
    // total_amount). Rename it deterministically; remember the mapping so
    // orderBy references to the old alias follow.
    $renamed = [];
    foreach (($params['select'] ?? []) as $i => $sel) {
      $alias = QueryNormalizer::selectAlias($sel);
      if ($alias !== NULL && isset($names[$alias])) {
        $new = self::nonCollidingAlias($alias, $names);
        $renamed[$alias] = $new;
        $params['select'][$i] = QueryNormalizer::renameAlias($sel, $new);
        $issues[] = "Renamed alias '{$alias}' to '{$new}' (collided with a field name)";
      }
    }

    // Aliases produced by select are valid orderBy/having references. Also map
    // each alias to its underlying expression, since APIv4 will not order by a
    // bare alias — orderBy must reference the expression (e.g. SUM(total_amount)).
    $aliases = [];
    $aliasExpr = [];
    foreach ($params['select'] ?? [] as $sel) {
      $aliases[QueryNormalizer::selectResultKey($sel)] = TRUE;
      $alias = QueryNormalizer::selectAlias($sel);
      if ($alias !== NULL) {
        $aliasExpr[$alias] = QueryNormalizer::stripAlias($sel);
      }
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
        if (!self::fieldExists($entity, (string) $clause[0], $joins)) {
          $issues[] = "Dropped where clause on unknown field: {$clause[0]}";
          continue;
        }
        // EXCLUDE adds "alias.id IS NULL", so a WHERE filter on its fields can
        // never match; it has to narrow the join's ON instead.
        $alias = explode('.', (string) $clause[0], 2)[0];
        if (($joinClauses[$alias][1] ?? NULL) === 'EXCLUDE') {
          $clause = array_values($clause);
          if (array_key_exists(2, $clause)) {
            $clause[3] = FALSE;
          }
          $joinClauses[$alias][] = $clause;
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
        if (self::fieldExists($entity, (string) $g, $joins)) {
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

    if ($joinClauses) {
      $params['join'] = array_values($joinClauses);
    }
    else {
      unset($params['join']);
    }

    // A join onto a one-to-many entity (a contact's contributions) repeats the
    // base row once per match; group by the base id to keep one row each.
    $fansOut = FALSE;
    foreach ($joinClauses as $join) {
      $fansOut = $fansOut || $join[1] !== 'EXCLUDE';
    }
    if ($fansOut && empty($params['groupBy'])) {
      $params['groupBy'] = ['id'];
    }
    // When the select aggregates, every non-aggregated selected field must be
    // grouped or MySQL errors (ONLY_FULL_GROUP_BY). Models routinely group by
    // only the "main" field (e.g. contact_id) and omit the rest — add them.
    $required = QueryNormalizer::requiredGroupBy($params['select'] ?? [], !empty($params['groupBy']));
    if ($required) {
      $existing = $params['groupBy'] ?? [];
      $params['groupBy'] = array_values(array_unique(array_merge($existing, $required)));
    }

    // ORDER BY (already a {field: dir} map) — allow aliases or real fields.
    if (!empty($params['orderBy']) && is_array($params['orderBy'])) {
      $kept = [];
      foreach ($params['orderBy'] as $field => $dir) {
        // Follow a renamed alias (orderBy total_amount -> total_amount_calc).
        $field = $renamed[$field] ?? $field;
        // APIv4 rejects ordering by a bare alias; use the underlying expression.
        if (isset($aliasExpr[$field])) {
          $kept[$aliasExpr[$field]] = $dir;
        }
        elseif (isset($aliases[$field]) || self::fieldExists($entity, (string) $field, $joins)) {
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

<?php

namespace Civi\AiAssistant;

/**
 * Builds a compact, model-friendly description of an entity's fields so the LLM
 * can ground its query in REAL fields (no hallucinated columns).
 *
 * Only schema/metadata is produced here — never contact records — which is the
 * core reason the NL-to-query feature keeps PII out of the LLM.
 */
class SchemaContext {

  /**
   * Entities the assistant is allowed to query. Keep this conservative; expand
   * deliberately. (A future enhancement: make this an admin setting.)
   *
   * @var string[]
   */
  public static array $allowedEntities = [
    'Contact',
    'Contribution',
    'Participant',
    'Membership',
    'Activity',
    'Event',
    'Email',
  ];

  public static function isAllowed(string $entity): bool {
    return in_array($entity, self::$allowedEntities, TRUE);
  }

  /**
   * Return [fieldName => "type; label; options...", ...] for an entity.
   *
   * @return array<string,string>
   */
  public static function forEntity(string $entity): array {
    if (!self::isAllowed($entity)) {
      throw new \CRM_Core_Exception("Entity not permitted for AI search: {$entity}");
    }
    $fields = civicrm_api4($entity, 'getFields', [
      'checkPermissions' => TRUE,
      'where' => [['type', 'IN', ['Field', 'Custom', 'Extra']]],
    ]);

    $out = [];
    foreach ($fields as $f) {
      $name = $f['name'] ?? NULL;
      if (!$name) {
        continue;
      }
      $desc = ($f['data_type'] ?? $f['input_type'] ?? 'String');
      if (!empty($f['title'])) {
        $desc .= '; ' . $f['title'];
      }
      if (!empty($f['options']) && is_array($f['options'])) {
        $opts = array_slice(array_keys($f['options']), 0, 12);
        $desc .= '; options: ' . implode(',', array_map('strval', $opts));
      }
      $out[$name] = $desc;
    }
    return $out;
  }

  /**
   * A flat, token-efficient string for embedding in a system prompt.
   */
  public static function asPromptBlock(string $entity): string {
    $lines = [];
    foreach (self::forEntity($entity) as $name => $desc) {
      $lines[] = "- {$name} ({$desc})";
    }
    return implode("\n", $lines);
  }

}

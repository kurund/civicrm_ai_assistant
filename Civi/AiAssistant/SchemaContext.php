<?php

namespace Civi\AiAssistant;

/**
 * Compact field descriptions of the entities the assistant may query, for
 * prompts.
 */
class SchemaContext {

  /**
   * Entities the assistant is allowed to query.
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

  /**
   * One-line description of each allowed entity, keyed by entity.
   *
   * @var string[]
   */
  public static array $entityCatalog = [
    'Contact' => 'People and organisations - donors, members, supporters - and their core fields.',
    'Contribution' => 'Donations and payments: amount, date, financial type, payment/contribution status.',
    'Membership' => 'Memberships: type, status, join/start/end dates, renewals.',
    'Participant' => 'Event registrations: who registered, status, role, fee.',
    'Event' => 'Events themselves: title, type, start/end dates, location.',
    'Activity' => 'Logged activities: meetings, phone calls, emails sent, tasks.',
    'Email' => 'Email address records: address, on-hold/bounce status, primary flag.',
  ];

  public static function isAllowed(string $entity): bool {
    return in_array($entity, self::$allowedEntities, TRUE);
  }

  /**
   * A compact catalog block ("- Entity: description") for a prompt, optionally
   * limited to a subset of entities.
   *
   * @param string[] $only  Restrict to these entities (empty = all allowed).
   */
  public static function catalogBlock(array $only = []): string {
    $lines = [];
    foreach (self::$entityCatalog as $entity => $desc) {
      if (!self::isAllowed($entity) || ($only && !in_array($entity, $only, TRUE))) {
        continue;
      }
      $lines[] = "- {$entity}: {$desc}";
    }
    return implode("\n", $lines);
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
   * The entity's fields as one "- name (description)" line each.
   */
  public static function asPromptBlock(string $entity): string {
    $lines = [];
    foreach (self::forEntity($entity) as $name => $desc) {
      $lines[] = "- {$name} ({$desc})";
    }
    return implode("\n", $lines);
  }

}

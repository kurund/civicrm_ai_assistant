<?php

namespace Civi\AiAssistant;

/**
 * Resolves which entity a natural-language request targets: an LLM classifier
 * first, then keyword matching when it is unavailable or returns nothing valid.
 */
class EntityRouter {

  /**
   * Word/phrase signals that a request is fundamentally about a NON-Contact
   * entity's own records. Single words match on a word boundary (so "member"
   * does not fire on "remember"); multi-word phrases match as substrings.
   *
   * @var array<string,string[]>
   */
  private const SIGNALS = [
    'Contribution' => [
      'donation', 'donations', 'donor', 'donors', 'donated', 'gave', 'giving',
      'contribution', 'contributions', 'contributed', 'pledge', 'pledges',
      'fundrais', 'raised', 'gift', 'gifts',
    ],
    'Membership' => [
      'member', 'members', 'membership', 'memberships',
      'renewal', 'renewals', 'renewed',
    ],
    'Participant' => [
      'participant', 'participants', 'registrant', 'registrants',
      'attendee', 'attendees', 'attended', 'registered', 'registration',
      'registrations', 'rsvp',
    ],
    'Event' => [
      'event', 'events', 'conference', 'conferences', 'workshop', 'workshops',
    ],
    'Activity' => [
      'activity', 'activities', 'meeting', 'meetings', 'phone call',
      'phone calls', 'follow-up', 'follow up', 'interaction', 'interactions',
    ],
    'Email' => [
      'bounced', 'on hold', 'on-hold', 'undeliverable',
    ],
  ];

  /**
   * Resolve the best entity for a prompt.
   *
   * @param string $prompt
   *   The natural-language request.
   * @param callable|null $classify
   *   LLM classifier: fn(string $prompt): string returning a permitted entity.
   *
   * @return string A permitted entity, "Contact" when nothing else fits.
   */
  public static function detect(string $prompt, ?callable $classify = NULL): string {
    if ($classify) {
      $picked = (string) $classify($prompt);
      if (SchemaContext::isAllowed($picked)) {
        return $picked;
      }
    }
    return self::keywordRoute($prompt);
  }

  /**
   * Keyword routing: a single clear signal wins, otherwise Contact.
   */
  public static function keywordRoute(string $prompt): string {
    $matches = self::keywordMatches($prompt);
    return count($matches) === 1 ? $matches[0] : 'Contact';
  }

  /**
   * Permitted non-Contact entities whose signals appear in the prompt.
   *
   * @return string[]
   */
  public static function keywordMatches(string $prompt): array {
    $text = ' ' . strtolower($prompt) . ' ';
    $matched = [];
    foreach (self::SIGNALS as $entity => $words) {
      if (!SchemaContext::isAllowed($entity)) {
        continue;
      }
      foreach ($words as $word) {
        $hit = strpos($word, ' ') !== FALSE
          ? strpos($text, $word) !== FALSE
          : (bool) preg_match('/\b' . preg_quote($word, '/') . '/', $text);
        if ($hit) {
          $matched[] = $entity;
          break;
        }
      }
    }
    return $matched;
  }

}

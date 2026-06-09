<?php

namespace Civi\AiAssistant;

/**
 * Resolves which entity a natural-language request targets
 *
 * A dedicated, lightweight LLM classification runs first: it is robust to
 * typos, synonyms and informal phrasing in a way keyword matching can't be
 * ("donrs over 100", "ppl who attended"). The deterministic keyword pass is
 * kept purely as an OFFLINE fallback — used only when the model is unavailable
 * or returns something invalid — and is pure/unit-testable (no LLM, no DB).
 * Contact is the safe default base entity (most requests are about people, and
 * cross-entity filters resolve through implicit joins).
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
   *   The natural-language request (may contain typos / informal wording).
   * @param callable|null $classify
   *   LLM classifier: fn(string $prompt): string returning a permitted entity.
   *   Tried first; any invalid/empty result falls through to keyword routing.
   *
   * @return string A permitted entity (always falls back to "Contact").
   */
  public static function detect(string $prompt, ?callable $classify = NULL): string {
    // Primary: let the model read intent (handles typos, synonyms, phrasing).
    if ($classify) {
      $picked = (string) $classify($prompt);
      if (SchemaContext::isAllowed($picked)) {
        return $picked;
      }
    }
    // Offline fallback: deterministic keyword routing, defaulting to Contact.
    return self::keywordRoute($prompt);
  }

  /**
   * Deterministic, LLM-free routing: a single clear signal wins, otherwise
   * Contact. Used as the offline fallback and independently unit-testable.
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

<?php

namespace Civi\AiAssistant;

/**
 * Best-effort PII masking applied before any external (cloud) call.
 *
 * IMPORTANT — honest limitations:
 *  - This catches *structured* identifiers (emails, phone numbers, obvious IDs)
 *    via regex. It CANNOT reliably detect plain personal names or free-text PII.
 *  - It is defense-in-depth, not a guarantee. The guarantees are architectural:
 *    (a) the NL-to-query feature sends schema + prompt, not records; and
 *    (b) pointing the provider at a local model means nothing leaves your infra.
 *
 * Redaction is skipped entirely when the configured provider is local (loopback
 * host), since nothing leaves the org in that case.
 */
class Redactor {

  /**
   * @return bool TRUE if redaction is enabled AND the provider is remote.
   */
  public static function isActive(): bool {
    if (!\Civi::settings()->get('ai_redact_pii')) {
      return FALSE;
    }
    return !self::isLocalProvider();
  }

  /**
   * Is the configured provider a local/loopback endpoint?
   */
  public static function isLocalProvider(): bool {
    $host = (string) parse_url((string) \Civi::settings()->get('ai_provider_base_url'), PHP_URL_HOST);
    return in_array($host, ['localhost', '127.0.0.1', '::1', 'host.docker.internal'], TRUE);
  }

  /**
   * Mask structured PII in a string. No-op when redaction is inactive.
   */
  public static function scrub(string $text): string {
    if (!self::isActive()) {
      return $text;
    }
    // Email addresses.
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $text);
    // Phone numbers (loose international/US patterns; conservative).
    $text = preg_replace('/(?<!\w)(\+?\d[\d\s().\-]{7,}\d)(?!\w)/', '[redacted-phone]', $text);
    return $text;
  }

  /**
   * Detect whether a user prompt appears to contain structured PII, for a
   * preflight "this looks like personal data — continue?" warning.
   *
   * @return string[] List of detected PII categories (empty if none).
   */
  public static function detect(string $text): array {
    $found = [];
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text)) {
      $found[] = 'email';
    }
    if (preg_match('/(?<!\w)(\+?\d[\d\s().\-]{7,}\d)(?!\w)/', $text)) {
      $found[] = 'phone';
    }
    return $found;
  }

}

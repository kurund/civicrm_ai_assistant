<?php

namespace Civi\AiAssistant;

/**
 * Regex masking of structured PII (emails, phone numbers) before a remote
 * provider call. Does not catch names or free-text PII; skipped for loopback
 * providers.
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
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $text);
    // Loose international/US phone patterns.
    $text = preg_replace('/(?<!\w)(\+?\d[\d\s().\-]{7,}\d)(?!\w)/', '[redacted-phone]', $text);
    return $text;
  }

  /**
   * Detect whether a user prompt appears to contain structured PII, for a
   * preflight "this looks like personal data - continue?" warning.
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

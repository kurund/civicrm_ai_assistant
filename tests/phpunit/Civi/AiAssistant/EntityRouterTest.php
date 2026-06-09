<?php

namespace Civi\AiAssistant;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the deterministic (offline) entity routing — the fallback used
 * when the LLM classifier is unavailable. The LLM-first path is exercised
 * separately; these cover the pure keyword logic with no bootstrap.
 *
 * @group unit
 */
class EntityRouterTest extends TestCase {

  public function testDefaultsToContact(): void {
    $this->assertSame('Contact', EntityRouter::keywordRoute('people in Exeter'));
    $this->assertSame('Contact', EntityRouter::keywordRoute(''));
  }

  public function testClearSingleSignalRoutes(): void {
    $this->assertSame('Contribution', EntityRouter::keywordRoute('total donations last year'));
    $this->assertSame('Membership', EntityRouter::keywordRoute('lapsed members'));
    $this->assertSame('Participant', EntityRouter::keywordRoute('who attended'));
  }

  public function testWordBoundaryAvoidsFalsePositives(): void {
    // "remember" must not trigger the Membership "member" signal.
    $this->assertSame('Contact', EntityRouter::keywordRoute('contacts to remember'));
  }

  public function testCompetingSignalsFallBackToContact(): void {
    // "fundraising event" hits both Contribution and Event -> ambiguous; with no
    // LLM classifier the deterministic route defaults to the safe base entity.
    $this->assertSame('Contact', EntityRouter::keywordRoute('fundraising event income'));
  }

  public function testDetectPrefersClassifierResult(): void {
    $classify = fn(string $p): string => 'Membership';
    $this->assertSame('Membership', EntityRouter::detect('anything at all', $classify));
  }

  public function testDetectIgnoresInvalidClassifierResult(): void {
    // A bogus/empty classifier reply falls through to keyword routing.
    $classify = fn(string $p): string => 'NotAnEntity';
    $this->assertSame('Contribution', EntityRouter::detect('total donations', $classify));
    $this->assertSame('Contact', EntityRouter::detect('people in Exeter', fn($p) => ''));
  }

}

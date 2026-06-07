<?php

namespace Civi\AiAssistant;

use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests that need no CiviCRM bootstrap (JSON extraction logic).
 *
 * @group unit
 */
class LlmServiceTest extends TestCase {

  public function testExtractsPlainJson(): void {
    $out = LlmService::extractJson('{"a":1,"b":[2,3]}');
    $this->assertSame(1, $out['a']);
    $this->assertSame([2, 3], $out['b']);
  }

  public function testExtractsFencedJson(): void {
    $raw = "Sure, here you go:\n```json\n{\"type\":\"single\"}\n```";
    $out = LlmService::extractJson($raw);
    $this->assertSame('single', $out['type']);
  }

  public function testExtractsJsonWrappedInProse(): void {
    $raw = 'The query is {"select":["id"],"limit":25} as requested.';
    $out = LlmService::extractJson($raw);
    $this->assertSame(['id'], $out['select']);
  }

  public function testReturnsNullOnGarbage(): void {
    $this->assertNull(LlmService::extractJson('no json here at all'));
  }

}

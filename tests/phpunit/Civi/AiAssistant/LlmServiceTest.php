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

  public function testStripsThinkBlock(): void {
    // Reasoning models emit <think>…</think> (which itself may contain braces)
    // before the real answer.
    $raw = "<think>The user wants {a count}. I'll use COUNT.</think>\n{\"type\":\"single\"}";
    $out = LlmService::extractJson($raw);
    $this->assertSame('single', $out['type']);
  }

  public function testExtractsBalancedObjectWithTrailingProse(): void {
    // A nested object plus commentary after the close — greedy matching would
    // have swallowed the trailing "}" in prose; balanced extraction stops dead.
    $raw = 'Here: {"display":{"type":"table"},"limit":10} — hope that helps! }';
    $out = LlmService::extractJson($raw);
    $this->assertSame(['type' => 'table'], $out['display']);
    $this->assertSame(10, $out['limit']);
  }

  public function testIgnoresBraceInsideStringValue(): void {
    $out = LlmService::extractJson('{"summary":"contacts with a } brace","limit":5}');
    $this->assertSame('contacts with a } brace', $out['summary']);
    $this->assertSame(5, $out['limit']);
  }

}

<?php

namespace Civi\AiAssistant;

use PHPUnit\Framework\TestCase;

/**
 * JSON extraction and retry.
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
    $raw = "<think>The user wants {a count}. I'll use COUNT.</think>\n{\"type\":\"single\"}";
    $out = LlmService::extractJson($raw);
    $this->assertSame('single', $out['type']);
  }

  public function testExtractsBalancedObjectWithTrailingProse(): void {
    $raw = 'Here: {"display":{"type":"table"},"limit":10} - hope that helps! }';
    $out = LlmService::extractJson($raw);
    $this->assertSame(['type' => 'table'], $out['display']);
    $this->assertSame(10, $out['limit']);
  }

  public function testIgnoresBraceInsideStringValue(): void {
    $out = LlmService::extractJson('{"summary":"contacts with a } brace","limit":5}');
    $this->assertSame('contacts with a } brace', $out['summary']);
    $this->assertSame(5, $out['limit']);
  }

  private function fakeLlm(array $replies): LlmService {
    return new class($replies) extends LlmService {

      /**
       * @var array[]
       */
      public array $calls = [];

      /**
       * @var string[]
       */
      private array $replies;

      public function __construct(array $replies) {
        $this->replies = $replies;
      }

      public function complete(?string $system, array $messages, array $options = []): string {
        $this->calls[] = $messages;
        return array_shift($this->replies);
      }

    };
  }

  public function testCompleteJsonRetriesOnceAfterUnparseableReply(): void {
    $llm = $this->fakeLlm(['Sorry, I cannot do {that', '{"ok":true}']);
    $this->assertSame(['ok' => TRUE], $llm->completeJson('sys', [['role' => 'user', 'content' => 'q']]));
    $this->assertCount(2, $llm->calls);
    $this->assertCount(2, $llm->calls[1]);
    $this->assertStringContainsString('not valid JSON', $llm->calls[1][1]['content']);
  }

  public function testCompleteJsonDoesNotRetryValidReply(): void {
    $llm = $this->fakeLlm(['{"ok":true}']);
    $llm->completeJson('sys', [['role' => 'user', 'content' => 'q']]);
    $this->assertCount(1, $llm->calls);
  }

  public function testCompleteJsonGivesUpAfterSecondFailure(): void {
    $llm = $this->fakeLlm(['nope', 'still nope']);
    try {
      $llm->completeJson('sys', [['role' => 'user', 'content' => 'q']]);
      $this->fail('Expected an exception');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertStringContainsString('Please try again', $e->getMessage());
      $this->assertCount(2, $llm->calls);
    }
  }

}

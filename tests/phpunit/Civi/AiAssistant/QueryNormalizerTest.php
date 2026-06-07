<?php

namespace Civi\AiAssistant;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the deterministic query-repair helpers. No CiviCRM bootstrap
 * required — these are pure string/array transforms.
 *
 * @group unit
 */
class QueryNormalizerTest extends TestCase {

  public function testStripsAliasFromPlainField(): void {
    // APIv4 forbids "field AS alias" — must become a bare field.
    $this->assertSame(
      'contact_id.display_name',
      QueryNormalizer::cleanSelectItem('contact_id.display_name AS donor')
    );
  }

  public function testKeepsAliasOnExpression(): void {
    $this->assertSame(
      'SUM(total_amount) AS total',
      QueryNormalizer::cleanSelectItem('SUM(total_amount) AS total')
    );
  }

  public function testResultKeyUsesAliasForExpression(): void {
    $this->assertSame('total', QueryNormalizer::selectResultKey('SUM(total_amount) AS total'));
  }

  public function testResultKeyIsFieldPathWhenNoAlias(): void {
    $this->assertSame('contact_id.display_name', QueryNormalizer::selectResultKey('contact_id.display_name'));
  }

  public function testBaseFieldUnwrapsFunctionAndAlias(): void {
    $this->assertSame('total_amount', QueryNormalizer::baseField('SUM(total_amount) AS total'));
    $this->assertSame('id', QueryNormalizer::baseField('COUNT(id)'));
    $this->assertSame('contact_id.display_name', QueryNormalizer::baseField('contact_id.display_name'));
  }

  public function testNormalizeOrderByFromPairs(): void {
    // The exact bug we hit: [["total","DESC"]] -> {"total":"DESC"}.
    $this->assertSame(
      ['total' => 'DESC'],
      QueryNormalizer::normalizeOrderBy([['total', 'DESC']])
    );
  }

  public function testNormalizeOrderByPassesThroughMap(): void {
    $this->assertSame(
      ['created_date' => 'ASC'],
      QueryNormalizer::normalizeOrderBy(['created_date' => 'asc'])
    );
  }

  public function testNormalizeOrderByBareStrings(): void {
    $this->assertSame(
      ['display_name' => 'ASC'],
      QueryNormalizer::normalizeOrderBy(['display_name'])
    );
  }

  public function testPrettifyLabel(): void {
    $this->assertSame('Display Name', QueryNormalizer::prettifyLabel('contact_id.display_name'));
    $this->assertSame('Total', QueryNormalizer::prettifyLabel('total'));
  }

}

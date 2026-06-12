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

  public function testSelectAlias(): void {
    $this->assertSame('total', QueryNormalizer::selectAlias('SUM(total_amount) AS total'));
    $this->assertNull(QueryNormalizer::selectAlias('contact_id.display_name'));
    $this->assertNull(QueryNormalizer::selectAlias('COUNT(id)'));
  }

  public function testRenameAlias(): void {
    $this->assertSame(
      'SUM(total_amount) AS total_amount_calc',
      QueryNormalizer::renameAlias('SUM(total_amount) AS total_amount', 'total_amount_calc')
    );
    // No alias present -> unchanged.
    $this->assertSame(
      'contact_id.display_name',
      QueryNormalizer::renameAlias('contact_id.display_name', 'whatever')
    );
  }

  public function testNormalizeWhereWrapsFlatBetween(): void {
    // The exact preview-breaking bug: a flat BETWEEN must become a nested pair.
    $this->assertSame(
      ['receive_date', 'BETWEEN', ['2026-01-01 00:00:00', '2026-12-31 23:59:59']],
      QueryNormalizer::normalizeWhereClause(['receive_date', 'BETWEEN', '2026-01-01 00:00:00', '2026-12-31 23:59:59'])
    );
  }

  public function testNormalizeWhereKeepsNestedBetween(): void {
    $this->assertSame(
      ['receive_date', 'BETWEEN', ['2026-01-01', '2026-12-31']],
      QueryNormalizer::normalizeWhereClause(['receive_date', 'BETWEEN', ['2026-01-01', '2026-12-31']])
    );
  }

  public function testNormalizeWhereWrapsFlatIn(): void {
    $this->assertSame(
      ['status_id', 'IN', [1, 2, 3]],
      QueryNormalizer::normalizeWhereClause(['status_id', 'IN', 1, 2, 3])
    );
  }

  public function testNormalizeWhereDropsValueFromNullOperator(): void {
    $this->assertSame(
      ['email_primary.email', 'IS NOT NULL'],
      QueryNormalizer::normalizeWhereClause(['email_primary.email', 'IS NOT NULL', ''])
    );
  }

  public function testNormalizeWhereUppercasesOperatorAndKeepsBinary(): void {
    $this->assertSame(
      ['total_amount', '>=', 100],
      QueryNormalizer::normalizeWhereClause(['total_amount', '>=', 100])
    );
  }

  public function testNormalizeWhereRejectsMalformed(): void {
    $this->assertNull(QueryNormalizer::normalizeWhereClause([]));
    $this->assertNull(QueryNormalizer::normalizeWhereClause(['only_field']));
    $this->assertNull(QueryNormalizer::normalizeWhereClause(['receive_date', 'BETWEEN', '2026-01-01']));
  }

  public function testIsAggregate(): void {
    $this->assertTrue(QueryNormalizer::isAggregate('SUM(total_amount) AS total'));
    $this->assertTrue(QueryNormalizer::isAggregate('COUNT(id)'));
    $this->assertFalse(QueryNormalizer::isAggregate('contact_id.display_name'));
    // A non-aggregate function is not an aggregate.
    $this->assertFalse(QueryNormalizer::isAggregate('YEAR(receive_date) AS yr'));
  }

  public function testStripAliasKeepsExpression(): void {
    $this->assertSame('SUM(total_amount)', QueryNormalizer::stripAlias('SUM(total_amount) AS total'));
    $this->assertSame('contact_id.display_name', QueryNormalizer::stripAlias('contact_id.display_name'));
  }

  public function testRequiredGroupByAddsNonAggregatedFields(): void {
    // The exact "top donors" shape: model groups by contact_id only.
    $this->assertSame(
      ['contact_id.display_name', 'contact_id.email_primary.email'],
      QueryNormalizer::requiredGroupBy([
        'contact_id.display_name',
        'contact_id.email_primary.email',
        'SUM(total_amount) AS total',
      ])
    );
  }

  public function testRequiredGroupByEmptyWithoutAggregate(): void {
    $this->assertSame([], QueryNormalizer::requiredGroupBy(['contact_id.display_name', 'email_primary.email']));
  }

  public function testRequiredGroupByStripsPseudoconstantSuffix(): void {
    $this->assertSame(
      ['financial_type_id'],
      QueryNormalizer::requiredGroupBy(['financial_type_id:label', 'SUM(total_amount) AS total'])
    );
  }

  public function testRequiredGroupByIgnoresStarAndAggregateOnly(): void {
    $this->assertSame([], QueryNormalizer::requiredGroupBy(['COUNT(id) AS total']));
  }

  public function testPrettifyLabel(): void {
    $this->assertSame('Display Name', QueryNormalizer::prettifyLabel('contact_id.display_name'));
    $this->assertSame('Total', QueryNormalizer::prettifyLabel('total'));
  }

}

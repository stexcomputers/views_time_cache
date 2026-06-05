<?php

namespace Drupal\Tests\views_time_cache\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\views_time_cache\CronExpression;

/**
 * Unit tests for the CronExpression evaluator.
 *
 * @coversDefaultClass \Drupal\views_time_cache\CronExpression
 * @group views_time_cache
 */
class CronExpressionTest extends UnitTestCase {

  /**
   * @covers ::isValid
   * @dataProvider validExpressionProvider
   */
  public function testIsValidTrue(string $expression): void {
    $this->assertTrue(CronExpression::isValid($expression));
  }

  /**
   * @covers ::isValid
   * @dataProvider invalidExpressionProvider
   */
  public function testIsValidFalse(string $expression): void {
    $this->assertFalse(CronExpression::isValid($expression));
  }

  /**
   * Data provider of syntactically valid cron expressions.
   *
   * @return array<string, array{string}>
   *   Keyed test sets of cron expression strings.
   */
  public static function validExpressionProvider(): array {
    return [
      'every minute'      => ['* * * * *'],
      'hourly'            => ['0 * * * *'],
      'daily midnight'    => ['0 0 * * *'],
      'weekly monday'     => ['0 0 * * 1'],
      'every 15 min'      => ['*/15 * * * *'],
      'every 6 hours'     => ['0 */6 * * *'],
      'list minutes'      => ['0,15,30,45 * * * *'],
      'range hours'       => ['0 9-17 * * *'],
      'dom 15th'          => ['0 0 15 * *'],
      'month march'       => ['0 0 1 3 *'],
      'dow sunday alt 7'  => ['0 0 * * 7'],
      'range step'        => ['0 1-23/2 * * *'],
      'complex'           => ['5,35 6-22/2 * 1-6 1-5'],
    ];
  }

  /**
   * Data provider of syntactically invalid cron expressions.
   *
   * @return array<string, array{string}>
   *   Keyed test sets of cron expression strings.
   */
  public static function invalidExpressionProvider(): array {
    return [
      'too few fields'      => ['* * * *'],
      'too many fields'     => ['* * * * * *'],
      'minute out of range' => ['60 * * * *'],
      'hour out of range'   => ['* 24 * * *'],
      'dom out of range'    => ['* * 32 * *'],
      'month out of range'  => ['* * * 13 *'],
      'dow out of range'    => ['* * * * 8'],
      'step zero'           => ['*/0 * * * *'],
      'non-numeric'         => ['a * * * *'],
      'empty string'        => [''],
    ];
  }

  /**
   * @covers ::getNextRunDate
   */
  public function testNextRunEveryMinute(): void {
    $expr = new CronExpression('* * * * *');
    $from = new \DateTime('2024-01-15 10:30:45');
    $next = $expr->getNextRunDate($from);
    $this->assertSame('2024-01-15 10:31:00', $next->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getNextRunDate
   */
  public function testNextRunHourly(): void {
    $expr = new CronExpression('0 * * * *');
    // From 10:30 → next boundary is 11:00.
    $from = new \DateTime('2024-01-15 10:30:00');
    $next = $expr->getNextRunDate($from);
    $this->assertSame('2024-01-15 11:00:00', $next->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getNextRunDate
   */
  public function testNextRunHourlyFromExactBoundary(): void {
    $expr = new CronExpression('0 * * * *');
    // From exactly 10:00 → next is 11:00 (strictly after, not the same minute).
    $from = new \DateTime('2024-01-15 10:00:00');
    $next = $expr->getNextRunDate($from);
    $this->assertSame('2024-01-15 11:00:00', $next->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getNextRunDate
   */
  public function testNextRunEvery6Hours(): void {
    $expr = new CronExpression('0 */6 * * *');
    $from = new \DateTime('2024-01-15 07:00:00');
    $next = $expr->getNextRunDate($from);
    $this->assertSame('2024-01-15 12:00:00', $next->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getNextRunDate
   */
  public function testNextRunEvery15Minutes(): void {
    $expr = new CronExpression('*/15 * * * *');
    $from = new \DateTime('2024-01-15 10:16:00');
    $next = $expr->getNextRunDate($from);
    $this->assertSame('2024-01-15 10:30:00', $next->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getNextRunDate
   */
  public function testNextRunDailyMidnightCrossesDay(): void {
    $expr = new CronExpression('0 0 * * *');
    $from = new \DateTime('2024-01-15 10:00:00');
    $next = $expr->getNextRunDate($from);
    $this->assertSame('2024-01-16 00:00:00', $next->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getNextRunDate
   */
  public function testNextRunWeeklyMonday(): void {
    $expr = new CronExpression('0 0 * * 1');
    // 2024-01-15 is a Monday; next Monday is 2024-01-22.
    $from = new \DateTime('2024-01-15 00:00:00');
    $next = $expr->getNextRunDate($from);
    $this->assertSame('2024-01-22 00:00:00', $next->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getNextRunDate
   */
  public function testNextRunListMinutes(): void {
    $expr = new CronExpression('0,30 * * * *');
    $from = new \DateTime('2024-01-15 10:05:00');
    $next = $expr->getNextRunDate($from);
    $this->assertSame('2024-01-15 10:30:00', $next->format('Y-m-d H:i:s'));
  }

  /**
   * DOM and DOW with OR logic: either the 15th or a Monday matches.
   *
   * @covers ::getNextRunDate
   */
  public function testNextRunDomOrDow(): void {
    // DOM = 15, DOW = Monday. Jan 15 2024 is both, so strictly-after → Jan 22.
    $expr = new CronExpression('0 0 15 * 1');
    $from = new \DateTime('2024-01-15 00:00:00');
    $next = $expr->getNextRunDate($from);
    $this->assertSame('2024-01-22 00:00:00', $next->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getNextRunDate
   */
  public function testNextRunSundayAlt7(): void {
    $expr = new CronExpression('0 0 * * 7');
    // 2024-01-14 is a Sunday.
    $from = new \DateTime('2024-01-14 00:00:00');
    $next = $expr->getNextRunDate($from);
    $this->assertSame('2024-01-21 00:00:00', $next->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getPreviousRunDate
   */
  public function testPreviousRunEveryMinuteAtExactMinute(): void {
    $expr = new CronExpression('* * * * *');
    $before = new \DateTime('2024-01-15 10:30:00');
    $prev = $expr->getPreviousRunDate($before);
    $this->assertSame('2024-01-15 10:30:00', $prev->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getPreviousRunDate
   */
  public function testPreviousRunHourly(): void {
    $expr = new CronExpression('0 * * * *');
    // At 10:30, last boundary was 10:00.
    $before = new \DateTime('2024-01-15 10:30:00');
    $prev = $expr->getPreviousRunDate($before);
    $this->assertSame('2024-01-15 10:00:00', $prev->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getPreviousRunDate
   */
  public function testPreviousRunHourlyAtExactBoundary(): void {
    $expr = new CronExpression('0 * * * *');
    // At exactly 10:00, previous run IS 10:00.
    $before = new \DateTime('2024-01-15 10:00:00');
    $prev = $expr->getPreviousRunDate($before);
    $this->assertSame('2024-01-15 10:00:00', $prev->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getPreviousRunDate
   */
  public function testPreviousRunCrossesMidnight(): void {
    $expr = new CronExpression('0 0 * * *');
    $before = new \DateTime('2024-01-15 10:30:00');
    $prev = $expr->getPreviousRunDate($before);
    $this->assertSame('2024-01-15 00:00:00', $prev->format('Y-m-d H:i:s'));
  }

  /**
   * @covers ::getPreviousRunDate
   */
  public function testPreviousRunEvery6Hours(): void {
    $expr = new CronExpression('0 */6 * * *');
    // At 07:00, last boundary was 06:00.
    $before = new \DateTime('2024-01-15 07:00:00');
    $prev = $expr->getPreviousRunDate($before);
    $this->assertSame('2024-01-15 06:00:00', $prev->format('Y-m-d H:i:s'));
  }

  /**
   * Entry created after boundary is valid; entry before boundary is stale.
   *
   * @covers ::getPreviousRunDate
   * @covers ::getNextRunDate
   */
  public function testCacheStalenessSemantics(): void {
    $expr = new CronExpression('0 * * * *');

    // At 10:45, last boundary was 10:00; entry created at 10:15 is still valid.
    $cutoff1 = $expr->getPreviousRunDate(new \DateTime('2024-01-15 10:45:00'))
      ->getTimestamp();
    $created = (new \DateTime('2024-01-15 10:15:00'))->getTimestamp();
    $this->assertGreaterThan($cutoff1, $created, 'Entry created after boundary is valid');

    // At 11:05, last boundary was 11:00; entry from 10:15 is now stale.
    $cutoff2 = $expr->getPreviousRunDate(new \DateTime('2024-01-15 11:05:00'))
      ->getTimestamp();
    $this->assertLessThan($cutoff2, $created, 'Entry created before boundary is stale');
  }

}

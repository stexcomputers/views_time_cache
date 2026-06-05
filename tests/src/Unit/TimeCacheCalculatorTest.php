<?php

declare(strict_types=1);

namespace Drupal\Tests\views_time_cache\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\views_time_cache\TimeCacheCalculator;

/**
 * Unit tests for TimeCacheCalculator.
 *
 * @coversDefaultClass \Drupal\views_time_cache\TimeCacheCalculator
 * @group views_time_cache
 */
class TimeCacheCalculatorTest extends UnitTestCase {

  /**
   * A fixed UTC timestamp: 2024-06-15 10:30:00 UTC.
   *
   * For the hourly expression '0 * * * *':
   *   - next run  = 2024-06-15 11:00:00 UTC → 1800 seconds away
   *   - prev run  = 2024-06-15 10:00:00 UTC → 1800 seconds ago.
   */
  private const FIXTURE_TS = 1718447400;

  /**
   * Builds a calculator with mocked dependencies.
   *
   * @param int $requestTime
   *   Unix timestamp returned by the time service.
   * @param string $timezone
   *   Site timezone string (default 'UTC').
   *
   * @return \Drupal\views_time_cache\TimeCacheCalculator
   *   Configured calculator instance.
   */
  private function buildCalculator(
    int $requestTime,
    string $timezone = 'UTC',
  ): TimeCacheCalculator {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($requestTime);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('timezone.default')
      ->willReturn($timezone);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('system.date')
      ->willReturn($config);

    return new TimeCacheCalculator($time, $configFactory);
  }

  /**
   * @covers ::maxAgeForPreset
   */
  public function testMaxAgeForPresetPositive(): void {
    $calc = $this->buildCalculator(self::FIXTURE_TS);
    $this->assertSame(3600, $calc->maxAgeForPreset(3600));
    $this->assertSame(86400, $calc->maxAgeForPreset(86400));
  }

  /**
   * @covers ::maxAgeForPreset
   */
  public function testMaxAgeForPresetForever(): void {
    $calc = $this->buildCalculator(self::FIXTURE_TS);
    $this->assertSame(Cache::PERMANENT, $calc->maxAgeForPreset(0));
  }

  /**
   * Never preset (-2) returns max-age 0 (uncacheable).
   *
   * @covers ::maxAgeForPreset
   */
  public function testMaxAgeForPresetNever(): void {
    $calc = $this->buildCalculator(self::FIXTURE_TS);
    $this->assertSame(0, $calc->maxAgeForPreset(-2));
  }

  /**
   * @covers ::maxAgeForCron
   */
  public function testMaxAgeForCronHourly(): void {
    $calc = $this->buildCalculator(self::FIXTURE_TS);
    // At 10:30 UTC, next hourly boundary is 11:00 = 1800 s.
    $maxAge = $calc->maxAgeForCron('0 * * * *');
    $this->assertSame(1800, $maxAge);
  }

  /**
   * @covers ::maxAgeForCron
   */
  public function testMaxAgeForCronMinimumOne(): void {
    $calc = $this->buildCalculator(self::FIXTURE_TS);
    // Any valid expression must return at least 1.
    $maxAge = $calc->maxAgeForCron('0 * * * *');
    $this->assertGreaterThanOrEqual(1, $maxAge);
  }

  /**
   * @covers ::maxAgeForCron
   */
  public function testMaxAgeForCronInvalidExpressionReturnsPermament(): void {
    $calc = $this->buildCalculator(self::FIXTURE_TS);
    $this->assertSame(Cache::PERMANENT, $calc->maxAgeForCron('not a cron'));
  }

  /**
   * Impossible DOM/month combination is rejected fast and returns PERMANENT.
   *
   * Regression test: before the unsatisfiable-date check, an expression like
   * '0 0 30 2 *' (Feb 30) would scan ~2 M iterations (~3 s) before falling
   * back to PERMANENT.  With the fix the constructor throws immediately and
   * maxAgeForCron() returns PERMANENT in microseconds.
   *
   * @covers ::maxAgeForCron
   */
  public function testMaxAgeForCronImpossibleDateReturnsPermanentFast(): void {
    $calc = $this->buildCalculator(self::FIXTURE_TS);
    $start = microtime(TRUE);
    $result = $calc->maxAgeForCron('0 0 30 2 *');
    $elapsed = microtime(TRUE) - $start;
    $this->assertSame(Cache::PERMANENT, $result);
    // Should resolve in well under 1 second; 0.5 s is generous on any host.
    $this->assertLessThan(0.5, $elapsed, 'Impossible expression must fail fast.');
  }

  /**
   * @covers ::cutoffForCron
   */
  public function testCutoffForCronHourly(): void {
    $calc = $this->buildCalculator(self::FIXTURE_TS);
    // At 10:30 UTC, previous hourly boundary is 10:00.
    $cutoff = $calc->cutoffForCron('0 * * * *');
    // 10:00 UTC = 10:30 minus 30 minutes.
    $expected = self::FIXTURE_TS - 1800;
    $this->assertSame($expected, $cutoff);
  }

  /**
   * @covers ::cutoffForCron
   */
  public function testCutoffForCronPastBoundary(): void {
    $calc = $this->buildCalculator(self::FIXTURE_TS);
    $cutoff = $calc->cutoffForCron('0 * * * *');
    $this->assertIsInt($cutoff);
    $this->assertLessThanOrEqual(self::FIXTURE_TS, $cutoff);
  }

  /**
   * @covers ::cutoffForCron
   */
  public function testCutoffForCronInvalidExpressionReturnsFalse(): void {
    $calc = $this->buildCalculator(self::FIXTURE_TS);
    $this->assertFalse($calc->cutoffForCron('not a cron'));
  }

  /**
   * @covers ::getPresetOptions
   */
  public function testGetPresetOptionsStructure(): void {
    $calc    = $this->buildCalculator(self::FIXTURE_TS);
    $options = $calc->getPresetOptions();
    $this->assertIsArray($options);
    $this->assertCount(7, $options);
    // Never.
    $this->assertArrayHasKey(-2, $options);
    // Forever.
    $this->assertArrayHasKey(0, $options);
    // 1 hour.
    $this->assertArrayHasKey(3600, $options);
    // 1 day.
    $this->assertArrayHasKey(86400, $options);
    // 1 week.
    $this->assertArrayHasKey(604800, $options);
  }

  /**
   * @covers ::getSiteNow
   */
  public function testGetSiteNowTimezone(): void {
    $calc = $this->buildCalculator(self::FIXTURE_TS, 'America/Chicago');
    $now  = $calc->getSiteNow();
    $this->assertInstanceOf(\DateTime::class, $now);
    $this->assertSame(
      self::FIXTURE_TS,
      $now->getTimestamp(),
      'getSiteNow() timestamp matches the request time.'
    );
    $this->assertSame('America/Chicago', $now->getTimezone()->getName());
  }

}

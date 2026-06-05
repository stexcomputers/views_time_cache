<?php

namespace Drupal\views_time_cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Computes time-based cache max-age values for presets and cron expressions.
 *
 * Shared by the Views cache plugin and the per-block max-age feature so that
 * preset definitions and cron boundary arithmetic live in one place.
 */
class TimeCacheCalculator {

  /**
   * Per-request cache for cron max-age, keyed by "expression|minute".
   *
   * @var array<string, int>
   */
  private static array $cronMaxAgeCache = [];

  /**
   * Per-request cache for cron cutoffs, keyed by "expression|minute".
   *
   * @var array<string, int|false>
   */
  private static array $cronCutoffCache = [];

  /**
   * Constructs a TimeCacheCalculator.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory service.
   */
  public function __construct(
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns a DateTime for the current request in the configured site timezone.
   *
   * @return \DateTime
   *   Current request time with the site timezone applied.
   */
  public function getSiteNow(): \DateTime {
    $tzName = $this->configFactory
      ->get('system.date')
      ->get('timezone.default') ?? 'UTC';
    return (new \DateTime('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone($tzName));
  }

  /**
   * Returns the cache max-age for a preset interval.
   *
   * @param int $preset
   *   Seconds; 0 = forever (Cache::PERMANENT); negative = never (max-age 0).
   *
   * @return int
   *   Seconds, Cache::PERMANENT (-1) when $preset is 0 (forever), or 0 when
   *   $preset is negative (never cache).
   */
  public function maxAgeForPreset(int $preset): int {
    if ($preset > 0) {
      return $preset;
    }
    // 0 = Forever (no time-based expiry; rely on cache tags).
    // Negative = Never (max-age 0; Drupal treats the item as uncacheable).
    return $preset === 0 ? Cache::PERMANENT : 0;
  }

  /**
   * Returns seconds until the next cron boundary, for cache max-age metadata.
   *
   * Results are memoised per expression per request-minute so multiple blocks
   * sharing the same expression pay the evaluation cost only once.
   *
   * @param string $expr
   *   A valid 5-field cron expression.
   *
   * @return int
   *   Seconds until the next run time, minimum 1.
   *   Returns Cache::PERMANENT on any evaluation error.
   */
  public function maxAgeForCron(string $expr): int {
    $key = $expr . '|' . (int) ($this->time->getRequestTime() / 60);
    if (array_key_exists($key, self::$cronMaxAgeCache)) {
      return self::$cronMaxAgeCache[$key];
    }
    try {
      $now = $this->getSiteNow();
      $next = (new CronExpression($expr))->getNextRunDate($now);
      $result = max(1, $next->getTimestamp() - $this->time->getRequestTime());
    }
    catch (\Throwable) {
      $result = Cache::PERMANENT;
    }
    self::$cronMaxAgeCache[$key] = $result;
    return $result;
  }

  /**
   * Returns the most-recent cron boundary timestamp for staleness checking.
   *
   * Results are memoised per expression per request-minute.
   *
   * @param string $expr
   *   A valid 5-field cron expression.
   *
   * @return int|false
   *   Unix timestamp of the last matching cron boundary, or FALSE on error.
   */
  public function cutoffForCron(string $expr): int|false {
    $key = $expr . '|' . (int) ($this->time->getRequestTime() / 60);
    if (array_key_exists($key, self::$cronCutoffCache)) {
      return self::$cronCutoffCache[$key];
    }
    try {
      $now    = $this->getSiteNow();
      $result = (new CronExpression($expr))
        ->getPreviousRunDate($now)
        ->getTimestamp();
    }
    catch (\Throwable) {
      $result = FALSE;
    }
    self::$cronCutoffCache[$key] = $result;
    return $result;
  }

  /**
   * Returns the labelled preset options for form selects.
   *
   * @return array<int, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Keyed by seconds. Special values: 0 = Forever (Cache::PERMANENT);
   *   -2 = Never (max-age 0, uncacheable).
   */
  public function getPresetOptions(): array {
    return [
      -2     => new TranslatableMarkup('Never'),
      3600   => new TranslatableMarkup('1 hour'),
      21600  => new TranslatableMarkup('6 hours'),
      43200  => new TranslatableMarkup('12 hours'),
      86400  => new TranslatableMarkup('1 day'),
      604800 => new TranslatableMarkup('1 week'),
      0      => new TranslatableMarkup('Forever'),
    ];
  }

}

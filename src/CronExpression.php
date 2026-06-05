<?php

namespace Drupal\views_time_cache;

/**
 * Evaluates a standard 5-field cron expression to find next/previous run times.
 *
 * Fields: minute hour day-of-month month day-of-week (0–7, 0 and 7 = Sunday).
 * Supports wildcards (*), ranges (1-5), step expressions (* /5, 1-5/2), and
 * comma-separated lists (1,3,5).
 * Day-of-month and day-of-week use OR logic when both are restricted.
 */
class CronExpression {

  /**
   * Parsed matching values for each field, keyed by value.
   *
   * @var array<int, array<int, bool>>
   */
  private array $fields;

  /**
   * Whether the day-of-month field is a bare wildcard (*).
   */
  private bool $domStar;

  /**
   * Whether the day-of-week field is a bare wildcard (*).
   */
  private bool $dowStar;

  /**
   * Maximum iterations for next/previous search (just over 1 year of minutes).
   */
  private const MAX_ITERATIONS = 527040;

  /**
   * Constructs a CronExpression.
   *
   * @param string $expression
   *   A standard 5-field cron expression.
   *
   * @throws \InvalidArgumentException
   *   If the expression format is invalid.
   */
  public function __construct(string $expression) {
    $parts = preg_split('/\s+/', trim($expression));
    if (count($parts) !== 5) {
      throw new \InvalidArgumentException('Cron expression must have exactly 5 fields.');
    }

    $this->fields = [
      $this->parseField($parts[0], 0, 59),
      $this->parseField($parts[1], 0, 23),
      $this->parseField($parts[2], 1, 31),
      $this->parseField($parts[3], 1, 12),
      $this->parseDow($parts[4]),
    ];
    $this->domStar = (trim($parts[2]) === '*');
    $this->dowStar = (trim($parts[4]) === '*');
  }

  /**
   * Returns the next run time strictly after $after.
   *
   * @param \DateTimeInterface $after
   *   The reference time; the result will be strictly later than this.
   *
   * @return \DateTime
   *   The next matching date/time.
   *
   * @throws \RuntimeException
   *   If no match is found within 1 year.
   */
  public function getNextRunDate(\DateTimeInterface $after): \DateTime {
    $dt = \DateTime::createFromInterface($after);
    // Advance at least one full minute and zero out seconds.
    $dt->setTimestamp($dt->getTimestamp() + 60);
    $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 0);

    for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
      if ($this->matches($dt)) {
        return $dt;
      }
      $dt->modify('+1 minute');
    }
    throw new \RuntimeException('No cron match found within 1 year.');
  }

  /**
   * Returns the most recent run time at or before $before.
   *
   * @param \DateTimeInterface $before
   *   The reference time; the result will be at or earlier than this.
   *
   * @return \DateTime
   *   The previous (or current) matching date/time.
   *
   * @throws \RuntimeException
   *   If no match is found within 1 year.
   */
  public function getPreviousRunDate(\DateTimeInterface $before): \DateTime {
    $dt = \DateTime::createFromInterface($before);
    // Truncate to the current minute.
    $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 0);

    for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
      if ($this->matches($dt)) {
        return $dt;
      }
      $dt->modify('-1 minute');
    }
    throw new \RuntimeException('No cron match found within 1 year.');
  }

  /**
   * Returns TRUE if the expression string is syntactically valid.
   *
   * @param string $expression
   *   The cron expression to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  public static function isValid(string $expression): bool {
    try {
      new self($expression);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Checks whether $dt matches this expression.
   *
   * @param \DateTimeInterface $dt
   *   The date/time to test.
   *
   * @return bool
   *   TRUE if the date/time matches the expression.
   */
  private function matches(\DateTimeInterface $dt): bool {
    $minute = (int) $dt->format('i');
    $hour   = (int) $dt->format('G');
    $dom    = (int) $dt->format('j');
    $month  = (int) $dt->format('n');
    $dow    = (int) $dt->format('w');

    if (!isset($this->fields[0][$minute])) {
      return FALSE;
    }
    if (!isset($this->fields[1][$hour])) {
      return FALSE;
    }
    if (!isset($this->fields[3][$month])) {
      return FALSE;
    }

    // DOM/DOW: OR when both restricted; AND when either is a wildcard.
    if ($this->domStar && $this->dowStar) {
      return TRUE;
    }
    if ($this->domStar) {
      return isset($this->fields[4][$dow]);
    }
    if ($this->dowStar) {
      return isset($this->fields[2][$dom]);
    }
    // Neither is *, use OR.
    return isset($this->fields[2][$dom]) || isset($this->fields[4][$dow]);
  }

  /**
   * Parses a single cron field into a set of matching integer values.
   *
   * @param string $field
   *   The raw field string (e.g. "1-5/2" or "* /15").
   * @param int $min
   *   The minimum allowed value for this field.
   * @param int $max
   *   The maximum allowed value for this field.
   *
   * @return array<int, bool>
   *   Associative array keyed by each matching integer value.
   *
   * @throws \InvalidArgumentException
   *   On out-of-range or malformed input.
   */
  private function parseField(string $field, int $min, int $max): array {
    $values = [];
    foreach (explode(',', $field) as $part) {
      $step = 1;
      if (str_contains($part, '/')) {
        [$part, $stepStr] = explode('/', $part, 2);
        $step = (int) $stepStr;
        if ($step < 1) {
          throw new \InvalidArgumentException("Step must be >= 1, got '$stepStr'.");
        }
      }
      if ($part === '*') {
        $start = $min;
        $end   = $max;
      }
      elseif (str_contains($part, '-')) {
        $range_parts = explode('-', $part, 2);
        if (!is_numeric($range_parts[0]) || !is_numeric($range_parts[1])) {
          throw new \InvalidArgumentException("Non-numeric value in range '$part'.");
        }
        $start = (int) $range_parts[0];
        $end   = (int) $range_parts[1];
      }
      else {
        if (!is_numeric($part)) {
          throw new \InvalidArgumentException("Non-numeric value '$part' in cron field.");
        }
        $start = $end = (int) $part;
      }
      if ($start < $min || $end > $max || $start > $end) {
        throw new \InvalidArgumentException(
          "Value '$part' is out of range [$min, $max]."
        );
      }
      for ($i = $start; $i <= $end; $i += $step) {
        $values[$i] = TRUE;
      }
    }
    return $values;
  }

  /**
   * Parses the day-of-week field, normalising 7 (Sunday alt) to 0.
   *
   * @param string $field
   *   The raw day-of-week field string.
   *
   * @return array<int, bool>
   *   Associative array keyed by matching day-of-week integers (0–6).
   */
  private function parseDow(string $field): array {
    // Allow 0–7 in the raw parse; normalise 7→0 afterwards.
    $values = $this->parseField($field, 0, 7);
    if (isset($values[7])) {
      $values[0] = TRUE;
      unset($values[7]);
    }
    return $values;
  }

}

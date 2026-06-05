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
   * Constructs a CronExpression.
   *
   * @param string $expression
   *   A standard 5-field cron expression.
   *
   * @throws \InvalidArgumentException
   *   If the expression format is invalid or describes an impossible date
   *   (e.g. Feb 30, Apr 31) when the day-of-week field is unrestricted.
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

    // When DOW is unrestricted and DOM is constrained, verify that at least
    // one month/day combination is satisfiable. For example, '0 0 30 2 *'
    // (Feb 30) must be rejected. February uses 29 so that the valid leap-day
    // expression '0 0 29 2 *' is still accepted.
    // This check is skipped when DOW is also constrained (non-star) because
    // the OR logic means any matching weekday in the month is sufficient —
    // e.g. '0 0 30 2 1' (30th of Feb OR any Monday in Feb) IS satisfiable.
    if ($this->dowStar && !$this->domStar) {
      $maxDaysByMonth = [
        1  => 31,
        2  => 29,
        3  => 31,
        4  => 30,
        5  => 31,
        6  => 30,
        7  => 31,
        8  => 31,
        9  => 30,
        10 => 31,
        11 => 30,
        12 => 31,
      ];
      $satisfiable = FALSE;
      foreach (array_keys($this->fields[3]) as $month) {
        $maxDays = $maxDaysByMonth[$month];
        foreach (array_keys($this->fields[2]) as $dom) {
          if ($dom <= $maxDays) {
            $satisfiable = TRUE;
            break 2;
          }
        }
      }
      if (!$satisfiable) {
        throw new \InvalidArgumentException(
          'The day-of-month and month combination never matches any calendar date.'
        );
      }
    }
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
   *   If no match is found within a 5-year search window.
   */
  public function getNextRunDate(\DateTimeInterface $after): \DateTime {
    $dt = \DateTime::createFromInterface($after);
    // Advance at least one full minute and zero out seconds.
    $dt->setTimestamp($dt->getTimestamp() + 60);
    $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 0);

    // 5-year guard: every valid cron expression recurs within 4 years
    // (the longest cycle is a Feb-29 leap-day expression).  Impossible
    // expressions are rejected in __construct(), so this limit is purely
    // defence-in-depth and will be reached only in pathological edge cases.
    $limit = (clone $dt)->modify('+5 years');
    while ($dt <= $limit) {
      if (!$this->monthMatches($dt)) {
        $dt->modify('first day of next month');
        $dt->setTime(0, 0, 0);
        continue;
      }
      if (!$this->dayMatches($dt)) {
        $dt->modify('+1 day');
        $dt->setTime(0, 0, 0);
        continue;
      }
      if (!$this->hourMatches($dt)) {
        $dt->modify('+1 hour');
        $dt->setTime((int) $dt->format('H'), 0, 0);
        continue;
      }
      if ($this->matches($dt)) {
        return $dt;
      }
      $dt->modify('+1 minute');
    }
    throw new \RuntimeException('No cron match found within 5-year search window.');
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
   *   If no match is found within a 5-year search window.
   */
  public function getPreviousRunDate(\DateTimeInterface $before): \DateTime {
    $dt = \DateTime::createFromInterface($before);
    // Truncate to the current minute.
    $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 0);

    // 5-year guard (see getNextRunDate for rationale).
    $limit = (clone $dt)->modify('-5 years');
    while ($dt >= $limit) {
      if (!$this->monthMatches($dt)) {
        $dt->modify('last day of last month');
        $dt->setTime(23, 59, 0);
        continue;
      }
      if (!$this->dayMatches($dt)) {
        $dt->modify('-1 day');
        $dt->setTime(23, 59, 0);
        continue;
      }
      if (!$this->hourMatches($dt)) {
        $dt->modify('-1 hour');
        $dt->setTime((int) $dt->format('H'), 59, 0);
        continue;
      }
      if ($this->matches($dt)) {
        return $dt;
      }
      $dt->modify('-1 minute');
    }
    throw new \RuntimeException('No cron match found within 5-year search window.');
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
   * Returns TRUE if the month field matches $dt.
   */
  private function monthMatches(\DateTimeInterface $dt): bool {
    return isset($this->fields[3][(int) $dt->format('n')]);
  }

  /**
   * Returns TRUE if the hour field matches $dt.
   */
  private function hourMatches(\DateTimeInterface $dt): bool {
    return isset($this->fields[1][(int) $dt->format('G')]);
  }

  /**
   * Returns TRUE if the DOM/DOW fields match $dt (OR logic when both set).
   */
  private function dayMatches(\DateTimeInterface $dt): bool {
    $dom = (int) $dt->format('j');
    $dow = (int) $dt->format('w');
    if ($this->domStar && $this->dowStar) {
      return TRUE;
    }
    if ($this->domStar) {
      return isset($this->fields[4][$dow]);
    }
    if ($this->dowStar) {
      return isset($this->fields[2][$dom]);
    }
    return isset($this->fields[2][$dom]) || isset($this->fields[4][$dow]);
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
    if (!isset($this->fields[0][(int) $dt->format('i')])) {
      return FALSE;
    }
    return $this->hourMatches($dt)
      && $this->monthMatches($dt)
      && $this->dayMatches($dt);
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

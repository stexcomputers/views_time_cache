<?php

declare(strict_types=1);

namespace Drupal\Tests\views_time_cache\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Tests\views\Kernel\ViewsKernelTestBase;
use Drupal\views\Views;

/**
 * Proves a real Views display drives the views_time_cache plugin methods.
 *
 * Mirrors core's CacheTest::testTimeResultCaching() pattern but exercises
 * views_time_cache in all three modes: preset interval, Forever
 * (Cache::PERMANENT), and cron expression. On each test the view is executed
 * once to prime the cache, a new row is inserted into the fixture table, and
 * then the view is executed again. If caching is active the stale 5-row
 * result is served rather than the live 6-row set, proving that cacheSet
 * and cacheGet ran through our plugin on a real display.
 *
 * @group views_time_cache
 * @see \Drupal\Tests\views\Kernel\Plugin\CacheTest
 */
class ViewsTimeCacheResultCachingTest extends ViewsKernelTestBase {

  /**
   * Test views imported from core's views_test_config module.
   *
   * @var string[]
   */
  public static $testViews = ['test_cache'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['views_time_cache'];

  /**
   * Verifies preset-interval mode caches results through a real display.
   *
   * With a 1-hour results preset the cache entry created on first execution
   * must still be served on re-execution even after a new row is inserted.
   */
  public function testPresetIntervalCachesResults(): void {
    $view = Views::getView('test_cache');
    $view->setDisplay();
    $view->display_handler->overrideOption('cache', [
      'type' => 'views_time_cache',
      'options' => [
        'cache_mode' => 'preset',
        'results_preset' => 3600,
        'output_preset' => 3600,
        'cron_expression' => '0 * * * *',
      ],
    ]);

    $this->executeView($view);
    $this->assertCount(5, $view->result,
      'Initial execution returns 5 rows.');

    // Insert an extra row; with the 1-hour preset active the re-execution
    // must still serve the stale 5-row result (note: views_test_data rows
    // have no cache tags, so only time-based expiry applies here).
    Database::getConnection()->insert('views_test_data')
      ->fields(['name' => 'Rod Davis', 'age' => 29, 'job' => 'Banjo'])
      ->execute();

    $view->destroy();
    $this->executeView($view);
    $this->assertCount(5, $view->result,
      'Re-execution serves cached results (5 rows, not 6).');
  }

  /**
   * Verifies the Forever preset (Cache::PERMANENT) caches results.
   *
   * A results_preset of 0 disables time-based expiry (cacheExpire returns
   * FALSE) so the entry is stored as Cache::PERMANENT and must always be
   * served.
   */
  public function testForeverPresetCachesResults(): void {
    $view = Views::getView('test_cache');
    $view->setDisplay();
    $view->display_handler->overrideOption('cache', [
      'type' => 'views_time_cache',
      'options' => [
        'cache_mode' => 'preset',
        'results_preset' => 0,
        'output_preset' => 0,
        'cron_expression' => '0 * * * *',
      ],
    ]);

    $this->executeView($view);
    $this->assertCount(5, $view->result,
      'Initial execution returns 5 rows.');

    Database::getConnection()->insert('views_test_data')
      ->fields(['name' => 'Rod Davis', 'age' => 29, 'job' => 'Banjo'])
      ->execute();

    $view->destroy();
    $this->executeView($view);
    $this->assertCount(5, $view->result,
      'Forever preset serves cached results (5 rows, not 6).');
  }

  /**
   * Verifies cron-expression mode caches results through a real display.
   *
   * Uses an annual expression (0 0 1 1 *) so the previous cron boundary is
   * always well in the past — the cache entry created during the test is
   * guaranteed to fall after that boundary and therefore be served on
   * re-execution regardless of when in the year the test runs.
   */
  public function testCronExpressionCachesResults(): void {
    $view = Views::getView('test_cache');
    $view->setDisplay();
    $view->display_handler->overrideOption('cache', [
      'type' => 'views_time_cache',
      'options' => [
        'cache_mode' => 'cron',
        'results_preset' => 3600,
        'output_preset' => 3600,
        'cron_expression' => '0 0 1 1 *',
      ],
    ]);

    $this->executeView($view);
    $this->assertCount(5, $view->result,
      'Cron mode initial execution returns 5 rows.');

    Database::getConnection()->insert('views_test_data')
      ->fields(['name' => 'Rod Davis', 'age' => 29, 'job' => 'Banjo'])
      ->execute();

    $view->destroy();
    $this->executeView($view);
    $this->assertCount(5, $view->result,
      'Cron mode serves cached results (5 rows, not 6).');
  }

}

<?php

namespace Drupal\Tests\views_time_cache\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\KernelTests\KernelTestBase;
use Drupal\views_time_cache\Plugin\views\cache\ViewsTimeCache;

/**
 * Kernel tests for the ViewsTimeCache cache plugin.
 *
 * @coversDefaultClass \Drupal\views_time_cache\Plugin\views\cache\ViewsTimeCache
 * @group views_time_cache
 */
class ViewsTimeCachePluginTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'views', 'views_time_cache'];

  /**
   * Creates a plugin instance with the given options pre-set.
   *
   * @param array $options
   *   Plugin options to inject.
   *
   * @return \Drupal\views_time_cache\Plugin\views\cache\ViewsTimeCache
   *   Configured plugin instance.
   */
  protected function createPlugin(array $options): ViewsTimeCache {
    $plugin = ViewsTimeCache::create(
      $this->container,
      [],
      'views_time_cache',
      ['id' => 'views_time_cache', 'provider' => 'views_time_cache'],
    );
    // Options is public on ComponentPluginBase.
    $plugin->options = $options;
    return $plugin;
  }

  /**
   * Invokes a protected method on the plugin via reflection.
   *
   * @param \Drupal\views_time_cache\Plugin\views\cache\ViewsTimeCache $plugin
   *   Plugin instance.
   * @param string $method
   *   Method name.
   * @param mixed ...$args
   *   Method arguments.
   *
   * @return mixed
   *   Return value of the method.
   */
  protected function invoke(
    ViewsTimeCache $plugin,
    string $method,
    mixed ...$args,
  ): mixed {
    return (new \ReflectionMethod($plugin, $method))->invoke($plugin, ...$args);
  }

  /**
   * @covers ::create
   */
  public function testPluginDiscovery(): void {
    $manager = $this->container->get('plugin.manager.views.cache');
    $this->assertTrue($manager->hasDefinition('views_time_cache'));
    $def = $manager->getDefinition('views_time_cache');
    $this->assertSame('views_time_cache', $def['id']);
  }

  /**
   * @covers ::create
   */
  public function testPluginInstantiates(): void {
    $plugin = ViewsTimeCache::create(
      $this->container,
      [],
      'views_time_cache',
      ['id' => 'views_time_cache', 'provider' => 'views_time_cache'],
    );
    $this->assertInstanceOf(ViewsTimeCache::class, $plugin);
  }

  /**
   * @covers ::cacheSetMaxAge
   */
  public function testPresetModeMaxAge(): void {
    $plugin = $this->createPlugin([
      'cache_mode' => 'preset',
      'results_preset' => 3600,
      'output_preset' => 86400,
      'cron_expression' => '0 * * * *',
    ]);

    $this->assertSame(
      3600,
      $this->invoke($plugin, 'cacheSetMaxAge', 'results'),
    );
    $this->assertSame(
      86400,
      $this->invoke($plugin, 'cacheSetMaxAge', 'output'),
    );
  }

  /**
   * @covers ::cacheExpire
   * @covers ::cacheSetMaxAge
   */
  public function testPresetForever(): void {
    $plugin = $this->createPlugin([
      'cache_mode' => 'preset',
      'results_preset' => 0,
      'output_preset' => 0,
      'cron_expression' => '0 * * * *',
    ]);

    $this->assertFalse(
      $this->invoke($plugin, 'cacheExpire', 'results'),
    );
    $this->assertSame(
      Cache::PERMANENT,
      $this->invoke($plugin, 'cacheSetMaxAge', 'results'),
    );
  }

  /**
   * @covers ::cacheExpire
   * @covers ::cacheSetMaxAge
   */
  public function testCronMode(): void {
    $plugin = $this->createPlugin([
      'cache_mode' => 'cron',
      'results_preset' => 3600,
      'output_preset' => 3600,
      'cron_expression' => '0 * * * *',
    ]);

    $expire = $this->invoke($plugin, 'cacheExpire', 'results');
    $this->assertIsInt($expire);
    // The cutoff must be a past hourly boundary.
    $this->assertLessThanOrEqual(time(), $expire);

    $maxAge = $this->invoke($plugin, 'cacheSetMaxAge', 'results');
    $this->assertGreaterThan(0, $maxAge);
    $this->assertLessThanOrEqual(3600, $maxAge);
  }

}

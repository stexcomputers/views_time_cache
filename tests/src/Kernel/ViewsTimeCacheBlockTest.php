<?php

declare(strict_types=1);

namespace Drupal\Tests\views_time_cache\Kernel;

use Drupal\block\Entity\Block;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Cache\Cache;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for the block cache max-age feature.
 *
 * Verifies that hook_block_build_alter() correctly overrides
 * #cache['max-age'] on the block render array based on the Views Time Cache
 * third-party settings stored on the Block config entity.
 *
 * @group views_time_cache
 */
class ViewsTimeCacheBlockTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'block', 'views_time_cache'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Install system config so system.date (timezone) is available.
    $this->installConfig(['system']);
  }

  /**
   * Creates a minimal Block config entity for testing.
   *
   * Writes the config record directly so no block plugin or theme validation
   * is triggered, keeping the test lightweight.
   *
   * @param string $id
   *   Block entity machine name.
   * @param array $vtcSettings
   *   Views Time Cache third-party settings array, e.g.
   *   ['max_age_mode' => 'preset', 'max_age_preset' => 3600,
   *   'cron_expression' => ''].
   *
   * @return \Drupal\block\Entity\Block
   *   The loaded Block entity.
   */
  private function createTestBlock(
    string $id,
    array $vtcSettings,
  ): Block {
    $data = [
      'id'       => $id,
      'theme'    => 'stark',
      'region'   => 'content',
      'plugin'   => 'system_powered_by_block',
      'weight'   => 0,
      'status'   => TRUE,
      'settings' => [
        'id'            => 'system_powered_by_block',
        'label'         => 'Test block',
        'provider'      => 'system',
        'label_display' => '0',
      ],
      'third_party_settings' => [
        'views_time_cache' => $vtcSettings,
      ],
    ];
    // Write directly to config to avoid plugin/theme validation overhead.
    \Drupal::configFactory()
      ->getEditable('block.block.' . $id)
      ->setData($data)
      ->save();

    $block = Block::load($id);
    $this->assertNotNull($block, "Block entity '$id' must be loadable.");
    return $block;
  }

  /**
   * Builds a simulated outer #cache array as BlockViewBuilder would produce.
   *
   * @param string $blockId
   *   The block entity ID.
   * @param int $initialMaxAge
   *   The max-age the block plugin declares.
   *
   * @return array
   *   A minimal #cache render array.
   */
  private function makeBuild(string $blockId, int $initialMaxAge = 0): array {
    return [
      '#cache' => [
        'keys'     => ['entity_view', 'block', $blockId],
        'contexts' => [],
        'tags'     => [],
        'max-age'  => $initialMaxAge,
      ],
    ];
  }

  /**
   * Returns a minimal BlockPluginInterface mock.
   *
   * @return \Drupal\Core\Block\BlockPluginInterface
   *   Mocked plugin instance.
   */
  private function mockPlugin(): BlockPluginInterface {
    return $this->createMock(BlockPluginInterface::class);
  }

  /**
   * Verifies that preset mode sets the configured max-age on the build array.
   */
  public function testPresetMaxAgeApplied(): void {
    $block = $this->createTestBlock('vtc_preset', [
      'max_age_mode'   => 'preset',
      'max_age_preset' => 3600,
      'cron_expression' => '',
    ]);

    $build  = $this->makeBuild($block->id());
    $plugin = $this->mockPlugin();
    views_time_cache_block_build_alter($build, $plugin);

    $this->assertSame(
      3600,
      $build['#cache']['max-age'],
      'Preset 1-hour max-age must be applied to the build array.'
    );
  }

  /**
   * Verifies that the Forever preset sets Cache::PERMANENT.
   */
  public function testForeverPresetApplied(): void {
    $block = $this->createTestBlock('vtc_forever', [
      'max_age_mode'    => 'preset',
      'max_age_preset'  => 0,
      'cron_expression' => '',
    ]);

    $build  = $this->makeBuild($block->id());
    $plugin = $this->mockPlugin();
    views_time_cache_block_build_alter($build, $plugin);

    $this->assertSame(
      Cache::PERMANENT,
      $build['#cache']['max-age'],
      'Forever (preset=0) must result in Cache::PERMANENT (-1).'
    );
  }

  /**
   * Verifies that cron mode sets a positive max-age bounded by the interval.
   */
  public function testCronMaxAgeApplied(): void {
    $block = $this->createTestBlock('vtc_cron', [
      'max_age_mode'    => 'cron',
      'max_age_preset'  => 3600,
      'cron_expression' => '0 * * * *',
    ]);

    $build  = $this->makeBuild($block->id());
    $plugin = $this->mockPlugin();
    views_time_cache_block_build_alter($build, $plugin);

    $maxAge = $build['#cache']['max-age'];
    $this->assertGreaterThanOrEqual(
      1,
      $maxAge,
      'Cron mode must produce a max-age of at least 1 second.'
    );
    $this->assertLessThanOrEqual(
      3600,
      $maxAge,
      'Cron mode max-age for an hourly expression must not exceed 3600 s.'
    );
  }

  /**
   * Verifies that a block with no override leaves max-age unchanged.
   */
  public function testNoOverrideIsNoOp(): void {
    $block = $this->createTestBlock('vtc_none', [
      'max_age_mode'    => '',
      'max_age_preset'  => 3600,
      'cron_expression' => '',
    ]);

    $build  = $this->makeBuild($block->id(), 42);
    $plugin = $this->mockPlugin();
    views_time_cache_block_build_alter($build, $plugin);

    $this->assertSame(
      42,
      $build['#cache']['max-age'],
      'No-override mode must not change the max-age.'
    );
  }

  /**
   * Verifies that a non-existent block ID is handled gracefully.
   */
  public function testMissingBlockIsNoOp(): void {
    $build = [
      '#cache' => [
        'keys'    => ['entity_view', 'block', 'does_not_exist'],
        'max-age' => 99,
      ],
    ];
    $plugin = $this->mockPlugin();
    views_time_cache_block_build_alter($build, $plugin);

    $this->assertSame(
      99,
      $build['#cache']['max-age'],
      'A missing block entity must not change the max-age.'
    );
  }

}

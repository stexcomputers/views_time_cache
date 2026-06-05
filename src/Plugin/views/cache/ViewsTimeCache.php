<?php

namespace Drupal\views_time_cache\Plugin\views\cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsCache;
use Drupal\views\Plugin\views\cache\CachePluginBase;
use Drupal\views_time_cache\CronExpression;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Time-based Views cache plugin with preset intervals and cron support.
 *
 * @ingroup views_cache_plugins
 */
#[ViewsCache(
  id: 'views_time_cache',
  title: new TranslatableMarkup('Time-based (presets & cron)'),
  help: new TranslatableMarkup('Cache view results for a preset duration or until a cron expression fires.'),
)]
class ViewsTimeCache extends CachePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesOptions = TRUE;

  /**
   * Constructs a ViewsTimeCache plugin.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Date formatter service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['cache_mode'] = ['default' => 'preset'];
    $options['preset'] = ['default' => 3600];
    $options['cron_expression'] = ['default' => '0 * * * *'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['cache_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Cache mode'),
      '#options' => [
        'preset' => $this->t('Preset interval'),
        'cron'   => $this->t('Cron expression'),
      ],
      '#default_value' => $this->options['cache_mode'],
    ];

    $form['preset'] = [
      '#type' => 'select',
      '#title' => $this->t('Interval'),
      '#options' => [
        3600   => $this->t('1 hour'),
        21600  => $this->t('6 hours'),
        43200  => $this->t('12 hours'),
        86400  => $this->t('1 day'),
        604800 => $this->t('1 week'),
        0      => $this->t('Forever'),
      ],
      '#default_value' => $this->options['preset'],
      '#description' => $this->t('"Forever" disables time-based expiry; content cache tags still invalidate the cache.'),
      '#states' => [
        'visible' => [
          ':input[name="cache_options[cache_mode]"]' => ['value' => 'preset'],
        ],
      ],
    ];

    $form['cron_expression'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cron expression'),
      '#default_value' => $this->options['cron_expression'],
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      '#description' => $this->t('Standard 5-field cron expression (minute hour day-of-month month day-of-week). The cache rebuilds at each boundary. Examples: <code>0 * * * *</code> (hourly), <code>0 0 * * *</code> (midnight daily).'),
      '#states' => [
        'visible' => [
          ':input[name="cache_options[cache_mode]"]' => ['value' => 'cron'],
        ],
        'required' => [
          ':input[name="cache_options[cache_mode]"]' => ['value' => 'cron'],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    $cache_options = $form_state->getValue('cache_options');
    if (($cache_options['cache_mode'] ?? '') === 'cron') {
      $expr = trim($cache_options['cron_expression'] ?? '');
      if ($expr === '') {
        $form_state->setError($form['cron_expression'], $this->t('A cron expression is required.'));
      }
      elseif (!CronExpression::isValid($expr)) {
        $form_state->setError(
          $form['cron_expression'],
          $this->t('Invalid cron expression. Use standard 5-field format, e.g. <code>0 * * * *</code>.')
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    if ($this->options['cache_mode'] === 'cron') {
      return $this->t('Cron: @expr', ['@expr' => $this->options['cron_expression']]);
    }
    $preset = (int) $this->options['preset'];
    if ($preset === 0) {
      return $this->t('Forever');
    }
    return $this->t('Every @interval', ['@interval' => $this->dateFormatter->formatInterval($preset, 1)]);
  }

  /**
   * {@inheritdoc}
   *
   * Returns a Unix timestamp; cache entries created before this are stale.
   * In cron mode this is the most recent matching cron boundary.
   */
  protected function cacheExpire($type) {
    if ($this->options['cache_mode'] === 'cron') {
      return $this->getCronCutoff();
    }
    $lifespan = (int) $this->options['preset'];
    if ($lifespan === 0) {
      return FALSE;
    }
    return $this->time->getRequestTime() - $lifespan;
  }

  /**
   * {@inheritdoc}
   */
  protected function cacheSetMaxAge($type) {
    if ($this->options['cache_mode'] === 'cron') {
      return $this->getCronMaxAge();
    }
    $lifespan = (int) $this->options['preset'];
    return $lifespan > 0 ? $lifespan : Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultCacheMaxAge() {
    return (int) $this->cacheSetMaxAge('output');
  }

  /**
   * Returns the most-recent cron boundary timestamp for staleness checking.
   *
   * Any cache entry created before this timestamp is considered expired.
   *
   * @return int|false
   *   Unix timestamp of the last cron boundary, or FALSE on failure.
   */
  private function getCronCutoff(): int|false {
    try {
      $now = new \DateTime('@' . $this->time->getRequestTime());
      $expr = new CronExpression($this->options['cron_expression']);
      return $expr->getPreviousRunDate($now)->getTimestamp();
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Returns seconds until the next cron boundary, for cache max-age metadata.
   *
   * @return int
   *   Seconds until the next matching cron time, minimum 1.
   */
  private function getCronMaxAge(): int {
    try {
      $now = new \DateTime('@' . $this->time->getRequestTime());
      $expr = new CronExpression($this->options['cron_expression']);
      $next = $expr->getNextRunDate($now);
      return max(1, $next->getTimestamp() - $this->time->getRequestTime());
    }
    catch (\Throwable) {
      return Cache::PERMANENT;
    }
  }

}

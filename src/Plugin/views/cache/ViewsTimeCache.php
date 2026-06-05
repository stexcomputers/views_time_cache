<?php

namespace Drupal\views_time_cache\Plugin\views\cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
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
  help: new TranslatableMarkup('Cache view results for preset intervals or until a cron boundary.'),
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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['cache_mode'] = ['default' => 'preset'];
    $options['results_preset'] = ['default' => 3600];
    $options['output_preset'] = ['default' => 3600];
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

    $preset_options = [
      3600   => $this->t('1 hour'),
      21600  => $this->t('6 hours'),
      43200  => $this->t('12 hours'),
      86400  => $this->t('1 day'),
      604800 => $this->t('1 week'),
      0      => $this->t('Forever'),
    ];
    $preset_states = [
      'visible' => [
        ':input[name="cache_options[cache_mode]"]' => ['value' => 'preset'],
      ],
    ];

    $form['results_preset'] = [
      '#type' => 'select',
      '#title' => $this->t('Query results'),
      '#options' => $preset_options,
      '#default_value' => $this->options['results_preset'],
      '#description' => $this->t('The length of time raw query results should be cached.'),
      '#states' => $preset_states,
    ];

    $form['output_preset'] = [
      '#type' => 'select',
      '#title' => $this->t('Rendered output'),
      '#options' => $preset_options,
      '#default_value' => $this->options['output_preset'],
      '#description' => $this->t('The length of time rendered HTML output should be cached.'),
      '#states' => $preset_states,
    ];

    $form['cron_expression'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cron expression'),
      '#default_value' => $this->options['cron_expression'],
      '#description' => $this->t('5-field cron expression (min hr dom month dow). E.g. <code>0 * * * *</code> (hourly).'),
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
          $this->t('Invalid cron expression. Use 5-field format, e.g. <code>0 * * * *</code>.')
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
    $results = (int) $this->options['results_preset'];
    $output  = (int) $this->options['output_preset'];
    if ($results === $output) {
      if ($results === 0) {
        return $this->t('Forever');
      }
      $interval = $this->dateFormatter->formatInterval($results, 1);
      return $this->t('Every @interval', ['@interval' => $interval]);
    }
    $ri = $this->dateFormatter->formatInterval($results, 1);
    $oi = $this->dateFormatter->formatInterval($output, 1);
    $results_label = $results === 0 ? $this->t('Forever') : $ri;
    $output_label = $output === 0 ? $this->t('Forever') : $oi;
    return $this->t('@results / @output', [
      '@results' => $results_label,
      '@output'  => $output_label,
    ]);
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
    $lifespan = $this->getPresetLifespan($type);
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
    $lifespan = $this->getPresetLifespan($type);
    return $lifespan > 0 ? $lifespan : Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultCacheMaxAge() {
    return (int) $this->cacheSetMaxAge('output');
  }

  /**
   * Returns the preset lifespan in seconds for the given cache type.
   *
   * @param string $type
   *   Either 'results' for query results or 'output' for rendered output.
   *
   * @return int
   *   Lifespan in seconds; 0 means forever.
   */
  private function getPresetLifespan(string $type): int {
    if ($type === 'output') {
      return (int) $this->options['output_preset'];
    }
    return (int) $this->options['results_preset'];
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
      $now = $this->getSiteNow();
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
      $now = $this->getSiteNow();
      $expr = new CronExpression($this->options['cron_expression']);
      $next = $expr->getNextRunDate($now);
      return max(1, $next->getTimestamp() - $this->time->getRequestTime());
    }
    catch (\Throwable) {
      return Cache::PERMANENT;
    }
  }

  /**
   * Returns a DateTime for the current request in the configured site timezone.
   *
   * @return \DateTime
   *   Current time with the site timezone applied.
   */
  private function getSiteNow(): \DateTime {
    $tzName = $this->configFactory
      ->get('system.date')
      ->get('timezone.default') ?? 'UTC';
    return (new \DateTime('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone($tzName));
  }

}

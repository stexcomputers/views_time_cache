<?php

namespace Drupal\views_time_cache\Plugin\views\cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsCache;
use Drupal\views\Plugin\views\cache\CachePluginBase;
use Drupal\views_time_cache\CronExpression;
use Drupal\views_time_cache\TimeCacheCalculator;
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
   * @param \Drupal\views_time_cache\TimeCacheCalculator $calculator
   *   Cache time calculator service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected TimeCacheCalculator $calculator,
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
      $container->get('views_time_cache.calculator'),
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

    $preset_states = [
      'visible' => [
        ':input[name="cache_options[cache_mode]"]' => ['value' => 'preset'],
      ],
    ];

    $form['results_preset'] = [
      '#type' => 'select',
      '#title' => $this->t('Query results'),
      '#options' => $this->calculator->getPresetOptions(),
      '#default_value' => $this->options['results_preset'],
      '#description' => $this->t(
        'The length of time raw query results should be cached.'
      ),
      '#states' => $preset_states,
    ];

    $form['output_preset'] = [
      '#type' => 'select',
      '#title' => $this->t('Rendered output'),
      '#options' => $this->calculator->getPresetOptions(),
      '#default_value' => $this->options['output_preset'],
      '#description' => $this->t(
        'The length of time rendered HTML output should be cached.'
      ),
      '#states' => $preset_states,
    ];

    $form['cron_expression'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cron expression'),
      '#default_value' => $this->options['cron_expression'],
      '#description' => $this->t(
        '5-field cron expression (min hr dom month dow). E.g. <code>0 * * * *</code> (hourly).'
      ),
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
        $form_state->setError(
          $form['cron_expression'],
          $this->t('A cron expression is required.')
        );
      }
      elseif (!CronExpression::isValid($expr)) {
        $form_state->setError(
          $form['cron_expression'],
          $this->t(
            'Invalid cron expression. Use 5-field format, e.g. <code>0 * * * *</code>.'
          )
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    if ($this->options['cache_mode'] === 'cron') {
      return $this->t(
        'Cron: @expr',
        ['@expr' => $this->options['cron_expression']]
      );
    }
    $results = (int) $this->options['results_preset'];
    $output  = (int) $this->options['output_preset'];
    if ($results === $output) {
      if ($results === 0) {
        return $this->t('Forever');
      }
      if ($results < 0) {
        return $this->t('Never');
      }
      return $this->t(
        'Every @interval',
        ['@interval' => $this->dateFormatter->formatInterval($results, 1)]
      );
    }
    $ri = $this->dateFormatter->formatInterval($results, 1);
    $oi = $this->dateFormatter->formatInterval($output, 1);
    $results_label = match(TRUE) {
      $results === 0 => $this->t('Forever'),
      $results < 0   => $this->t('Never'),
      default        => $ri,
    };
    $output_label = match(TRUE) {
      $output === 0 => $this->t('Forever'),
      $output < 0   => $this->t('Never'),
      default       => $oi,
    };
    return $this->t(
      '@results / @output',
      ['@results' => $results_label, '@output' => $output_label]
    );
  }

  /**
   * {@inheritdoc}
   *
   * Returns a Unix timestamp; cache entries created before this are stale.
   * In cron mode this is the most recent matching cron boundary.
   */
  protected function cacheExpire($type) {
    if ($this->options['cache_mode'] === 'cron') {
      return $this->calculator->cutoffForCron(
        $this->options['cron_expression']
      );
    }
    $lifespan = $this->getPresetLifespan($type);
    if ($lifespan === 0) {
      // Forever: no time-based expiry; rely on cache tag invalidation.
      return FALSE;
    }
    if ($lifespan < 0) {
      // Never: every stored entry is immediately stale.
      return $this->time->getRequestTime();
    }
    return $this->time->getRequestTime() - $lifespan;
  }

  /**
   * {@inheritdoc}
   */
  protected function cacheSetMaxAge($type) {
    if ($this->options['cache_mode'] === 'cron') {
      return $this->calculator->maxAgeForCron(
        $this->options['cron_expression']
      );
    }
    return $this->calculator->maxAgeForPreset(
      $this->getPresetLifespan($type)
    );
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

}

<?php
$root = 'C:/Users/jeremy/Documents/Projects/drupal-test-site';
$mod  = 'C:/Users/jeremy/Documents/Projects/ViewsTimeCache';
require $root . '/vendor/autoload.php';
$container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
$container->set('cache_contexts_manager', new class {
  public function assertValidTokens($contexts) { return TRUE; }
});
\Drupal::setContainer($container);
spl_autoload_register(static function (string $class) use ($root, $mod): void {
  $map = [
    'Drupal\views_time_cache\' => $mod . '/src/',
    'Drupal\Tests\views_time_cache\' => $mod . '/tests/src/',
    'Drupal\Tests\' => $root . '/web/core/tests/Drupal/Tests/',
    'Drupal\TestTools\' => $root . '/web/core/tests/Drupal/TestTools/',
  ];
  foreach ($map as $prefix => $dir) {
    if (str_starts_with($class, $prefix)) {
      $rel = str_replace('\', '/', substr($class, strlen($prefix)));
      $file = $dir . $rel . '.php';
      if (is_file($file)) { require $file; }
      return;
    }
  }
  if (preg_match('#^Drupal\\([a-z0-9_]+)\\(.+)$#', $class, $m)) {
    $rel = str_replace('\', '/', $m[2]) . '.php';
    foreach (["/web/core/modules/{$m[1]}/src/", "/web/core/profiles/{$m[1]}/src/"] as $base) {
      $file = $root . $base . $rel;
      if (is_file($file)) { require $file; return; }
    }
  }
});

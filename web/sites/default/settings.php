<?php

// phpcs:ignoreFile

$databases['default']['default'] = [
  'database' => getenv('DRUPAL_DB_NAME'),
  'username' => getenv('DRUPAL_DB_USER'),
  'password' => getenv('DRUPAL_DB_PASS'),
  'host' => getenv('DRUPAL_DB_HOST'),
  'port' => getenv('DRUPAL_DB_PORT'),
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT');
$settings['update_free_access'] = FALSE;
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';

$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^server$',
  '^' . getenv('DRUPAL_BASE_URL') . '$',
  '^.*\.'. getenv('DRUPAL_BASE_URL') . '$',
];

$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];

$settings["config_sync_directory"] = '../config/sync';


$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['migrate_node_migrate_type_classic'] = FALSE;

// API keys — override in settings.local.php or via environment variables.
// Without a token every Hardcover lookup is a silent no-op.
$settings['hardcover_api_token'] = getenv('HARDCOVER_API_TOKEN') ?: '';

// Automatically generated include for settings managed by ddev.
$ddev_settings = dirname(__FILE__) . '/settings.ddev.php';
if (getenv('IS_DDEV_PROJECT') == 'true' && is_readable($ddev_settings)) {
  require $ddev_settings;
}

$local_settings = dirname(__FILE__) . '/settings.local.php';
if (is_readable($local_settings)) {
  require $local_settings;
}

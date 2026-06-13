<?php
declare(strict_types=1);

$siteSettingsPath = __DIR__ . '/includes/site_settings.php';
if (is_file($siteSettingsPath)) {
    require_once $siteSettingsPath;
}

if (function_exists('site_settings_start_clean_url_buffer')) {
    site_settings_start_clean_url_buffer();
}

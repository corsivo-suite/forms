<?php

declare(strict_types=1);

$pluginDirectory = dirname(__DIR__);
$package = json_decode((string) file_get_contents($pluginDirectory . '/admin-app/package.json'), true);
$lock = json_decode((string) file_get_contents($pluginDirectory . '/admin-app/package-lock.json'), true);
$settings = (string) file_get_contents($pluginDirectory . '/includes/settings.php');
$activity = (string) file_get_contents($pluginDirectory . '/includes/activity.php');
$entry = (string) file_get_contents($pluginDirectory . '/admin-app/src/index.js');
$asset = require $pluginDirectory . '/admin-app/build/index.asset.php';
$failures = [];

$check = static function ($condition, $message) use (&$failures): void {
	if (!$condition) {
		$failures[] = $message;
	}
};

$check(($package['dependencies']['@corsivo-suite/wp-admin-dataviews'] ?? '') === '0.1.0', 'DataViews non è bloccato alla 0.1.0.');
$check(($package['dependencies']['@corsivo-suite/wp-admin-ui'] ?? '') === '0.1.0', 'WP Admin UI non è bloccato alla 0.1.0.');
$check(($package['devDependencies']['react'] ?? '') === '18.3.1', 'React non è bloccato alla 18.3.1 tra le sole dipendenze di sviluppo.');
$check(($package['devDependencies']['react-dom'] ?? '') === '18.3.1', 'React DOM non è bloccato alla 18.3.1 tra le sole dipendenze di sviluppo.');
$check(isset($lock['packages']['node_modules/@corsivo-suite/wp-admin-dataviews']), 'Il lockfile non contiene DataViews.');
$check(str_contains($settings, "register_rest_route('corsivo/v1/forms', '/activity'"), 'La collection REST attività non è registrata.');
$check(str_contains($settings, "current_user_can('manage_options')"), 'La collection non mantiene manage_options.');
$check(str_contains($activity, "check_admin_referer('corsivo_forms_clear_activity')"), 'La pulizia non mantiene il nonce admin-post.');
$check(str_contains($entry, 'corsivo_forms_clear_activity'), 'L’app non invia la pulizia all’handler PHP.');
$check(in_array('wp-components', $asset['dependencies'] ?? [], true), 'Il bundle non dichiara wp-components.');

foreach (['index.js', 'index.css', 'index-rtl.css', 'style-index.css', 'style-index-rtl.css'] as $buildFile) {
	$check(is_file($pluginDirectory . '/admin-app/build/' . $buildFile), 'Asset mancante: ' . $buildFile);
}

if ($failures) {
	fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
	exit(1);
}

echo 'Corsivo Forms DataViews static checks passed.' . PHP_EOL;

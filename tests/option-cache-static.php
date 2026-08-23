<?php

$plugin_directory = dirname(__DIR__);
$storage_source = file_get_contents($plugin_directory . '/includes/activity-storage.php');
$settings_source = file_get_contents($plugin_directory . '/includes/settings.php');
if (!is_string($storage_source) || !is_string($settings_source)) {
	fwrite(STDERR, 'Impossibile leggere il runtime Forms.' . PHP_EOL);
	exit(1);
}

function corsivo_forms_option_cache_function_source($source, $function_name) {
	$pattern = '/function\s+' . preg_quote($function_name, '/') . '\s*\([^)]*\)\s*\{.*?(?=\nfunction\s+|\z)/s';
	return preg_match($pattern, $source, $matches) ? $matches[0] : '';
}

$failures = [];
$boot = corsivo_forms_option_cache_function_source($storage_source, 'corsivo_forms_activity_verify_boot');
$required = [
	"function_exists('wp_prime_option_caches')",
	'wp_prime_option_caches([',
	'CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION',
	'CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION',
	'CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION',
	"'corsivo_forms_settings'",
];
foreach ($required as $needle) {
	if ($boot === '' || !str_contains($boot, $needle)) {
		$failures[] = 'Contratto cache option mancante: ' . $needle;
	}
}
$prime = strpos($boot, 'wp_prime_option_caches(');
$readiness = strpos($boot, 'corsivo_forms_activity_schema_verification_due()');
if ($prime === false || $readiness === false || $prime >= $readiness) {
	$failures[] = 'Il prime delle option non precede il gate storage.';
}

$install = corsivo_forms_option_cache_function_source($settings_source, 'corsivo_forms_install_settings');
if ($install === '') {
	$failures[] = 'corsivo_forms_install_settings non trovata.';
} elseif (!str_contains($install, "add_option('corsivo_forms_settings', corsivo_forms_settings_defaults(), '', true)")) {
	$failures[] = 'La fresh install non crea le impostazioni in autoload.';
}

if ($failures) {
	fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
	exit(1);
}

echo 'Corsivo Forms option cache static checks passed.' . PHP_EOL;

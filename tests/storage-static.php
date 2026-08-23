<?php

$plugin_directory = dirname(__DIR__);
$runtime_files = [
	$plugin_directory . '/corsivo-forms.php',
	$plugin_directory . '/includes/activity-storage.php',
	$plugin_directory . '/includes/activity.php',
	$plugin_directory . '/uninstall.php',
];
$sources = [];

foreach ($runtime_files as $runtime_file) {
	$source = file_get_contents($runtime_file);
	if (!is_string($source)) {
		fwrite(STDERR, 'Impossibile leggere ' . $runtime_file . PHP_EOL);
		exit(1);
	}
	$sources[$runtime_file] = $source;
}

$combined = implode("\n", $sources);
$storage_source = $sources[$plugin_directory . '/includes/activity-storage.php'];
$forbidden = [
	'/\bdbDelta\s*\(/i',
	'/\bALTER\s+TABLE\b/i',
	'/ACTIVITY_DB_VERSION/',
	'/corsivo_forms_activity_db_version/',
	'/wp-admin\/includes\/upgrade\.php/',
	'/add_filter\s*\(\s*[\'"]option_/i',
];
$failures = [];

foreach ($forbidden as $pattern) {
	if (preg_match($pattern, $combined)) {
		$failures[] = 'Pattern vietato: ' . $pattern;
	}
}

$storage_forbidden = [
	'/\bhash_hmac\s*\(/i',
	'/\bwp_salt\s*\(/i',
	'/[\'\"]signature[\'\"]\s*=>/i',
	'/corsivo_forms_activity_schema_(?:seal|unseal)\b/',
	'/marker_is_opaque/',
	'/\bDROP\s+TABLE\b/i',
];

foreach ($storage_forbidden as $pattern) {
	if (preg_match($pattern, $storage_source)) {
		$failures[] = 'Firma storage residua: ' . $pattern;
	}
}

$required = [
	'corsivo_forms_activity_schema_fresh_intent',
	'corsivo_forms_activity_schema_verified_payload',
	'corsivo_forms_activity_schema_option_record',
	'corsivo_forms_activity_schema_marker_is_reattestable',
	'corsivo_forms_activity_schema_failure_payload',
	'corsivo_forms_activity_schema_physical_fingerprint',
	'corsivo_forms_activity_schema_source_fingerprint',
	'corsivo_forms_activity_schema_inventory_is_current',
	'corsivo_forms_activity_schema_expected_descriptor',
	'corsivo_forms_activity_schema_create_is_current',
	'corsivo_forms_activity_schema_verification_due',
	'corsivo_forms_activity_schema_future_format_error',
	'corsivo_forms_activity_schema_verify_and_self_heal',
	'SELECT GET_LOCK',
	'SELECT RELEASE_LOCK',
	'corsivo_forms_activity_schema_incompatible',
	'corsivo_forms_activity_schema_fresh_state_conflict',
	'corsivo_forms_activity_schema_intent_fence_lost',
	'corsivo_forms_activity_schema_finalize_fence_lost',
	'CORSIVO_FORMS_ACTIVITY_SCHEMA_MARKER_FORMAT',
	"'marker_format'",
	"'intent_format'",
	"'failure_format'",
	"'operation_id'",
	"'token'",
	'add_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION',
	'wp_schedule_single_event',
	'corsivo_forms_activity_schema_schedule_verification(1)',
	"add_action('admin_init', 'corsivo_forms_activity_schema_verify_admin'",
	"add_action('upgrader_process_complete', 'corsivo_forms_activity_schema_verify_after_update'",
	'Update URI: https://www.corsivo.dev/plugins/corsivo-forms',
];

foreach ($required as $needle) {
	if (!str_contains($combined, $needle)) {
		$failures[] = 'Contratto mancante: ' . $needle;
	}
}

function corsivo_forms_storage_static_function_source($source, $function_name) {
	$pattern = '/function\s+' . preg_quote($function_name, '/') . '\s*\([^)]*\)\s*\{.*?(?=\nfunction\s+|\z)/s';

	return preg_match($pattern, $source, $matches) ? $matches[0] : '';
}

foreach ([
	'corsivo_forms_activity_schema_verified_payload',
	'corsivo_forms_activity_schema_is_ready',
	'corsivo_forms_activity_verify_boot',
	'corsivo_forms_activity_schema_verify_after_update',
] as $fast_function) {
	$function_source = corsivo_forms_storage_static_function_source($storage_source, $fast_function);
	if ($function_source === '') {
		$failures[] = 'Fast gate non trovato: ' . $fast_function;
		continue;
	}

	foreach ([
		'activity_schema_describe',
		'activity_schema_inventory',
		'information_schema',
		'SHOW FULL COLUMNS',
		'SHOW INDEX',
		'SELECT GET_LOCK',
		'activity_schema_verify_and_self_heal',
	] as $physical_needle) {
		if (str_contains($function_source, $physical_needle)) {
			$failures[] = 'Verifica fisica nel fast gate ' . $fast_function . ': ' . $physical_needle;
		}
	}
}

$lock_source = corsivo_forms_storage_static_function_source(
	$storage_source,
	'corsivo_forms_activity_schema_with_lock'
);
$failure_write_position = strpos($lock_source, 'corsivo_forms_activity_schema_record_failure($result)');
$release_position = strpos($lock_source, 'SELECT RELEASE_LOCK');
if ($failure_write_position === false
	|| $release_position === false
	|| $failure_write_position >= $release_position) {
	$failures[] = 'La failure storage non viene persistita prima del rilascio del named lock.';
} elseif (str_contains(substr($lock_source, $release_position), 'corsivo_forms_activity_schema_record_failure(')) {
	$failures[] = 'Il percorso post-release può ancora persistere una failure storage.';
}

$intent_source = corsivo_forms_storage_static_function_source(
	$storage_source,
	'corsivo_forms_activity_schema_ensure_fresh_intent'
);
if (str_contains($intent_source, 'corsivo_forms_activity_schema_delete_option(')) {
	$failures[] = 'Il fresh-intent elimina ancora un artefatto preesistente non verificabile.';
}

$finalize_source = corsivo_forms_storage_static_function_source(
	$storage_source,
	'corsivo_forms_activity_schema_finalize'
);
foreach ([
	'CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION',
	'CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION',
	'CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION',
] as $fenced_option) {
	if (!str_contains($finalize_source, $fenced_option)) {
		$failures[] = 'La finalizzazione non verifica il record atteso: ' . $fenced_option;
	}
}

$update_source = corsivo_forms_storage_static_function_source(
	$storage_source,
	'corsivo_forms_activity_schema_verify_after_update'
);
foreach ([
	'corsivo_forms_activity_schema_future_format_error()',
	'corsivo_forms_activity_schema_delete_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION)',
] as $update_contract) {
	if (!str_contains($update_source, $update_contract)) {
		$failures[] = 'Il fencing post-update non contiene: ' . $update_contract;
	}
}

if ($failures) {
	fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
	exit(1);
}

echo 'Corsivo Forms current-only storage static checks passed.' . PHP_EOL;

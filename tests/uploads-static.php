<?php

$plugin_directory = dirname(__DIR__);
$uploads_file = $plugin_directory . '/includes/uploads.php';
$source = file_get_contents($uploads_file);
if (!is_string($source)) {
	fwrite(STDERR, 'Impossibile leggere ' . $uploads_file . PHP_EOL);
	exit(1);
}

$failures = [];
$forbidden = [
	'/\bwp_salt\s*\(/i' => 'uploads.php dipende dai salt WordPress',
	'/\bhash_hmac\s*\(/i' => 'uploads.php contiene HMAC',
	'/\bopenssl_(?:encrypt|decrypt)\s*\(/i' => 'uploads.php contiene cifratura',
	'/[\'"]signature[\'"]\s*=>/i' => 'uploads.php persiste una firma',
	'/\bwp_insert_attachment\s*\(/i' => 'uploads.php crea attachment WordPress',
	'/\bmedia_handle_upload\s*\(/i' => 'uploads.php usa la Media Library',
	'/\bwp_handle_upload\s*\(/i' => 'uploads.php usa lo storage upload pubblico',
	'/\bwp_generate_attachment_metadata\s*\(/i' => 'uploads.php genera metadata attachment',
	'/[\'"]post_type[\'"]\s*=>\s*[\'"]attachment[\'"]/i' => 'uploads.php registra post attachment',
	'/admin_post_nopriv_corsivo_forms_download_upload/i' => 'il download espone un endpoint pubblico',
];

foreach ($forbidden as $pattern => $message) {
	if (preg_match($pattern, $source)) {
		$failures[] = $message;
	}
}

$required = [
	'corsivo_forms_upload_type_registry',
	'corsivo_forms_upload_storage_is_safe_root',
	'corsivo_forms_upload_web_roots',
	'corsivo_forms_upload_default_storage_root',
	'dirname($outermost_web_root)',
	'corsivo_forms_upload_path_is_within($root, $web_root, true)',
	"add_action('admin_post_corsivo_forms_download_upload'",
	"current_user_can('manage_options')",
	'wp_nonce_url(',
	'wp_verify_nonce(',
	'@chmod($destination, 0640)',
	'corsivo_forms_privacy_export_uploads',
	'corsivo_forms_delete_submission_uploads',
];

foreach ($required as $needle) {
	if (!str_contains($source, $needle)) {
		$failures[] = 'Contratto upload mancante: ' . $needle;
	}
}

function corsivo_forms_upload_static_function_source($source, $function_name) {
	$pattern = '/function\s+' . preg_quote($function_name, '/') . '\s*\([^)]*\)\s*\{.*?(?=\nfunction\s+|\z)/s';
	return preg_match($pattern, $source, $matches) ? $matches[0] : '';
}

$storage_source = corsivo_forms_upload_static_function_source($source, 'corsivo_forms_ensure_upload_storage');
foreach ([
	'corsivo_forms_upload_storage_is_safe_root($root)',
	'corsivo_forms_upload_marker_matches(',
	'wp_mkdir_p($root)',
] as $storage_contract) {
	if ($storage_source === '' || !str_contains($storage_source, $storage_contract)) {
		$failures[] = 'Storage privato non contiene: ' . $storage_contract;
	}
}

$authorization_source = corsivo_forms_upload_static_function_source($source, 'corsivo_forms_authorize_submission_upload_download');
foreach ([
	"current_user_can('manage_options')",
	'wp_verify_nonce(',
	'corsivo_forms_resolve_submission_upload(',
] as $authorization_contract) {
	if ($authorization_source === '' || !str_contains($authorization_source, $authorization_contract)) {
		$failures[] = 'Autorizzazione download non contiene: ' . $authorization_contract;
	}
}

$store_source = corsivo_forms_upload_static_function_source($source, 'corsivo_forms_store_submission_uploads');
foreach ([
	'corsivo_forms_upload_path_is_within($destination, $directory)',
	'@chmod($destination, 0640)',
	'hash_file(',
	'add_post_meta(',
] as $store_contract) {
	if ($store_source === '' || !str_contains($store_source, $store_contract)) {
		$failures[] = 'Persistenza upload non contiene: ' . $store_contract;
	}
}

if ($failures) {
	fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
	exit(1);
}

echo 'Corsivo Forms private uploads static checks passed.' . PHP_EOL;

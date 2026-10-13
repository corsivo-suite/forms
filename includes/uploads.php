<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('CORSIVO_FORMS_UPLOAD_MAX_FILES')) {
	define('CORSIVO_FORMS_UPLOAD_MAX_FILES', 10);
}
if (!defined('CORSIVO_FORMS_UPLOAD_MAX_FILE_BYTES')) {
	define('CORSIVO_FORMS_UPLOAD_MAX_FILE_BYTES', 20 * MB_IN_BYTES);
}
if (!defined('CORSIVO_FORMS_UPLOAD_MAX_REQUEST_BYTES')) {
	define('CORSIVO_FORMS_UPLOAD_MAX_REQUEST_BYTES', 25 * MB_IN_BYTES);
}

add_action('admin_post_corsivo_forms_download_upload', 'corsivo_forms_download_submission_upload');
add_filter('pre_delete_post', 'corsivo_forms_guard_submission_upload_deletion', PHP_INT_MAX, 3);

function corsivo_forms_upload_type_registry() {
	return [
		'pdf'  => ['application/pdf'],
		'jpg'  => ['image/jpeg', 'image/pjpeg'],
		'jpeg' => ['image/jpeg', 'image/pjpeg'],
		'png'  => ['image/png'],
		'doc'  => ['application/msword', 'application/cdfv2', 'application/x-ole-storage'],
		'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
		'xls'  => ['application/vnd.ms-excel', 'application/cdfv2', 'application/x-ole-storage'],
		'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
		'csv'  => ['text/csv', 'text/plain', 'application/csv', 'text/comma-separated-values', 'application/vnd.ms-excel'],
		'txt'  => ['text/plain'],
		'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip'],
		'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
	];
}

function corsivo_forms_normalize_upload_extensions($extensions) {
	if (is_string($extensions)) {
		$extensions = preg_split('/[\s,;]+/', $extensions, -1, PREG_SPLIT_NO_EMPTY);
	}
	if (!is_array($extensions)) {
		return [];
	}

	$registry = corsivo_forms_upload_type_registry();
	$normalized = [];
	foreach ($extensions as $extension) {
		if (!is_scalar($extension)) {
			continue;
		}
		$extension = strtolower(ltrim(trim((string) $extension), '.'));
		if (isset($registry[$extension])) {
			$normalized[$extension] = true;
		}
	}
	return array_keys($normalized);
}

function corsivo_forms_upload_wp_mime_registry() {
	$mimes = [];
	foreach (corsivo_forms_upload_type_registry() as $extension => $types) {
		$mimes[$extension] = (string) reset($types);
	}
	return $mimes;
}

function corsivo_forms_upload_field_name($name) {
	$name = preg_replace('/(?:\[\])+$/', '', trim((string) $name));
	return sanitize_key($name);
}

function corsivo_forms_upload_original_name($name) {
	$name = str_replace('\\', '/', (string) $name);
	$name = sanitize_file_name(wp_basename($name));
	$extension = pathinfo($name, PATHINFO_EXTENSION);
	$suffix = $extension !== '' ? '.' . $extension : '';
	$basename = $suffix !== '' ? substr($name, 0, -strlen($suffix)) : $name;
	$limit = max(1, 180 - strlen($suffix));
	return (function_exists('mb_strcut') ? mb_strcut($basename, 0, $limit, 'UTF-8') : substr($basename, 0, $limit)) . $suffix;
}

function corsivo_forms_upload_array_value_at_path($value, array $path, &$found) {
	$found = true;
	foreach ($path as $key) {
		if (!is_array($value) || !array_key_exists($key, $value)) {
			$found = false;
			return null;
		}
		$value = $value[$key];
	}
	return $value;
}

function corsivo_forms_upload_collect_leaf_paths($value, array $path = []) {
	if (!is_array($value)) {
		return [$path];
	}
	$paths = [];
	foreach ($value as $key => $child) {
		$paths = array_merge($paths, corsivo_forms_upload_collect_leaf_paths($child, array_merge($path, [$key])));
	}
	return $paths;
}

function corsivo_forms_flatten_upload_entry($entry, $field_name) {
	if (!is_array($entry)) {
		return new WP_Error('corsivo_forms_upload_shape_invalid', __('La struttura del file caricato non è valida.', 'corsivo-forms'), ['status' => 400]);
	}

	$required_keys = ['name', 'tmp_name', 'error', 'size'];
	$has_file_shape = count(array_intersect($required_keys, array_keys($entry))) > 0;
	if (!$has_file_shape) {
		$files = [];
		foreach ($entry as $child) {
			$flattened = corsivo_forms_flatten_upload_entry($child, $field_name);
			if (is_wp_error($flattened)) {
				return $flattened;
			}
			$files = array_merge($files, $flattened);
		}
		return $files;
	}

	foreach ($required_keys as $required_key) {
		if (!array_key_exists($required_key, $entry)) {
			return new WP_Error('corsivo_forms_upload_shape_invalid', __('La struttura del file caricato non è completa.', 'corsivo-forms'), ['status' => 400]);
		}
	}

	$paths = corsivo_forms_upload_collect_leaf_paths($entry['name']);
	$files = [];
	foreach ($paths as $path) {
		$values = [];
		foreach ($required_keys as $required_key) {
			$found = false;
			$values[$required_key] = corsivo_forms_upload_array_value_at_path($entry[$required_key], $path, $found);
			if (!$found || is_array($values[$required_key])) {
				return new WP_Error('corsivo_forms_upload_shape_invalid', __('La struttura dei file caricati non è coerente.', 'corsivo-forms'), ['status' => 400]);
			}
		}
		$client_type = '';
		if (array_key_exists('type', $entry)) {
			$found = false;
			$client_type = corsivo_forms_upload_array_value_at_path($entry['type'], $path, $found);
			if (!$found || is_array($client_type)) {
				return new WP_Error('corsivo_forms_upload_shape_invalid', __('La struttura dei file caricati non è coerente.', 'corsivo-forms'), ['status' => 400]);
			}
		}
		$files[] = [
			'field'       => $field_name,
			'original_name' => corsivo_forms_upload_original_name($values['name']),
			'client_type' => is_scalar($client_type) ? trim((string) $client_type) : '',
			'tmp_name'    => is_scalar($values['tmp_name']) ? (string) $values['tmp_name'] : '',
			'error'       => is_numeric($values['error']) ? (int) $values['error'] : -1,
			'size'        => is_numeric($values['size']) ? (int) $values['size'] : -1,
		];
	}
	return $files;
}

function corsivo_forms_extract_request_files(WP_REST_Request $request) {
	$file_params = $request->get_file_params();
	if (!is_array($file_params)) {
		return new WP_Error('corsivo_forms_upload_shape_invalid', __('I file caricati non sono leggibili.', 'corsivo-forms'), ['status' => 400]);
	}

	$files = [];
	foreach ($file_params as $raw_field_name => $entry) {
		$field_name = corsivo_forms_upload_field_name($raw_field_name);
		if ($field_name === '') {
			return new WP_Error('corsivo_forms_upload_field_invalid', __('Il nome del campo file non è valido.', 'corsivo-forms'), ['status' => 400]);
		}
		$flattened = corsivo_forms_flatten_upload_entry($entry, $field_name);
		if (is_wp_error($flattened)) {
			return $flattened;
		}
		$files = array_merge($files, $flattened);
	}
	return $files;
}

function corsivo_forms_request_upload_identity(WP_REST_Request $request) {
	$files = corsivo_forms_extract_request_files($request);
	if (is_wp_error($files)) {
		return $files;
	}
	$files = array_values(array_filter($files, static function ($file) {
		return (int) $file['error'] !== UPLOAD_ERR_NO_FILE || $file['original_name'] !== '';
	}));
	if (count($files) > CORSIVO_FORMS_UPLOAD_MAX_FILES) {
		return new WP_Error('corsivo_forms_upload_count_exceeded', __('Sono stati caricati troppi file.', 'corsivo-forms'), ['status' => 413]);
	}

	$total_bytes = 0;
	$identity = [];
	foreach ($files as $file) {
		$error = (int) $file['error'];
		$size = max(0, (int) $file['size']);
		$sha256 = '';
		if ($error === UPLOAD_ERR_OK) {
			if ($file['tmp_name'] === '' || !is_file($file['tmp_name']) || is_link($file['tmp_name']) || !is_readable($file['tmp_name'])) {
				return new WP_Error('corsivo_forms_upload_temporary_file_invalid', __('Un file caricato non è disponibile.', 'corsivo-forms'), ['status' => 400]);
			}
			$actual_size = filesize($file['tmp_name']);
			if ($actual_size === false || (int) $actual_size !== $size) {
				return new WP_Error('corsivo_forms_upload_size_mismatch', __('La dimensione di un file caricato non è valida.', 'corsivo-forms'), ['status' => 400]);
			}
			$total_bytes += $size;
			if ($total_bytes > CORSIVO_FORMS_UPLOAD_MAX_REQUEST_BYTES) {
				return new WP_Error('corsivo_forms_upload_request_too_large', __('La dimensione complessiva dei file è troppo grande.', 'corsivo-forms'), ['status' => 413]);
			}
			$sha256 = hash_file('sha256', $file['tmp_name']);
			if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
				return new WP_Error('corsivo_forms_upload_hash_failed', __('Non è stato possibile verificare un file caricato.', 'corsivo-forms'), ['status' => 500]);
			}
		}
		$identity[] = [
			'field'         => $file['field'],
			'original_name' => $file['original_name'],
			'error'         => $error,
			'size'          => $size,
			'sha256'        => $sha256,
		];
	}

	return $identity;
}

function corsivo_forms_unverified_upload_identity($value, $temporary_paths = false) {
	if (is_array($value)) {
		if (!array_is_list($value)) {
			ksort($value, SORT_STRING);
		}
		$identity = [];
		foreach ($value as $key => $child) {
			$identity[$key] = corsivo_forms_unverified_upload_identity($child, $temporary_paths || $key === 'tmp_name');
		}
		return $identity;
	}
	if (!$temporary_paths) {
		return is_scalar($value) || $value === null ? $value : null;
	}
	if (is_string($value) && $value !== '' && is_file($value) && !is_link($value) && is_readable($value)) {
		$hash = hash_file('sha256', $value);
		if (is_string($hash)) {
			return ['sha256' => $hash];
		}
	}
	return ['unavailable' => true];
}

function corsivo_forms_upload_error($code, $message, $field_name, $status = 400) {
	return new WP_Error($code, $message, [
		'status' => (int) $status,
		'field'  => $field_name,
		'errors' => [$field_name => $message],
	]);
}

function corsivo_forms_upload_php_error($error, $field_name) {
	$errors = [
		UPLOAD_ERR_INI_SIZE   => [__('Il file supera il limite consentito dal server.', 'corsivo-forms'), 413],
		UPLOAD_ERR_FORM_SIZE  => [__('Il file supera il limite consentito.', 'corsivo-forms'), 413],
		UPLOAD_ERR_PARTIAL    => [__('Il caricamento del file è incompleto.', 'corsivo-forms'), 400],
		UPLOAD_ERR_NO_TMP_DIR => [__('Il servizio di caricamento non è disponibile.', 'corsivo-forms'), 503],
		UPLOAD_ERR_CANT_WRITE => [__('Il file non può essere salvato.', 'corsivo-forms'), 503],
		UPLOAD_ERR_EXTENSION  => [__('Il caricamento del file è stato bloccato.', 'corsivo-forms'), 400],
	];
	[$message, $status] = $errors[$error] ?? [__('Il caricamento del file non è riuscito.', 'corsivo-forms'), 400];
	return corsivo_forms_upload_error('corsivo_forms_upload_failed', $message, $field_name, $status);
}

function corsivo_forms_detect_upload_mime($path) {
	$mime = '';
	if (function_exists('finfo_open')) {
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		if ($finfo) {
			$detected = finfo_file($finfo, $path);
			finfo_close($finfo);
			$mime = is_string($detected) ? $detected : '';
		}
	}
	if ($mime === '' && function_exists('mime_content_type')) {
		$detected = mime_content_type($path);
		$mime = is_string($detected) ? $detected : '';
	}
	$mime = strtolower(trim(strtok($mime, ';') ?: ''));
	return preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/D', $mime) ? $mime : '';
}

function corsivo_forms_upload_zip_has_entry(ZipArchive $archive, $entry_name) {
	return $archive->locateName($entry_name, ZipArchive::FL_NOCASE) !== false;
}

function corsivo_forms_upload_container_is_valid($path, $extension) {
	if (!in_array($extension, ['docx', 'xlsx', 'odt', 'ods'], true)) {
		return true;
	}
	if (!class_exists('ZipArchive')) {
		return false;
	}

	$archive = new ZipArchive();
	if ($archive->open($path, ZipArchive::RDONLY) !== true) {
		return false;
	}
	try {
		if ($archive->numFiles > 2048) {
			return false;
		}
		for ($index = 0; $index < $archive->numFiles; $index++) {
			$entry = (string) $archive->getNameIndex($index);
			if (preg_match('#(?:^|/)(?:vbaProject\.bin|[^/]+\.(?:exe|dll|com|bat|cmd|ps1|sh|js|html?|svg))$#i', $entry)) {
				return false;
			}
		}
		if ($extension === 'docx') {
			return corsivo_forms_upload_zip_has_entry($archive, '[Content_Types].xml')
				&& corsivo_forms_upload_zip_has_entry($archive, 'word/document.xml');
		}
		if ($extension === 'xlsx') {
			return corsivo_forms_upload_zip_has_entry($archive, '[Content_Types].xml')
				&& corsivo_forms_upload_zip_has_entry($archive, 'xl/workbook.xml');
		}
		$expected_mime = $extension === 'odt'
			? 'application/vnd.oasis.opendocument.text'
			: 'application/vnd.oasis.opendocument.spreadsheet';
		$mimetype = $archive->getFromName('mimetype', 128);
		return is_string($mimetype) && trim($mimetype) === $expected_mime;
	} finally {
		$archive->close();
	}
}

function corsivo_forms_upload_text_is_safe($path, $extension) {
	if (!in_array($extension, ['csv', 'txt'], true)) {
		return true;
	}
	$stream = fopen($path, 'rb');
	if (!$stream) {
		return false;
	}
	$sample = fread($stream, 8192);
	fclose($stream);
	if (!is_string($sample) || str_contains($sample, "\0")) {
		return false;
	}
	return !preg_match('/^\s*(?:#!|<\?php)|<\s*(?:!doctype\s+html|html|body|script|svg|iframe|object|embed|meta)\b/i', $sample);
}

function corsivo_forms_validate_upload_file(array $file, array $field) {
	$field_name = $field['name'];
	if ((int) $file['error'] !== UPLOAD_ERR_OK) {
		return corsivo_forms_upload_php_error((int) $file['error'], $field_name);
	}
	if ($file['tmp_name'] === '' || !is_file($file['tmp_name']) || is_link($file['tmp_name']) || !is_readable($file['tmp_name'])) {
		return corsivo_forms_upload_error('corsivo_forms_upload_temporary_file_invalid', __('Il file caricato non è disponibile.', 'corsivo-forms'), $field_name);
	}

	$actual_size = filesize($file['tmp_name']);
	if ($actual_size === false || (int) $actual_size !== (int) $file['size'] || (int) $actual_size < 1) {
		return corsivo_forms_upload_error('corsivo_forms_upload_size_invalid', __('La dimensione del file non è valida.', 'corsivo-forms'), $field_name);
	}
	if ((int) $actual_size > (int) $field['max_file_bytes']) {
		return corsivo_forms_upload_error('corsivo_forms_upload_file_too_large', __('Il file supera la dimensione massima consentita.', 'corsivo-forms'), $field_name, 413);
	}

	$original_name = corsivo_forms_upload_original_name($file['original_name']);
	$extension = strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION));
	if ($original_name === '' || $extension === '' || !in_array($extension, $field['allowed_extensions'], true)) {
		return corsivo_forms_upload_error('corsivo_forms_upload_extension_invalid', __('Il formato del file non è consentito.', 'corsivo-forms'), $field_name);
	}

	$registry = corsivo_forms_upload_type_registry();
	$checked = wp_check_filetype_and_ext($file['tmp_name'], $original_name, corsivo_forms_upload_wp_mime_registry());
	$checked_extension = strtolower((string) ($checked['ext'] ?? ''));
	$checked_mime = strtolower((string) ($checked['type'] ?? ''));
	if ($checked_extension !== $extension || !in_array($checked_mime, $registry[$extension], true)) {
		return corsivo_forms_upload_error('corsivo_forms_upload_filetype_invalid', __('Il contenuto del file non corrisponde alla sua estensione.', 'corsivo-forms'), $field_name);
	}

	$detected_mime = corsivo_forms_detect_upload_mime($file['tmp_name']);
	if ($detected_mime === '' || !in_array($detected_mime, $registry[$extension], true)) {
		return corsivo_forms_upload_error('corsivo_forms_upload_mime_invalid', __('Il tipo reale del file non è consentito.', 'corsivo-forms'), $field_name);
	}
	if (!corsivo_forms_upload_container_is_valid($file['tmp_name'], $extension)
		|| !corsivo_forms_upload_text_is_safe($file['tmp_name'], $extension)) {
		return corsivo_forms_upload_error('corsivo_forms_upload_content_invalid', __('Il contenuto del file non è valido.', 'corsivo-forms'), $field_name);
	}
	if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
		$image = wp_getimagesize($file['tmp_name']);
		if (!is_array($image) || strtolower((string) ($image['mime'] ?? '')) !== $checked_mime) {
			return corsivo_forms_upload_error('corsivo_forms_upload_image_invalid', __('L’immagine caricata non è valida.', 'corsivo-forms'), $field_name);
		}
	}

	$sha256 = hash_file('sha256', $file['tmp_name']);
	if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
		return corsivo_forms_upload_error('corsivo_forms_upload_hash_failed', __('Non è stato possibile verificare il file.', 'corsivo-forms'), $field_name, 500);
	}

	$upload_id = str_replace('-', '', wp_generate_uuid4());
	return [
		'id'              => $upload_id,
		'field'           => $field_name,
		'original_name'   => $original_name,
		'storage_name'    => $upload_id . '.' . $extension,
		'extension'       => $extension,
		'mime'            => $checked_mime,
		'size'            => (int) $actual_size,
		'sha256'          => $sha256,
		'uploaded_at_gmt' => current_time('mysql', true),
		'tmp_name'        => $file['tmp_name'],
	];
}

function corsivo_forms_upload_field_contract(array $field) {
	$values = is_array($field['values'] ?? null) ? $field['values'] : [];
	$name = corsivo_forms_upload_field_name($field['name'] ?? '');
	$multiple = !empty($values['multiple']);
	$max_files = max(1, min(CORSIVO_FORMS_UPLOAD_MAX_FILES, (int) ($values['max_files'] ?? 1)));
	if (!$multiple) {
		$max_files = 1;
	}
	$max_file_size_mb = max(1, min(20, (int) ($values['max_file_size_mb'] ?? 5)));
	$allowed_extensions = corsivo_forms_normalize_upload_extensions($values['allowed_extensions'] ?? ['pdf', 'jpg', 'jpeg', 'png']);
	return [
		'name'               => $name,
		'required'           => !empty($values['required']),
		'multiple'           => $multiple,
		'max_files'          => $max_files,
		'max_file_bytes'     => min(CORSIVO_FORMS_UPLOAD_MAX_FILE_BYTES, $max_file_size_mb * MB_IN_BYTES),
		'allowed_extensions' => $allowed_extensions,
	];
}

function corsivo_forms_upload_public_metadata(array $metadata) {
	return [
		'id'            => $metadata['id'],
		'original_name' => $metadata['original_name'],
		'extension'     => $metadata['extension'],
		'mime'          => $metadata['mime'],
		'size'          => (int) $metadata['size'],
		'sha256'        => $metadata['sha256'],
	];
}

function corsivo_forms_group_upload_values(array $files, array $field_contracts = []) {
	$values = [];
	foreach ($field_contracts as $field_name => $field_contract) {
		$values[$field_name] = [];
	}
	foreach ($files as $file) {
		$values[$file['field']][] = corsivo_forms_upload_public_metadata($file);
	}
	return $values;
}

function corsivo_forms_register_prepared_upload_batch(array $files) {
	$batch_id = str_replace('-', '', wp_generate_uuid4());
	if (!isset($GLOBALS['corsivo_forms_prepared_upload_batches']) || !is_array($GLOBALS['corsivo_forms_prepared_upload_batches'])) {
		$GLOBALS['corsivo_forms_prepared_upload_batches'] = [];
	}
	$GLOBALS['corsivo_forms_prepared_upload_batches'][$batch_id] = [
		'blog_id' => get_current_blog_id(),
		'files'   => $files,
	];
	return $batch_id;
}

function corsivo_forms_prepare_request_uploads(WP_REST_Request $request, array $fields) {
	$field_contracts = [];
	foreach ($fields as $field) {
		if (!is_array($field) || ($field['type'] ?? '') !== 'file') {
			continue;
		}
		$contract = corsivo_forms_upload_field_contract($field);
		if ($contract['name'] === '' || !$contract['allowed_extensions']) {
			return corsivo_forms_upload_error('corsivo_forms_upload_field_config_invalid', __('La configurazione del campo file non è valida.', 'corsivo-forms'), $contract['name'] ?: 'file', 409);
		}
		$field_contracts[$contract['name']] = $contract;
	}

	$files = corsivo_forms_extract_request_files($request);
	if (is_wp_error($files)) {
		$data = $files->get_error_data();
		$data = is_array($data) ? $data : [];
		if (!isset($data['errors'])) {
			$data['errors'] = ['file' => $files->get_error_message()];
		}
		$files->add_data($data);
		return $files;
	}
	$files = array_values(array_filter($files, static function ($file) {
		return (int) $file['error'] !== UPLOAD_ERR_NO_FILE || $file['original_name'] !== '';
	}));
	if (count($files) > CORSIVO_FORMS_UPLOAD_MAX_FILES) {
		return new WP_Error('corsivo_forms_upload_count_exceeded', __('Sono stati caricati troppi file.', 'corsivo-forms'), ['status' => 413, 'errors' => ['file' => __('Sono stati caricati troppi file.', 'corsivo-forms')]]);
	}

	$grouped = [];
	foreach ($files as $file) {
		if (!isset($field_contracts[$file['field']])) {
			return corsivo_forms_upload_error('corsivo_forms_upload_unexpected_field', __('È stato ricevuto un file non previsto dal modulo.', 'corsivo-forms'), $file['field']);
		}
		$grouped[$file['field']][] = $file;
	}

	$total_bytes = 0;
	$validated = [];
	foreach ($field_contracts as $field_name => $contract) {
		$field_files = $grouped[$field_name] ?? [];
		if ($contract['required'] && !$field_files) {
			return corsivo_forms_upload_error('corsivo_forms_upload_required', __('Carica almeno un file.', 'corsivo-forms'), $field_name);
		}
		if (count($field_files) > $contract['max_files']) {
			return corsivo_forms_upload_error('corsivo_forms_upload_field_count_exceeded', __('Sono stati caricati troppi file per questo campo.', 'corsivo-forms'), $field_name, 413);
		}
		foreach ($field_files as $file) {
			$total_bytes += max(0, (int) $file['size']);
			if ($total_bytes > CORSIVO_FORMS_UPLOAD_MAX_REQUEST_BYTES) {
				return corsivo_forms_upload_error('corsivo_forms_upload_request_too_large', __('La dimensione complessiva dei file è troppo grande.', 'corsivo-forms'), $field_name, 413);
			}
			$checked = corsivo_forms_validate_upload_file($file, $contract);
			if (is_wp_error($checked)) {
				return $checked;
			}
			$validated[] = $checked;
		}
	}

	$batch_id = corsivo_forms_register_prepared_upload_batch($validated);
	$prepared_files = array_map(static function ($file) {
		$metadata = $file;
		unset($metadata['tmp_name']);
		return $metadata;
	}, $validated);
	return [
		'values'   => corsivo_forms_group_upload_values($prepared_files, $field_contracts),
		'prepared' => [
			'batch_id' => $batch_id,
			'files'    => $prepared_files,
		],
		'errors' => [],
	];
}
function corsivo_forms_upload_normalize_path($path) {
	return wp_normalize_path(untrailingslashit((string) $path));
}

function corsivo_forms_upload_path_is_within($path, $root, $allow_equal = false) {
	$path = corsivo_forms_upload_normalize_path($path);
	$root = corsivo_forms_upload_normalize_path($root);
	if ($path === '' || $root === '') {
		return false;
	}
	if (preg_match('/^[a-z]:\//i', $path) || preg_match('/^[a-z]:\//i', $root)) {
		$path = strtolower($path);
		$root = strtolower($root);
	}
	if ($allow_equal && $path === $root) {
		return true;
	}
	return str_starts_with($path, trailingslashit($root));
}

function corsivo_forms_upload_web_roots() {
	$roots = [
		ABSPATH,
		defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
		defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '',
		is_scalar($_SERVER['DOCUMENT_ROOT'] ?? null) ? (string) $_SERVER['DOCUMENT_ROOT'] : '',
	];
	$uploads = wp_get_upload_dir();
	$roots[] = is_scalar($uploads['basedir'] ?? null) ? (string) $uploads['basedir'] : '';

	$site_path = (string) wp_parse_url(site_url(), PHP_URL_PATH);
	$site_segments = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $site_path), '/')), 'strlen'));
	$absolute_segments = array_values(array_filter(explode('/', trim(str_replace('\\', '/', corsivo_forms_upload_normalize_path(ABSPATH)), '/')), 'strlen'));
	$matching_segments = 0;
	for ($length = min(count($site_segments), count($absolute_segments)); $length > 0; $length--) {
		$absolute_suffix = array_map('strtolower', array_slice($absolute_segments, -$length));
		$site_prefix = array_map('strtolower', array_slice($site_segments, 0, $length));
		if ($absolute_suffix === $site_prefix) {
			$matching_segments = $length;
			break;
		}
	}
	$inferred = corsivo_forms_upload_normalize_path(ABSPATH);
	for ($index = 0; $index < $matching_segments; $index++) {
		$inferred = corsivo_forms_upload_normalize_path(dirname($inferred));
	}
	$roots[] = $inferred;

	$normalized = [];
	foreach ($roots as $root) {
		if (!is_scalar($root) || trim((string) $root) === '') {
			continue;
		}
		$resolved = realpath((string) $root);
		$root = corsivo_forms_upload_normalize_path($resolved ?: $root);
		if ($root !== '' && $root !== '/' && !preg_match('/^[a-z]:$/i', $root)) {
			$normalized[strtolower($root)] = $root;
		}
	}
	return array_values($normalized);
}

function corsivo_forms_upload_default_storage_root() {
	$web_roots = corsivo_forms_upload_web_roots();
	usort($web_roots, static fn($left, $right) => strlen($left) <=> strlen($right));
	$outermost_web_root = $web_roots[0] ?? corsivo_forms_upload_normalize_path(ABSPATH);
	return corsivo_forms_upload_normalize_path(
		dirname($outermost_web_root)
		. '/corsivo-forms-private/'
		. substr(hash('sha256', corsivo_forms_upload_normalize_path(ABSPATH)), 0, 16)
	);
}

function corsivo_forms_upload_storage_root() {
	$root = defined('CORSIVO_FORMS_PRIVATE_DIR')
		? CORSIVO_FORMS_PRIVATE_DIR
		: corsivo_forms_upload_default_storage_root();
	return corsivo_forms_upload_normalize_path(is_scalar($root) ? (string) $root : '');
}

function corsivo_forms_upload_storage_marker_name() {
	return '.corsivo-forms-storage';
}

function corsivo_forms_upload_storage_marker_contents() {
	return "corsivo-forms:private-upload-storage:v1\n";
}

function corsivo_forms_upload_storage_is_safe_root($root) {
	$root = corsivo_forms_upload_normalize_path($root);
	if ($root === ''
		|| $root === '/'
		|| preg_match('/^[a-z]:\/?$/i', $root)
		|| (!str_starts_with($root, '/') && !preg_match('/^[a-z]:\//i', $root) && !str_starts_with($root, '//'))) {
		return false;
	}
	foreach (corsivo_forms_upload_web_roots() as $web_root) {
		if (corsivo_forms_upload_path_is_within($root, $web_root, true)
			|| corsivo_forms_upload_path_is_within($web_root, $root, true)) {
			return false;
		}
	}
	return true;
}

function corsivo_forms_upload_marker_matches($directory, $marker_name, $expected_contents) {
	$directory = realpath((string) $directory);
	if (!$directory || !is_dir($directory) || is_link($directory)) {
		return false;
	}
	$marker = corsivo_forms_upload_normalize_path($directory . '/' . $marker_name);
	$resolved_marker = realpath($marker);
	if (!$resolved_marker
		|| is_link($marker)
		|| !is_file($resolved_marker)
		|| !corsivo_forms_upload_path_is_within($resolved_marker, $directory)) {
		return false;
	}
	$stream = fopen($resolved_marker, 'rb');
	if (!$stream) {
		return false;
	}
	if (!flock($stream, LOCK_SH)) {
		fclose($stream);
		return false;
	}
	$contents = stream_get_contents($stream);
	flock($stream, LOCK_UN);
	fclose($stream);
	return is_string($contents) && hash_equals($expected_contents, $contents);
}
function corsivo_forms_upload_marker_matches_after_wait($directory, $marker_name, $expected_contents) {
	for ($attempt = 0; $attempt < 5; $attempt++) {
		if (corsivo_forms_upload_marker_matches($directory, $marker_name, $expected_contents)) {
			return true;
		}
		if ($attempt < 4) {
			usleep(5000);
		}
	}
	return false;
}


function corsivo_forms_upload_write_marker($directory, $marker_name, $contents) {
	$entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
	if ($entries) {
		return count($entries) === 1
			&& $entries[0] === $marker_name
			&& corsivo_forms_upload_marker_matches_after_wait($directory, $marker_name, $contents)
			? true
			: new WP_Error('corsivo_forms_upload_directory_unowned', __('La directory privata non appartiene a Corsivo Forms.', 'corsivo-forms'));
	}
	$marker = corsivo_forms_upload_normalize_path($directory . '/' . $marker_name);
	$written = file_put_contents($marker, $contents, LOCK_EX);
	if ($written !== strlen($contents) || !corsivo_forms_upload_marker_matches($directory, $marker_name, $contents)) {
		if (is_file($marker) && !is_link($marker)) {
			wp_delete_file($marker);
		}
		return new WP_Error('corsivo_forms_upload_marker_failed', __('La directory privata non può essere attestata.', 'corsivo-forms'));
	}
	return true;
}

function corsivo_forms_ensure_upload_storage() {
	$root = corsivo_forms_upload_storage_root();
	if (!corsivo_forms_upload_storage_is_safe_root($root)) {
		return new WP_Error('corsivo_forms_upload_storage_unsafe', __('La directory privata configurata non è sicura.', 'corsivo-forms'));
	}

	if (is_dir($root)) {
		$resolved = realpath($root);
		if (!$resolved
			|| is_link($root)
			|| !corsivo_forms_upload_storage_is_safe_root($resolved)) {
			return new WP_Error('corsivo_forms_upload_storage_unowned', __('La directory privata non è attestata.', 'corsivo-forms'));
		}
		if (corsivo_forms_upload_marker_matches($resolved, corsivo_forms_upload_storage_marker_name(), corsivo_forms_upload_storage_marker_contents())) {
			return true;
		}
		$marked = corsivo_forms_upload_write_marker($resolved, corsivo_forms_upload_storage_marker_name(), corsivo_forms_upload_storage_marker_contents());
		return is_wp_error($marked) ? $marked : true;
	}

	if (!wp_mkdir_p($root) && !is_dir($root)) {
		return new WP_Error('corsivo_forms_upload_storage_create_failed', __('La directory privata non può essere creata.', 'corsivo-forms'));
	}
	$resolved = realpath($root);
	if (!$resolved || is_link($root) || !corsivo_forms_upload_storage_is_safe_root($resolved)) {
		return new WP_Error('corsivo_forms_upload_storage_unsafe', __('La directory privata creata non è sicura.', 'corsivo-forms'));
	}
	$marked = corsivo_forms_upload_write_marker($resolved, corsivo_forms_upload_storage_marker_name(), corsivo_forms_upload_storage_marker_contents());
	if (is_wp_error($marked)) {
		@rmdir($resolved);
		return $marked;
	}
	return true;
}

function corsivo_forms_existing_upload_storage_root() {
	$root = corsivo_forms_upload_storage_root();
	if (!is_dir($root)) {
		return null;
	}
	$resolved = realpath($root);
	if (!$resolved
		|| is_link($root)
		|| !corsivo_forms_upload_storage_is_safe_root($resolved)
		|| !corsivo_forms_upload_marker_matches($resolved, corsivo_forms_upload_storage_marker_name(), corsivo_forms_upload_storage_marker_contents())) {
		return new WP_Error('corsivo_forms_upload_storage_unowned', __('La directory privata non è attestata.', 'corsivo-forms'));
	}
	return corsivo_forms_upload_normalize_path($resolved);
}

function corsivo_forms_upload_site_marker_contents($blog_id) {
	return 'corsivo-forms:site:' . max(1, (int) $blog_id) . ":v1\n";
}

function corsivo_forms_upload_submission_marker_contents($blog_id, $submission_id) {
	return 'corsivo-forms:site:' . max(1, (int) $blog_id) . ':submission:' . max(1, (int) $submission_id) . ":v1\n";
}

function corsivo_forms_ensure_owned_upload_directory($path, $parent, $marker_name, $marker_contents) {
	$path = corsivo_forms_upload_normalize_path($path);
	$parent = realpath((string) $parent);
	if (!$parent || !corsivo_forms_upload_path_is_within($path, $parent)) {
		return new WP_Error('corsivo_forms_upload_path_invalid', __('Il percorso privato non è valido.', 'corsivo-forms'));
	}

	$existed = is_dir($path);
	if (!$existed && !wp_mkdir_p($path) && !is_dir($path)) {
		return new WP_Error('corsivo_forms_upload_directory_create_failed', __('La directory del file non può essere creata.', 'corsivo-forms'));
	}
	$resolved = realpath($path);
	if (!$resolved || is_link($path) || !corsivo_forms_upload_path_is_within($resolved, $parent)) {
		return new WP_Error('corsivo_forms_upload_path_invalid', __('Il percorso privato non è valido.', 'corsivo-forms'));
	}
	if ($existed && corsivo_forms_upload_marker_matches($resolved, $marker_name, $marker_contents)) {
		return corsivo_forms_upload_normalize_path($resolved);
	}
	$marked = corsivo_forms_upload_write_marker($resolved, $marker_name, $marker_contents);
	if (is_wp_error($marked)) {
		if (!$existed) {
			@rmdir($resolved);
		}
		return $marked;
	}
	return corsivo_forms_upload_normalize_path($resolved);
}

function corsivo_forms_ensure_submission_upload_directory($submission_id) {
	$submission_id = absint($submission_id);
	if (!$submission_id) {
		return new WP_Error('corsivo_forms_upload_submission_invalid', __('La richiesta associata ai file non è valida.', 'corsivo-forms'));
	}
	$storage = corsivo_forms_ensure_upload_storage();
	if (is_wp_error($storage)) {
		return $storage;
	}
	$root = corsivo_forms_existing_upload_storage_root();
	if (is_wp_error($root) || !$root) {
		return is_wp_error($root) ? $root : new WP_Error('corsivo_forms_upload_storage_missing', __('La directory privata non è disponibile.', 'corsivo-forms'));
	}

	$sites = corsivo_forms_upload_normalize_path($root . '/sites');
	if (!is_dir($sites) && !wp_mkdir_p($sites) && !is_dir($sites)) {
		return new WP_Error('corsivo_forms_upload_directory_create_failed', __('La directory del sito non può essere creata.', 'corsivo-forms'));
	}
	$resolved_sites = realpath($sites);
	if (!$resolved_sites || is_link($sites) || !corsivo_forms_upload_path_is_within($resolved_sites, $root)) {
		return new WP_Error('corsivo_forms_upload_path_invalid', __('Il percorso privato del sito non è valido.', 'corsivo-forms'));
	}

	$blog_id = max(1, get_current_blog_id());
	$site = corsivo_forms_ensure_owned_upload_directory(
		$sites . '/' . $blog_id,
		$resolved_sites,
		'.corsivo-forms-site',
		corsivo_forms_upload_site_marker_contents($blog_id)
	);
	if (is_wp_error($site)) {
		return $site;
	}

	$submissions = corsivo_forms_upload_normalize_path($site . '/submissions');
	if (!is_dir($submissions) && !wp_mkdir_p($submissions) && !is_dir($submissions)) {
		return new WP_Error('corsivo_forms_upload_directory_create_failed', __('La directory delle richieste non può essere creata.', 'corsivo-forms'));
	}
	$resolved_submissions = realpath($submissions);
	if (!$resolved_submissions || is_link($submissions) || !corsivo_forms_upload_path_is_within($resolved_submissions, $site)) {
		return new WP_Error('corsivo_forms_upload_path_invalid', __('Il percorso privato delle richieste non è valido.', 'corsivo-forms'));
	}

	return corsivo_forms_ensure_owned_upload_directory(
		$submissions . '/' . $submission_id,
		$resolved_submissions,
		'.corsivo-forms-submission',
		corsivo_forms_upload_submission_marker_contents($blog_id, $submission_id)
	);
}
function corsivo_forms_upload_meta_key() {
	return '_corsivo_forms_uploads';
}

function corsivo_forms_normalize_stored_upload(array $upload) {
	$required = ['id', 'field', 'original_name', 'storage_name', 'extension', 'mime', 'size', 'sha256', 'uploaded_at_gmt'];
	if (array_keys($upload) !== $required) {
		return new WP_Error('corsivo_forms_upload_metadata_invalid', __('I metadati di un file non sono validi.', 'corsivo-forms'));
	}

	$registry = corsivo_forms_upload_type_registry();
	$id = strtolower((string) $upload['id']);
	$field = corsivo_forms_upload_field_name($upload['field']);
	$original_name = corsivo_forms_upload_original_name($upload['original_name']);
	$storage_name = strtolower((string) $upload['storage_name']);
	$extension = strtolower((string) $upload['extension']);
	$mime = strtolower((string) $upload['mime']);
	$size = (int) $upload['size'];
	$sha256 = strtolower((string) $upload['sha256']);
	$uploaded_at_gmt = (string) $upload['uploaded_at_gmt'];

	if (!preg_match('/^[a-f0-9]{32}$/D', $id)
		|| $field === ''
		|| $original_name === ''
		|| !isset($registry[$extension])
		|| $storage_name !== $id . '.' . $extension
		|| !in_array($mime, $registry[$extension], true)
		|| $size < 1
		|| $size > CORSIVO_FORMS_UPLOAD_MAX_FILE_BYTES
		|| !preg_match('/^[a-f0-9]{64}$/D', $sha256)
		|| !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $uploaded_at_gmt)) {
		return new WP_Error('corsivo_forms_upload_metadata_invalid', __('I metadati di un file non sono validi.', 'corsivo-forms'));
	}

	return [
		'id'              => $id,
		'field'           => $field,
		'original_name'   => $original_name,
		'storage_name'    => $storage_name,
		'extension'       => $extension,
		'mime'            => $mime,
		'size'            => $size,
		'sha256'          => $sha256,
		'uploaded_at_gmt' => $uploaded_at_gmt,
	];
}

function corsivo_forms_submission_uploads($submission_id) {
	$raw = get_post_meta(absint($submission_id), corsivo_forms_upload_meta_key(), true);
	if ($raw === '' || $raw === null) {
		return [];
	}
	if (!is_array($raw) || !array_is_list($raw)) {
		return new WP_Error('corsivo_forms_upload_metadata_invalid', __('I metadati dei file non sono validi.', 'corsivo-forms'));
	}

	$uploads = [];
	$ids = [];
	foreach ($raw as $upload) {
		if (!is_array($upload)) {
			return new WP_Error('corsivo_forms_upload_metadata_invalid', __('I metadati dei file non sono validi.', 'corsivo-forms'));
		}
		$normalized = corsivo_forms_normalize_stored_upload($upload);
		if (is_wp_error($normalized) || isset($ids[$normalized['id']])) {
			return is_wp_error($normalized)
				? $normalized
				: new WP_Error('corsivo_forms_upload_metadata_invalid', __('I metadati dei file non sono validi.', 'corsivo-forms'));
		}
		$ids[$normalized['id']] = true;
		$uploads[] = $normalized;
	}
	return $uploads;
}

function corsivo_forms_existing_submission_upload_directory($submission_id) {
	$root = corsivo_forms_existing_upload_storage_root();
	if (is_wp_error($root) || !$root) {
		return $root;
	}
	$blog_id = max(1, get_current_blog_id());
	$sites = realpath($root . '/sites');
	$site = realpath($root . '/sites/' . $blog_id);
	$submissions = realpath($root . '/sites/' . $blog_id . '/submissions');
	$directory = realpath($root . '/sites/' . $blog_id . '/submissions/' . absint($submission_id));
	if (!$directory) {
		return null;
	}
	if (!$sites
		|| !$site
		|| !$submissions
		|| is_link($site)
		|| is_link($directory)
		|| !corsivo_forms_upload_path_is_within($sites, $root)
		|| !corsivo_forms_upload_path_is_within($site, $sites)
		|| !corsivo_forms_upload_path_is_within($submissions, $site)
		|| !corsivo_forms_upload_path_is_within($directory, $submissions)
		|| !corsivo_forms_upload_marker_matches($site, '.corsivo-forms-site', corsivo_forms_upload_site_marker_contents($blog_id))
		|| !corsivo_forms_upload_marker_matches($directory, '.corsivo-forms-submission', corsivo_forms_upload_submission_marker_contents($blog_id, $submission_id))) {
		return new WP_Error('corsivo_forms_upload_directory_unowned', __('La directory dei file non è attestata.', 'corsivo-forms'));
	}
	return corsivo_forms_upload_normalize_path($directory);
}

function corsivo_forms_move_prepared_upload($source, $destination) {
	if (is_uploaded_file($source)) {
		return move_uploaded_file($source, $destination);
	}
	if (PHP_SAPI !== 'cli' && !(defined('WP_CLI') && WP_CLI)) {
		return false;
	}
	if (@rename($source, $destination)) {
		return true;
	}
	if (!@copy($source, $destination)) {
		return false;
	}
	if (!@unlink($source)) {
		wp_delete_file($destination);
		return false;
	}
	return true;
}

function corsivo_forms_remove_submission_upload_directory($submission_id, array $uploads) {
	$directory = corsivo_forms_existing_submission_upload_directory($submission_id);
	if (is_wp_error($directory)) {
		return $directory;
	}
	if (!$directory) {
		return $uploads
			? new WP_Error('corsivo_forms_upload_files_missing', __('La directory dei file non è disponibile.', 'corsivo-forms'))
			: true;
	}

	$expected = ['.corsivo-forms-submission' => true];
	foreach ($uploads as $upload) {
		$expected[$upload['storage_name']] = true;
	}
	$entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
	if (!$uploads) {
		foreach ($entries as $entry) {
			if ($entry === '.corsivo-forms-submission') {
				continue;
			}
			if (!preg_match('/^[a-f0-9]{32}\.([a-z0-9]+)$/D', $entry, $matches)
				|| !isset(corsivo_forms_upload_type_registry()[$matches[1]])) {
				return new WP_Error('corsivo_forms_upload_directory_unexpected_entry', __('La directory dei file contiene elementi non riconosciuti.', 'corsivo-forms'));
			}
			$uploads[] = ['storage_name' => $entry];
			$expected[$entry] = true;
		}
	}
	foreach ($entries as $entry) {
		if (!isset($expected[$entry])) {
			return new WP_Error('corsivo_forms_upload_directory_unexpected_entry', __('La directory dei file contiene elementi non riconosciuti.', 'corsivo-forms'));
		}
	}

	foreach ($uploads as $upload) {
		$path = corsivo_forms_upload_normalize_path($directory . '/' . $upload['storage_name']);
		if (!file_exists($path)) {
			continue;
		}
		$resolved = realpath($path);
		if (!$resolved
			|| is_link($path)
			|| !is_file($resolved)
			|| !corsivo_forms_upload_path_is_within($resolved, $directory)) {
			return new WP_Error('corsivo_forms_upload_path_invalid', __('Il percorso di un file non è valido.', 'corsivo-forms'));
		}
		wp_delete_file($resolved);
		if (file_exists($resolved)) {
			return new WP_Error('corsivo_forms_upload_delete_failed', __('Un file non può essere eliminato.', 'corsivo-forms'));
		}
	}

	$remaining = array_values(array_diff(scandir($directory) ?: [], ['.', '..', '.corsivo-forms-submission']));
	if ($remaining) {
		return new WP_Error('corsivo_forms_upload_directory_not_empty', __('La directory dei file non può essere rimossa.', 'corsivo-forms'));
	}
	$marker = $directory . '/.corsivo-forms-submission';
	if (!corsivo_forms_upload_marker_matches($directory, '.corsivo-forms-submission', corsivo_forms_upload_submission_marker_contents(get_current_blog_id(), $submission_id))) {
		return new WP_Error('corsivo_forms_upload_directory_unowned', __('La directory dei file non è attestata.', 'corsivo-forms'));
	}
	wp_delete_file($marker);
	if (file_exists($marker) || !@rmdir($directory)) {
		return new WP_Error('corsivo_forms_upload_directory_delete_failed', __('La directory dei file non può essere eliminata.', 'corsivo-forms'));
	}
	return true;
}

function corsivo_forms_prune_empty_upload_directories() {
	$root = corsivo_forms_existing_upload_storage_root();
	if (is_wp_error($root) || !$root) {
		return is_wp_error($root) ? $root : true;
	}
	$blog_id = max(1, get_current_blog_id());
	$sites = realpath($root . '/sites');
	$site = realpath($root . '/sites/' . $blog_id);
	$submissions = realpath($root . '/sites/' . $blog_id . '/submissions');
	if ($submissions && corsivo_forms_upload_path_is_within($submissions, $site)) {
		$entries = array_values(array_diff(scandir($submissions) ?: [], ['.', '..']));
		if (!$entries) {
			@rmdir($submissions);
		}
	}
	if ($site
		&& corsivo_forms_upload_path_is_within($site, $sites)
		&& corsivo_forms_upload_marker_matches($site, '.corsivo-forms-site', corsivo_forms_upload_site_marker_contents($blog_id))) {
		$entries = array_values(array_diff(scandir($site) ?: [], ['.', '..', '.corsivo-forms-site']));
		if (!$entries) {
			wp_delete_file($site . '/.corsivo-forms-site');
			@rmdir($site);
		}
	}
	if ($sites) {
		$entries = array_values(array_diff(scandir($sites) ?: [], ['.', '..']));
		if (!$entries) {
			@rmdir($sites);
		}
	}
	return true;
}

function corsivo_forms_store_submission_uploads($submission_id, array $prepared) {
	$submission_id = absint($submission_id);
	if (!$submission_id || get_post_type($submission_id) !== 'corsivo_submission') {
		return new WP_Error('corsivo_forms_upload_submission_invalid', __('La richiesta associata ai file non è valida.', 'corsivo-forms'));
	}
	$batch_id = strtolower((string) ($prepared['batch_id'] ?? ''));
	if (!preg_match('/^[a-f0-9]{32}$/D', $batch_id)) {
		return new WP_Error('corsivo_forms_upload_batch_invalid', __('Il gruppo di file preparato non è valido.', 'corsivo-forms'));
	}
	$batches = $GLOBALS['corsivo_forms_prepared_upload_batches'] ?? [];
	$batch = is_array($batches) ? ($batches[$batch_id] ?? null) : null;
	if (!is_array($batch) || (int) ($batch['blog_id'] ?? 0) !== get_current_blog_id() || !is_array($batch['files'] ?? null)) {
		return new WP_Error('corsivo_forms_upload_batch_expired', __('Il gruppo di file preparato non è più disponibile.', 'corsivo-forms'));
	}
	$files = $batch['files'];
	unset($GLOBALS['corsivo_forms_prepared_upload_batches'][$batch_id]);
	if (!$files) {
		return ['files' => [], 'values' => []];
	}
	if (get_post_meta($submission_id, corsivo_forms_upload_meta_key(), true) !== '') {
		return new WP_Error('corsivo_forms_uploads_already_stored', __('La richiesta contiene già dei file.', 'corsivo-forms'));
	}

	$directory = corsivo_forms_ensure_submission_upload_directory($submission_id);
	if (is_wp_error($directory)) {
		return $directory;
	}
	$entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..', '.corsivo-forms-submission']));
	if ($entries) {
		return new WP_Error('corsivo_forms_upload_directory_not_empty', __('La directory della richiesta non è vuota.', 'corsivo-forms'));
	}

	$stored = [];
	foreach ($files as $file) {
		$tmp_name = (string) ($file['tmp_name'] ?? '');
		$metadata = $file;
		unset($metadata['tmp_name']);
		$normalized = corsivo_forms_normalize_stored_upload($metadata);
		if (is_wp_error($normalized)
			|| $tmp_name === ''
			|| !is_file($tmp_name)
			|| is_link($tmp_name)
			|| filesize($tmp_name) !== $normalized['size']
			|| !hash_equals($normalized['sha256'], (string) hash_file('sha256', $tmp_name))) {
			corsivo_forms_remove_submission_upload_directory($submission_id, $stored);
			return is_wp_error($normalized)
				? $normalized
				: new WP_Error('corsivo_forms_upload_changed', __('Un file è cambiato dopo la validazione.', 'corsivo-forms'));
		}

		$destination = corsivo_forms_upload_normalize_path($directory . '/' . $normalized['storage_name']);
		if (file_exists($destination)
			|| !corsivo_forms_upload_path_is_within($destination, $directory)
			|| !corsivo_forms_move_prepared_upload($tmp_name, $destination)) {
			corsivo_forms_remove_submission_upload_directory($submission_id, $stored);
			return new WP_Error('corsivo_forms_upload_move_failed', __('Un file non può essere spostato nella directory privata.', 'corsivo-forms'));
		}
		@chmod($destination, 0640);
		$resolved = realpath($destination);
		if (!$resolved
			|| is_link($destination)
			|| !corsivo_forms_upload_path_is_within($resolved, $directory)
			|| filesize($resolved) !== $normalized['size']
			|| !hash_equals($normalized['sha256'], (string) hash_file('sha256', $resolved))) {
			if ($resolved && is_file($resolved) && !is_link($resolved)) {
				wp_delete_file($resolved);
			}
			corsivo_forms_remove_submission_upload_directory($submission_id, $stored);
			return new WP_Error('corsivo_forms_upload_verification_failed', __('Un file salvato non supera la verifica di integrità.', 'corsivo-forms'));
		}
		$stored[] = $normalized;
	}

	$added = add_post_meta($submission_id, corsivo_forms_upload_meta_key(), wp_slash($stored), true);
	$verified = $added ? corsivo_forms_submission_uploads($submission_id) : null;
	if (!$added || is_wp_error($verified) || wp_json_encode($verified) !== wp_json_encode($stored)) {
		delete_post_meta($submission_id, corsivo_forms_upload_meta_key());
		corsivo_forms_remove_submission_upload_directory($submission_id, $stored);
		return new WP_Error('corsivo_forms_upload_metadata_store_failed', __('I metadati dei file non possono essere salvati.', 'corsivo-forms'));
	}

	return [
		'files'  => $stored,
		'values' => corsivo_forms_group_upload_values($stored),
	];
}

function corsivo_forms_delete_submission_uploads($submission_id) {
	$submission_id = absint($submission_id);
	$uploads = corsivo_forms_submission_uploads($submission_id);
	if (is_wp_error($uploads)) {
		return $uploads;
	}
	$deleted = corsivo_forms_remove_submission_upload_directory($submission_id, $uploads);
	if (is_wp_error($deleted)) {
		return $deleted;
	}
	delete_post_meta($submission_id, corsivo_forms_upload_meta_key());
	corsivo_forms_prune_empty_upload_directories();
	return true;
}

function corsivo_forms_guard_submission_upload_deletion($delete, $post, $force_delete) {
	if ($delete !== null || !($post instanceof WP_Post) || $post->post_type !== 'corsivo_submission') {
		return $delete;
	}
	unset($GLOBALS['corsivo_forms_upload_deletion_error']);
	$deleted = corsivo_forms_delete_submission_uploads((int) $post->ID);
	if (is_wp_error($deleted)) {
		$GLOBALS['corsivo_forms_upload_deletion_error'] = $deleted;
		return false;
	}
	return null;
}

function corsivo_forms_privacy_delete_submission_uploads($submission_id) {
	return corsivo_forms_delete_submission_uploads($submission_id);
}

function corsivo_forms_privacy_export_uploads($submission_id) {
	$uploads = corsivo_forms_submission_uploads($submission_id);
	if (is_wp_error($uploads)) {
		return $uploads;
	}
	$data = [];
	foreach ($uploads as $upload) {
		$data[] = [
			'name'  => __('File caricato', 'corsivo-forms'),
			'value' => implode("\n", [
				$upload['original_name'],
				$upload['mime'],
				size_format($upload['size']),
			]),
		];
	}
	return $data;
}
function corsivo_forms_resolve_submission_upload($submission_id, $upload_id) {
	$submission_id = absint($submission_id);
	$upload_id = strtolower((string) $upload_id);
	if (!$submission_id || !preg_match('/^[a-f0-9]{32}$/D', $upload_id) || get_post_type($submission_id) !== 'corsivo_submission') {
		return new WP_Error('corsivo_forms_upload_not_found', __('Il file richiesto non esiste.', 'corsivo-forms'), ['status' => 404]);
	}
	$uploads = corsivo_forms_submission_uploads($submission_id);
	if (is_wp_error($uploads)) {
		return $uploads;
	}
	$metadata = null;
	foreach ($uploads as $upload) {
		if (hash_equals($upload['id'], $upload_id)) {
			$metadata = $upload;
			break;
		}
	}
	if (!$metadata) {
		return new WP_Error('corsivo_forms_upload_not_found', __('Il file richiesto non esiste.', 'corsivo-forms'), ['status' => 404]);
	}
	$directory = corsivo_forms_existing_submission_upload_directory($submission_id);
	if (is_wp_error($directory) || !$directory) {
		return is_wp_error($directory)
			? $directory
			: new WP_Error('corsivo_forms_upload_not_found', __('Il file richiesto non è disponibile.', 'corsivo-forms'), ['status' => 404]);
	}
	$path = corsivo_forms_upload_normalize_path($directory . '/' . $metadata['storage_name']);
	$resolved = realpath($path);
	if (!$resolved
		|| is_link($path)
		|| !is_file($resolved)
		|| !is_readable($resolved)
		|| !corsivo_forms_upload_path_is_within($resolved, $directory)
		|| filesize($resolved) !== $metadata['size']) {
		return new WP_Error('corsivo_forms_upload_not_found', __('Il file richiesto non è disponibile.', 'corsivo-forms'), ['status' => 404]);
	}
	$sha256 = hash_file('sha256', $resolved);
	if (!is_string($sha256) || !hash_equals($metadata['sha256'], $sha256)) {
		return new WP_Error('corsivo_forms_upload_integrity_failed', __('Il file richiesto non supera la verifica di integrità.', 'corsivo-forms'), ['status' => 409]);
	}
	return [
		'path'     => corsivo_forms_upload_normalize_path($resolved),
		'metadata' => $metadata,
	];
}

function corsivo_forms_submission_upload_download_url($submission_id, $upload_id) {
	$submission_id = absint($submission_id);
	$upload_id = strtolower((string) $upload_id);
	if (!$submission_id || !preg_match('/^[a-f0-9]{32}$/D', $upload_id)) {
		return '';
	}
	$action = 'corsivo_forms_download_upload_' . $submission_id . '_' . $upload_id;
	return wp_nonce_url(
		add_query_arg([
			'action'        => 'corsivo_forms_download_upload',
			'submission_id' => $submission_id,
			'upload_id'     => $upload_id,
		], admin_url('admin-post.php')),
		$action
	);
}

function corsivo_forms_authorize_submission_upload_download($submission_id, $upload_id, $nonce) {
	if (!current_user_can('manage_options')) {
		return new WP_Error('corsivo_forms_upload_download_forbidden', __('Non hai il permesso di scaricare questo file.', 'corsivo-forms'), ['status' => 403]);
	}
	$submission_id = absint($submission_id);
	$upload_id = strtolower((string) $upload_id);
	$action = 'corsivo_forms_download_upload_' . $submission_id . '_' . $upload_id;
	if (!$submission_id
		|| !preg_match('/^[a-f0-9]{32}$/D', $upload_id)
		|| !is_string($nonce)
		|| !wp_verify_nonce($nonce, $action)) {
		return new WP_Error('corsivo_forms_upload_download_nonce_invalid', __('Il link di download non è valido.', 'corsivo-forms'), ['status' => 403]);
	}
	return corsivo_forms_resolve_submission_upload($submission_id, $upload_id);
}

function corsivo_forms_upload_download_headers(array $metadata) {
	$filename = corsivo_forms_upload_original_name($metadata['original_name'] ?? '');
	$ascii_filename = sanitize_file_name(remove_accents($filename));
	$ascii_filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ascii_filename);
	$ascii_filename = trim((string) $ascii_filename, '.-') ?: 'file.' . ($metadata['extension'] ?? 'bin');
	return [
		'Content-Type'              => (string) $metadata['mime'],
		'Content-Length'            => (string) (int) $metadata['size'],
		'Content-Disposition'       => 'attachment; filename="' . $ascii_filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
		'X-Content-Type-Options'    => 'nosniff',
		'Content-Security-Policy'   => "default-src 'none'; sandbox",
		'Cache-Control'             => 'private, no-store, no-cache, must-revalidate, max-age=0',
		'Pragma'                    => 'no-cache',
		'X-Robots-Tag'              => 'noindex, nofollow, noarchive',
	];
}

function corsivo_forms_download_submission_upload() {
	$submission_id = isset($_GET['submission_id']) ? absint(wp_unslash($_GET['submission_id'])) : 0;
	$upload_id = isset($_GET['upload_id']) ? sanitize_key(wp_unslash($_GET['upload_id'])) : '';
	$nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
	$resolved = corsivo_forms_authorize_submission_upload_download($submission_id, $upload_id, $nonce);
	if (is_wp_error($resolved)) {
		$data = $resolved->get_error_data();
		$status = is_array($data) ? (int) ($data['status'] ?? 500) : 500;
		wp_die(esc_html($resolved->get_error_message()), '', ['response' => $status]);
	}

	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	foreach (corsivo_forms_upload_download_headers($resolved['metadata']) as $name => $value) {
		header($name . ': ' . $value);
	}
	$streamed = readfile($resolved['path']);
	if ($streamed === false) {
		status_header(500);
	}
	exit;
}

function corsivo_forms_uninstall_upload_storage() {
	$root = corsivo_forms_existing_upload_storage_root();
	if (is_wp_error($root) || !$root) {
		return is_wp_error($root) ? $root : true;
	}
	$blog_id = max(1, get_current_blog_id());
	$sites = realpath($root . '/sites');
	$site = realpath($root . '/sites/' . $blog_id);
	if (!$site) {
		return true;
	}
	if (!$sites
		|| !corsivo_forms_upload_path_is_within($site, $sites)
		|| !corsivo_forms_upload_marker_matches($site, '.corsivo-forms-site', corsivo_forms_upload_site_marker_contents($blog_id))) {
		return new WP_Error('corsivo_forms_upload_directory_unowned', __('La directory privata del sito non è attestata.', 'corsivo-forms'));
	}

	$submissions = realpath($site . '/submissions');
	if ($submissions) {
		if (!corsivo_forms_upload_path_is_within($submissions, $site) || is_link($submissions)) {
			return new WP_Error('corsivo_forms_upload_path_invalid', __('La directory privata delle richieste non è valida.', 'corsivo-forms'));
		}
		$submission_entries = array_values(array_diff(scandir($submissions) ?: [], ['.', '..']));
		foreach ($submission_entries as $entry) {
			if (!preg_match('/^[1-9][0-9]*$/D', $entry)) {
				return new WP_Error('corsivo_forms_upload_directory_unexpected_entry', __('La directory privata contiene elementi non riconosciuti.', 'corsivo-forms'));
			}
			$submission_id = (int) $entry;
			$directory = realpath($submissions . '/' . $entry);
			if (!$directory
				|| is_link($directory)
				|| !corsivo_forms_upload_path_is_within($directory, $submissions)
				|| !corsivo_forms_upload_marker_matches($directory, '.corsivo-forms-submission', corsivo_forms_upload_submission_marker_contents($blog_id, $submission_id))) {
				return new WP_Error('corsivo_forms_upload_directory_unowned', __('La directory privata di una richiesta non è attestata.', 'corsivo-forms'));
			}
			$uploads = [];
			foreach (array_values(array_diff(scandir($directory) ?: [], ['.', '..', '.corsivo-forms-submission'])) as $filename) {
				if (!preg_match('/^[a-f0-9]{32}\.([a-z0-9]+)$/D', $filename, $matches)
					|| !isset(corsivo_forms_upload_type_registry()[$matches[1]])) {
					return new WP_Error('corsivo_forms_upload_directory_unexpected_entry', __('La directory privata contiene file non riconosciuti.', 'corsivo-forms'));
				}
				$uploads[] = ['storage_name' => $filename];
			}
			$deleted = corsivo_forms_remove_submission_upload_directory($submission_id, $uploads);
			if (is_wp_error($deleted)) {
				return $deleted;
			}
		}
	}

	$pruned = corsivo_forms_prune_empty_upload_directories();
	if (is_wp_error($pruned)) {
		return $pruned;
	}
	$sites = realpath($root . '/sites');
	if ($sites && !array_diff(scandir($sites) ?: [], ['.', '..'])) {
		@rmdir($sites);
	}
	$remaining = array_values(array_diff(scandir($root) ?: [], ['.', '..', corsivo_forms_upload_storage_marker_name()]));
	if (!$remaining) {
		$marker = $root . '/' . corsivo_forms_upload_storage_marker_name();
		if (!corsivo_forms_upload_marker_matches($root, corsivo_forms_upload_storage_marker_name(), corsivo_forms_upload_storage_marker_contents())) {
			return new WP_Error('corsivo_forms_upload_storage_unowned', __('La directory privata non è attestata.', 'corsivo-forms'));
		}
		wp_delete_file($marker);
		if (file_exists($marker) || !@rmdir($root)) {
			return new WP_Error('corsivo_forms_upload_storage_delete_failed', __('La directory privata non può essere eliminata.', 'corsivo-forms'));
		}
	}
	return true;
}



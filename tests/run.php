<?php

if (!defined('ABSPATH')) {
	exit(1);
}

global $wpdb;

$GLOBALS['corsivo_forms_test_checks'] = 0;
$GLOBALS['corsivo_forms_test_skips'] = 0;
$GLOBALS['corsivo_forms_test_hook_backups'] = [];
$corsivo_forms_test_checks = &$GLOBALS['corsivo_forms_test_checks'];
$corsivo_forms_test_skips = &$GLOBALS['corsivo_forms_test_skips'];
$corsivo_forms_test_created_posts = [];
$corsivo_forms_test_activity_ids = [];
$corsivo_forms_test_temp_files = [];
$corsivo_forms_test_temp_directories = [];
$corsivo_forms_test_hook_backups = &$GLOBALS['corsivo_forms_test_hook_backups'];
$corsivo_forms_test_rate_keys = [];
$corsivo_forms_test_admin_email_filter = null;
$corsivo_forms_test_ttl_filter = null;
$corsivo_forms_test_privacy_delete_filter = null;
$corsivo_forms_test_failure = null;
$corsivo_forms_test_settings_marker = new stdClass();
$corsivo_forms_test_previous_settings = get_option('corsivo_forms_settings', $corsivo_forms_test_settings_marker);
$corsivo_forms_test_settings_existed = $corsivo_forms_test_previous_settings !== $corsivo_forms_test_settings_marker;
$corsivo_forms_test_privacy_page_marker = new stdClass();
$corsivo_forms_test_previous_privacy_page = get_option('wp_page_for_privacy_policy', $corsivo_forms_test_privacy_page_marker);
$corsivo_forms_test_privacy_page_existed = $corsivo_forms_test_previous_privacy_page !== $corsivo_forms_test_privacy_page_marker;
$corsivo_forms_test_had_log_suppression = array_key_exists('corsivo_forms_suppress_activity_log', $GLOBALS);
$corsivo_forms_test_previous_log_suppression = $GLOBALS['corsivo_forms_suppress_activity_log'] ?? false;
$corsivo_forms_test_had_prepared_upload_batches = array_key_exists('corsivo_forms_prepared_upload_batches', $GLOBALS);
$corsivo_forms_test_previous_prepared_upload_batches = $GLOBALS['corsivo_forms_prepared_upload_batches'] ?? null;
$GLOBALS['corsivo_forms_suppress_activity_log'] = true;

function corsivo_forms_test_check($condition, $message) {
	global $corsivo_forms_test_checks;

	$corsivo_forms_test_checks++;
	if (!$condition) {
		throw new RuntimeException('Check ' . $corsivo_forms_test_checks . ' fallito: ' . $message);
	}

	echo 'ok ' . $corsivo_forms_test_checks . ' - ' . $message . PHP_EOL;
}

function corsivo_forms_test_skip($message) {
	global $corsivo_forms_test_skips;

	$corsivo_forms_test_skips++;
	echo 'skip ' . $corsivo_forms_test_skips . ' - ' . $message . PHP_EOL;
}

function corsivo_forms_test_suspend_hook($tag) {
	global $wp_filter, $corsivo_forms_test_hook_backups;

	if (array_key_exists($tag, $corsivo_forms_test_hook_backups)) {
		return;
	}

	$corsivo_forms_test_hook_backups[$tag] = isset($wp_filter[$tag]) ? clone $wp_filter[$tag] : null;
	remove_all_filters($tag);
}

function corsivo_forms_test_restore_hooks() {
	global $wp_filter, $corsivo_forms_test_hook_backups;

	foreach ($corsivo_forms_test_hook_backups as $tag => $hook) {
		if ($hook === null) {
			unset($wp_filter[$tag]);
		} else {
			$wp_filter[$tag] = $hook;
		}
	}

	$corsivo_forms_test_hook_backups = [];
}

function corsivo_forms_test_rest_request(array $payload) {
	$request = new WP_REST_Request('POST', '/corsivo/v1/forms/submit');
	$request->set_header('content-type', 'application/json');
	$request->set_body((string) wp_json_encode($payload));

	return rest_do_request($request);
}

function corsivo_forms_test_submission_ids($request_id) {
	global $wpdb;

	return array_map('intval', $wpdb->get_col($wpdb->prepare(
		"SELECT DISTINCT posts.ID
		 FROM {$wpdb->posts} posts
		 INNER JOIN {$wpdb->postmeta} request_meta
		 ON request_meta.post_id = posts.ID
		 WHERE posts.post_type = 'corsivo_submission'
		 AND request_meta.meta_key = '_corsivo_forms_request_id'
		 AND request_meta.meta_value = %s",
		$request_id
	)));
}

function corsivo_forms_test_temp_file($contents, $filename) {
	global $corsivo_forms_test_temp_files;

	$path = wp_tempnam((string) $filename);
	if (!is_string($path) || $path === '' || file_put_contents($path, (string) $contents, LOCK_EX) !== strlen((string) $contents)) {
		throw new RuntimeException('Impossibile creare il file temporaneo ' . $filename);
	}
	$corsivo_forms_test_temp_files[] = $path;
	return $path;
}

function corsivo_forms_test_zip_file(array $entries, $filename) {
	global $corsivo_forms_test_temp_files;

	if (!class_exists('ZipArchive')) {
		throw new RuntimeException('ZipArchive non disponibile');
	}
	$path = wp_tempnam((string) $filename);
	if (!is_string($path) || $path === '') {
		throw new RuntimeException('Impossibile creare l’archivio temporaneo ' . $filename);
	}
	$corsivo_forms_test_temp_files[] = $path;
	$archive = new ZipArchive();
	if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		throw new RuntimeException('Impossibile aprire l’archivio temporaneo ' . $filename);
	}
	try {
		foreach ($entries as $entry_name => $entry_contents) {
			if (!$archive->addFromString((string) $entry_name, (string) $entry_contents)) {
				throw new RuntimeException('Impossibile aggiungere ' . $entry_name . ' a ' . $filename);
			}
		}
	} finally {
		$archive->close();
	}
	return $path;
}

function corsivo_forms_test_temp_directory($prefix) {
	global $corsivo_forms_test_temp_directories;

	$path = trailingslashit(sys_get_temp_dir())
		. sanitize_key((string) $prefix)
		. '-'
		. str_replace('-', '', wp_generate_uuid4());
	if (is_dir($path) || !wp_mkdir_p($path)) {
		throw new RuntimeException('Impossibile creare la directory temporanea ' . $prefix);
	}
	$resolved = realpath($path);
	if (!$resolved || is_link($path)) {
		throw new RuntimeException('Directory temporanea non valida ' . $prefix);
	}
	$resolved = corsivo_forms_upload_normalize_path($resolved);
	$corsivo_forms_test_temp_directories[] = $resolved;
	return $resolved;
}

function corsivo_forms_test_upload_entry($original_name, $path) {
	return [
		'name'     => (string) $original_name,
		'type'     => '',
		'tmp_name' => (string) $path,
		'error'    => UPLOAD_ERR_OK,
		'size'     => (int) filesize($path),
	];
}

function corsivo_forms_test_multiple_upload_entry(array $uploads) {
	$entry = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
	foreach ($uploads as $upload) {
		$file = corsivo_forms_test_upload_entry($upload['name'], $upload['path']);
		foreach (array_keys($entry) as $key) {
			$entry[$key][] = $file[$key];
		}
	}
	return $entry;
}

function corsivo_forms_test_upload_request(array $file_params, array $body_params = []) {
	$request = new WP_REST_Request('POST', '/corsivo/v1/forms/submit');
	$request->set_body_params($body_params);
	$request->set_file_params($file_params);
	return $request;
}

try {
	corsivo_forms_test_suspend_hook('corsivo_forms_allowed_recipients');
	add_filter('corsivo_forms_allowed_recipients', static fn($recipients) => array_merge($recipients, [
		'one@example.com', 'two@example.com', 'privacy@example.com', 'select@example.com',
		'files@example.com', 'plain@example.com', 'token@example.com', 'receiver@example.com',
		'changed@example.com', 'files-e2e@example.com',
	]));
	$required_functions = [
		'corsivo_forms_normalize_settings',
		'corsivo_forms_normalize_form_settings',
		'corsivo_forms_normalize_field_values',
		'corsivo_forms_validate_field',
		'corsivo_forms_create_form_token',
		'corsivo_forms_read_form_token',
		'corsivo_forms_notification_recipients',
		'corsivo_forms_definition_form_id',
		'corsivo_forms_notification_subject',
		'corsivo_forms_activity_claim_request',
		'corsivo_forms_activity_duplicate_response',
		'corsivo_forms_activity_exception_summary',
		'corsivo_forms_store_submission',
		'corsivo_forms_build_submission',
		'corsivo_forms_expired_submission_ids',
		'corsivo_forms_collect_forms',
		'corsivo_forms_collect_fields',
		'corsivo_forms_handle_submit',
		'corsivo_forms_upload_type_registry',
		'corsivo_forms_request_fingerprint',
		'corsivo_forms_prepare_request_uploads',
		'corsivo_forms_store_submission_uploads',
		'corsivo_forms_resolve_submission_upload',
		'corsivo_forms_submission_upload_download_url',
	];

	foreach ($required_functions as $required_function) {
		corsivo_forms_test_check(function_exists($required_function), 'funzione disponibile: ' . $required_function);
	}

	$settings = corsivo_forms_normalize_settings([
		'log_enabled'               => 'false',
		'log_retention_days'        => 99999,
		'submission_retention_days' => -10,
		'delete_data_on_uninstall'  => 'on',
		'corsivo_mail_enabled'       => 0,
		'corsivo_privacy_enabled'    => true,
	]);
	corsivo_forms_test_check($settings['log_enabled'] === false, 'normalizzazione booleano impostazioni');
	corsivo_forms_test_check($settings['log_retention_days'] === 3650, 'limite massimo retention attività');
	corsivo_forms_test_check($settings['submission_retention_days'] === 0, 'limite minimo retention submission');
	corsivo_forms_test_check($settings['delete_data_on_uninstall'] === true, 'normalizzazione checkbox impostazioni');
	corsivo_forms_test_check($settings['corsivo_mail_enabled'] === false, 'disattivazione integrazione mail normalizzata');

	$form_settings = corsivo_forms_normalize_form_settings([
		'form_title'     => '<b>Modulo test</b>',
		'recipients'     => 'one@example.com, two@example.com',
		'subject'        => 'Richiesta {name}',
		'success_message' => "Ricevuta\ncorrettamente",
		'store'          => false,
	]);
	corsivo_forms_test_check($form_settings['form_title'] === 'Modulo test', 'normalizzazione titolo form');
	corsivo_forms_test_check($form_settings['store'] === false, 'normalizzazione flag archivio form');
	$form_identity_definition = ['has_submit' => true, 'is_public' => true];
	$form_identity_payload = wp_json_encode([
		'settings'         => $form_settings,
		'fields'           => [],
		'has_submit'       => true,
		'schema_truncated' => false,
		'too_many_fields'  => false,
		'is_public'        => true,
	]);
	$form_identity = corsivo_forms_definition_form_id($form_settings, [], $form_identity_definition);
	corsivo_forms_test_check($form_identity === substr(hash('sha256', $form_identity_payload), 0, 32), 'identità modulo deterministica e indipendente dai salt WordPress');

	$sender_fields = [
		[
			'type'   => 'text',
			'name'   => 'company',
			'values' => corsivo_forms_normalize_field_values('text', ['label' => 'Azienda', 'field_key' => 'company']),
		],
		[
			'type'   => 'text',
			'name'   => 'sender_name',
			'values' => corsivo_forms_normalize_field_values('text', ['label' => 'Nome', 'field_key' => 'sender_name']),
		],
		[
			'type'   => 'email',
			'name'   => 'email',
			'values' => corsivo_forms_normalize_field_values('email', ['label' => 'Email', 'field_key' => 'email']),
		],
	];
	$sender_definition = [
		'form_id'    => hash('md5', 'corsivo-forms-sender-test'),
		'page_id'    => 0,
		'page_title' => '',
		'page_url'   => '',
		'language'   => 'it_it',
	];
	$sender_submission = corsivo_forms_build_submission(
		$form_settings,
		$sender_fields,
		['company' => 'Acme', 'sender_name' => 'Mario Rossi', 'email' => 'mario@example.com'],
		$sender_definition,
		wp_generate_uuid4()
	);
	corsivo_forms_test_check($sender_submission['sender_name'] === 'Mario Rossi', 'chiave sender_name prevale sul primo campo testo');
	corsivo_forms_test_check(corsivo_forms_notification_headers($sender_submission) === ['Reply-To: Mario Rossi <mario@example.com>'], 'Reply-To usa il campo mittente esplicito');
	$fallback_sender_submission = corsivo_forms_build_submission(
		$form_settings,
		[$sender_fields[0], $sender_fields[2]],
		['company' => 'Acme', 'email' => 'info@example.com'],
		$sender_definition,
		wp_generate_uuid4()
	);
	corsivo_forms_test_check($fallback_sender_submission['sender_name'] === 'Acme', 'primo campo testo usato come nome mittente di riserva');
	$mail_test_settings = corsivo_forms_get_settings();
	update_option('corsivo_forms_settings', array_merge($mail_test_settings, ['corsivo_mail_enabled' => false]), false);
	$mail_attempts = 0;
	$mail_failure_filter = static function ($preempt, $attributes) use (&$mail_attempts) {
		$mail_attempts++;
		if ($mail_attempts === 1) {
			throw new RuntimeException('Errore trasporto test');
		}
		return false;
	};
	add_filter('pre_wp_mail', $mail_failure_filter, 0, 2);
	$mail_failure_result = corsivo_forms_send_notification($sender_submission);
	remove_filter('pre_wp_mail', $mail_failure_filter, 0);
	update_option('corsivo_forms_settings', $mail_test_settings, false);
	corsivo_forms_test_check($mail_failure_result['status'] === 'failed' && $mail_failure_result['failed'] === 2, 'notifica multi-destinatario conta tutte le failure');
	corsivo_forms_test_check($mail_failure_result['error_code'] === 'wp_mail_exception', 'errore mail specifico prevale sul rifiuto generico successivo');

	$select_values = corsivo_forms_normalize_field_values('select', [
		'label'       => '<b>Motivo</b>',
		'field_key'   => 'reason',
		'required'    => 1,
		'placeholder' => '<i>Scegli</i>',
		'options'     => [
			['option' => 'Uno'],
			['option' => 'Uno'],
			'non valido',
			['option' => '<b>Due</b>'],
		],
	]);
	corsivo_forms_test_check($select_values['label'] === 'Motivo', 'normalizzazione label campo');
	corsivo_forms_test_check($select_values['required'] === true, 'normalizzazione required campo');
	corsivo_forms_test_check($select_values['options'] === [['option' => 'Uno'], ['option' => 'Due']], 'deduplica e normalizzazione opzioni select');

	$definition_fields = corsivo_forms_normalize_definition_fields([
		[
			'type'   => 'text',
			'name'   => 'contact_name',
			'values' => ['label' => 'Nome', 'field_key' => 'contact_name', 'required' => true],
		],
		[
			'type'   => 'unknown',
			'name'   => 'ignored',
			'values' => [],
		],
	]);
	corsivo_forms_test_check(count($definition_fields) === 1 && $definition_fields[0]['name'] === 'contact_name', 'normalizzazione definizione campi scarta tipi ignoti');

	$checkbox_field = [
		'type'   => 'checkbox',
		'name'   => 'terms',
		'values' => corsivo_forms_normalize_field_values('checkbox', ['label' => 'Termini', 'field_key' => 'terms', 'required' => true]),
	];
	$privacy_field = [
		'type'   => 'privacy',
		'name'   => 'privacy',
		'values' => corsivo_forms_normalize_field_values('privacy', ['label' => 'Consenso privacy']),
	];

	foreach ([false, 'false', ['1']] as $invalid_boolean) {
		$checkbox_result = corsivo_forms_validate_field($checkbox_field, $invalid_boolean);
		$privacy_result = corsivo_forms_validate_field($privacy_field, $invalid_boolean);
		corsivo_forms_test_check($checkbox_result['value'] === false && $checkbox_result['error'] !== null, 'checkbox rifiuta valore non accettato');
		corsivo_forms_test_check($privacy_result['value'] === false && $privacy_result['error'] !== null, 'privacy rifiuta valore non accettato');
	}

	$checkbox_result = corsivo_forms_validate_field($checkbox_field, '1');
	$privacy_result = corsivo_forms_validate_field($privacy_field, 1);
	corsivo_forms_test_check($checkbox_result === ['value' => true, 'error' => null], 'checkbox accetta 1');
	corsivo_forms_test_check($privacy_result === ['value' => true, 'error' => null], 'privacy accetta 1');
	$missing_policy_error = corsivo_forms_form_configuration_error([
		'is_public'        => true,
		'has_submit'       => true,
		'schema_truncated' => false,
		'too_many_fields'  => false,
		'settings'         => ['recipients' => 'privacy@example.com'],
		'fields'           => [$privacy_field + ['policy_url' => '']],
	]);
	corsivo_forms_test_check(is_wp_error($missing_policy_error) && $missing_policy_error->get_error_code() === 'privacy_policy_missing', 'form privacy senza informativa bloccato esplicitamente');
	$empty_select_error = corsivo_forms_form_configuration_error([
		'is_public'  => true,
		'has_submit' => true,
		'settings'   => ['recipients' => 'select@example.com'],
		'fields'     => [[
			'type'   => 'select',
			'name'   => 'choice',
			'values' => corsivo_forms_normalize_field_values('select', ['required' => true, 'options' => []]),
		]],
	]);
	corsivo_forms_test_check(is_wp_error($empty_select_error) && $empty_select_error->get_error_code() === 'empty_required_select', 'select obbligatoria senza opzioni bloccata al render');

	$upload_registry = corsivo_forms_upload_type_registry();
	corsivo_forms_test_check(
		isset($upload_registry['pdf'], $upload_registry['jpg'], $upload_registry['jpeg'], $upload_registry['png'])
			&& !isset($upload_registry['svg'], $upload_registry['htm'], $upload_registry['html']),
		'registry upload espone la allowlist documentale senza SVG o HTML'
	);
	$registry_mimes = array_merge(...array_values($upload_registry));
	corsivo_forms_test_check(
		$registry_mimes === array_map('strtolower', $registry_mimes),
		'registry upload conserva MIME canonici lowercase'
	);

	$single_file_values = corsivo_forms_normalize_field_values('file', [
		'label'              => 'Documento',
		'field_key'          => 'document',
		'required'           => true,
		'multiple'           => false,
		'allowed_extensions' => ['png', 'svg', 'PDF', 'png'],
		'max_files'          => 10,
		'max_file_size_mb'   => 0,
	]);
	corsivo_forms_test_check(
		$single_file_values['required'] === true
			&& $single_file_values['multiple'] === false
			&& $single_file_values['allowed_extensions'] === ['png', 'pdf']
			&& $single_file_values['max_files'] === 1
			&& $single_file_values['max_file_size_mb'] === 1,
		'campo file singolo normalizza allowlist e limiti'
	);

	$multiple_file_values = corsivo_forms_normalize_field_values('file', [
		'label'              => 'Documenti',
		'field_key'          => 'documents',
		'multiple'           => true,
		'allowed_extensions' => [['value' => 'jpeg'], 'docx', 'html'],
		'max_files'          => 99,
		'max_file_size_mb'   => 99,
	]);
	corsivo_forms_test_check(
		$multiple_file_values['multiple'] === true
			&& $multiple_file_values['allowed_extensions'] === ['jpeg', 'docx']
			&& $multiple_file_values['max_files'] === 10
			&& $multiple_file_values['max_file_size_mb'] === 20,
		'campo file multiplo limita quantità, dimensione e formati al registry'
	);

	$file_field = [
		'type'   => 'file',
		'name'   => 'documents',
		'values' => $multiple_file_values,
	];
	$file_store_error = corsivo_forms_form_configuration_error([
		'is_public'  => true,
		'has_submit' => true,
		'settings'   => ['recipients' => 'files@example.com', 'store' => false],
		'fields'     => [$file_field],
	]);
	corsivo_forms_test_check(
		is_wp_error($file_store_error) && $file_store_error->get_error_code() === 'file_storage_required',
		'form con file richiede storage submission'
	);

	$plain_form_configuration = corsivo_forms_form_configuration_error([
		'is_public'  => true,
		'has_submit' => true,
		'settings'   => ['recipients' => 'plain@example.com', 'store' => false],
		'fields'     => [[
			'type'   => 'text',
			'name'   => 'name',
			'values' => corsivo_forms_normalize_field_values('text', ['label' => 'Nome']),
		]],
	]);
	corsivo_forms_test_check($plain_form_configuration === null, 'form senza file resta valido senza archiviazione');

	$render_file_values = corsivo_forms_normalize_field_values('file', [
		'label'              => 'Allegati',
		'field_key'          => 'documents',
		'required'           => true,
		'multiple'           => true,
		'allowed_extensions' => ['png', 'pdf'],
		'max_files'          => 3,
		'max_file_size_mb'   => 2,
	]);
	$file_markup = corsivo_forms_render_field_input('file', 'documents', $render_file_values);
	corsivo_forms_test_check(
		str_contains($file_markup, 'type="file"')
			&& str_contains($file_markup, 'name="documents[]"')
			&& str_contains($file_markup, 'accept=".png,.pdf"')
			&& str_contains($file_markup, ' multiple')
			&& str_contains($file_markup, 'aria-describedby=')
			&& str_contains($file_markup, 'Massimo 3 file da 2 MB ciascuno.'),
		'render campo file espone accept, multiple e limiti accessibili'
	);

	$png_contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
	if (!is_string($png_contents)) {
		throw new RuntimeException('Fixture PNG non valida');
	}
	$png_path = corsivo_forms_test_temp_file($png_contents, 'pixel-test.png');
	$changed_path = corsivo_forms_test_temp_file($png_contents . 'changed', 'pixel-changed.png');
	$mismatched_path = corsivo_forms_test_temp_file('contenuto non immagine', 'mismatch.png');
	$large_path = corsivo_forms_test_temp_file(str_repeat('a', MB_IN_BYTES + 1), 'large.txt');
	$request_identity_params = ['formId' => str_repeat('b', 32), 'name' => 'Mario'];

	$identity_base_request = corsivo_forms_test_upload_request([
		'documents' => corsivo_forms_test_upload_entry('alpha.png', $png_path),
	], $request_identity_params);
	$identity_name_request = corsivo_forms_test_upload_request([
		'documents' => corsivo_forms_test_upload_entry('beta.png', $png_path),
	], $request_identity_params);
	$identity_content_request = corsivo_forms_test_upload_request([
		'documents' => corsivo_forms_test_upload_entry('alpha.png', $changed_path),
	], $request_identity_params);
	$identity_order_request = corsivo_forms_test_upload_request([
		'documents' => corsivo_forms_test_multiple_upload_entry([
			['name' => 'alpha.png', 'path' => $png_path],
			['name' => 'beta.png', 'path' => $changed_path],
		]),
	], $request_identity_params);
	$identity_reverse_request = corsivo_forms_test_upload_request([
		'documents' => corsivo_forms_test_multiple_upload_entry([
			['name' => 'beta.png', 'path' => $changed_path],
			['name' => 'alpha.png', 'path' => $png_path],
		]),
	], $request_identity_params);
	$identity_base = corsivo_forms_request_fingerprint($identity_base_request);
	$identity_name = corsivo_forms_request_fingerprint($identity_name_request);
	$identity_content = corsivo_forms_request_fingerprint($identity_content_request);
	$identity_order = corsivo_forms_request_fingerprint($identity_order_request);
	$identity_reverse = corsivo_forms_request_fingerprint($identity_reverse_request);
	corsivo_forms_test_check(
		is_string($identity_base)
			&& $identity_base !== $identity_name
			&& $identity_base !== $identity_content
			&& $identity_order !== $identity_reverse,
		'identità richiesta cambia con nome, contenuto e ordine dei file'
	);

	$png_field = [
		'type'   => 'file',
		'name'   => 'documents',
		'values' => corsivo_forms_normalize_field_values('file', [
			'label'              => 'Immagine',
			'field_key'          => 'documents',
			'required'           => true,
			'multiple'           => false,
			'allowed_extensions' => ['png'],
			'max_files'          => 1,
			'max_file_size_mb'   => 1,
		]),
	];
	$extension_error = corsivo_forms_prepare_request_uploads(
		corsivo_forms_test_upload_request([
			'documents' => corsivo_forms_test_upload_entry('image.svg', $png_path),
		]),
		[$png_field]
	);
	corsivo_forms_test_check(
		is_wp_error($extension_error) && $extension_error->get_error_code() === 'corsivo_forms_upload_extension_invalid',
		'prepare upload rifiuta estensione non consentita'
	);

	$mime_error = corsivo_forms_prepare_request_uploads(
		corsivo_forms_test_upload_request([
			'documents' => corsivo_forms_test_upload_entry('image.png', $mismatched_path),
		]),
		[$png_field]
	);
	corsivo_forms_test_check(
		is_wp_error($mime_error)
			&& in_array($mime_error->get_error_code(), [
				'corsivo_forms_upload_filetype_invalid',
				'corsivo_forms_upload_mime_invalid',
				'corsivo_forms_upload_image_invalid',
			], true),
		'prepare upload rifiuta contenuto con MIME incompatibile'
	);

	$count_field = $png_field;
	$count_field['values']['multiple'] = true;
	$count_error = corsivo_forms_prepare_request_uploads(
		corsivo_forms_test_upload_request([
			'documents' => corsivo_forms_test_multiple_upload_entry([
				['name' => 'one.png', 'path' => $png_path],
				['name' => 'two.png', 'path' => $png_path],
			]),
		]),
		[$count_field]
	);
	corsivo_forms_test_check(
		is_wp_error($count_error) && $count_error->get_error_code() === 'corsivo_forms_upload_field_count_exceeded',
		'prepare upload applica il massimo file per campo'
	);

	$size_field = [
		'type'   => 'file',
		'name'   => 'documents',
		'values' => corsivo_forms_normalize_field_values('file', [
			'label'              => 'Testo',
			'field_key'          => 'documents',
			'allowed_extensions' => ['txt'],
			'max_file_size_mb'   => 1,
		]),
	];
	$size_error = corsivo_forms_prepare_request_uploads(
		corsivo_forms_test_upload_request([
			'documents' => corsivo_forms_test_upload_entry('large.txt', $large_path),
		]),
		[$size_field]
	);
	corsivo_forms_test_check(
		is_wp_error($size_error) && $size_error->get_error_code() === 'corsivo_forms_upload_file_too_large',
		'prepare upload applica il limite dimensione per file'
	);

	$unexpected_error = corsivo_forms_prepare_request_uploads(
		corsivo_forms_test_upload_request([
			'unexpected' => corsivo_forms_test_upload_entry('image.png', $png_path),
		]),
		[$png_field]
	);
	corsivo_forms_test_check(
		is_wp_error($unexpected_error) && $unexpected_error->get_error_code() === 'corsivo_forms_upload_unexpected_field',
		'prepare upload rifiuta campi file non dichiarati'
	);

	$prepared_upload = corsivo_forms_prepare_request_uploads(
		corsivo_forms_test_upload_request([
			'documents' => corsivo_forms_test_upload_entry('pixel-test.png', $png_path),
		]),
		[$png_field]
	);
	corsivo_forms_test_check(
		!is_wp_error($prepared_upload)
			&& count($prepared_upload['prepared']['files'] ?? []) === 1
			&& ($prepared_upload['prepared']['files'][0]['mime'] ?? '') === 'image/png',
		'prepare upload accetta un PNG valido'
	);

	$upload_test_email = 'upload-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 10) . '@example.com';
	$document_content_types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>';
	$docx_path = corsivo_forms_test_zip_file([
		'[Content_Types].xml' => $document_content_types,
		'word/document.xml'   => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
	], 'minimal.docx');
	$xlsx_path = corsivo_forms_test_zip_file([
		'[Content_Types].xml' => $document_content_types,
		'xl/workbook.xml'     => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"></workbook>',
	], 'minimal.xlsx');
	$docx_field = [
		'type'   => 'file',
		'name'   => 'document',
		'values' => corsivo_forms_normalize_field_values('file', [
			'label'              => 'DOCX',
			'field_key'          => 'document',
			'required'           => true,
			'allowed_extensions' => ['docx'],
			'max_file_size_mb'   => 2,
		]),
	];
	$xlsx_field = [
		'type'   => 'file',
		'name'   => 'spreadsheet',
		'values' => corsivo_forms_normalize_field_values('file', [
			'label'              => 'XLSX',
			'field_key'          => 'spreadsheet',
			'required'           => true,
			'allowed_extensions' => ['xlsx'],
			'max_file_size_mb'   => 2,
		]),
	];
	$prepared_docx = corsivo_forms_prepare_request_uploads(
		corsivo_forms_test_upload_request([
			'document' => corsivo_forms_test_upload_entry('minimal.docx', $docx_path),
		]),
		[$docx_field]
	);
	$prepared_xlsx = corsivo_forms_prepare_request_uploads(
		corsivo_forms_test_upload_request([
			'spreadsheet' => corsivo_forms_test_upload_entry('minimal.xlsx', $xlsx_path),
		]),
		[$xlsx_field]
	);
	corsivo_forms_test_check(
		!is_wp_error($prepared_docx)
			&& ($prepared_docx['prepared']['files'][0]['extension'] ?? '') === 'docx',
		'prepare upload accetta un DOCX minimo con path ZIP canonici'
	);
	corsivo_forms_test_check(
		!is_wp_error($prepared_xlsx)
			&& ($prepared_xlsx['prepared']['files'][0]['extension'] ?? '') === 'xlsx',
		'prepare upload accetta un XLSX minimo con path ZIP canonici'
	);
	foreach ([$prepared_docx, $prepared_xlsx] as $prepared_container) {
		$batch_id = $prepared_container['prepared']['batch_id'] ?? '';
		if (is_string($batch_id) && $batch_id !== '') {
			unset($GLOBALS['corsivo_forms_prepared_upload_batches'][$batch_id]);
		}
	}

	$oversized_entries = [
		'[Content_Types].xml' => $document_content_types,
		'word/document.xml'   => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
	];
	for ($entry_index = 0; $entry_index < 2049; $entry_index++) {
		$oversized_entries['entries/' . str_pad((string) $entry_index, 4, '0', STR_PAD_LEFT) . '.xml'] = '<entry/>';
	}
	$oversized_docx_path = corsivo_forms_test_zip_file($oversized_entries, 'oversized.docx');
	unset($oversized_entries);
	corsivo_forms_test_check(
		corsivo_forms_upload_container_is_valid($oversized_docx_path, 'docx') === false,
		'prepare upload rifiuta container con più di 2048 entry'
	);

	$owned_parent = corsivo_forms_test_temp_directory('corsivo-forms-owned');
	$owned_directory = corsivo_forms_upload_normalize_path($owned_parent . '/adopted');
	if (!wp_mkdir_p($owned_directory)) {
		throw new RuntimeException('Impossibile creare la directory vuota da adottare');
	}
	$corsivo_forms_test_temp_directories[] = $owned_directory;
	$owned_marker_name = '.corsivo-forms-owned-test';
	$owned_marker_contents = 'corsivo-forms:test-owned:' . wp_generate_uuid4() . "\n";
	$adopted_directory = corsivo_forms_ensure_owned_upload_directory(
		$owned_directory,
		$owned_parent,
		$owned_marker_name,
		$owned_marker_contents
	);
	$recognized_directory = corsivo_forms_ensure_owned_upload_directory(
		$owned_directory,
		$owned_parent,
		$owned_marker_name,
		$owned_marker_contents
	);
	corsivo_forms_test_check(
		is_string($adopted_directory)
			&& $adopted_directory === $recognized_directory
			&& corsivo_forms_upload_marker_matches($owned_directory, $owned_marker_name, $owned_marker_contents),
		'directory vuota viene adottata e la seconda chiamata riconosce il marker'
	);

	$upload_submission_id = wp_insert_post([
		'post_type'   => 'corsivo_submission',
		'post_status' => 'publish',
		'post_title'  => 'Upload storage test',
	], true);
	corsivo_forms_test_check(!is_wp_error($upload_submission_id) && $upload_submission_id > 0, 'submission upload temporanea creata');
	$upload_submission_id = (int) $upload_submission_id;
	$corsivo_forms_test_created_posts[] = $upload_submission_id;
	update_post_meta($upload_submission_id, '_corsivo_forms_sender_email', $upload_test_email);
	add_post_meta($upload_submission_id, '_corsivo_forms_email_index', $upload_test_email);
	update_post_meta($upload_submission_id, '_corsivo_forms_rows', []);
	update_post_meta($upload_submission_id, '_corsivo_forms_form_title', 'Upload storage test');
	update_post_meta($upload_submission_id, '_corsivo_forms_submitted_at_gmt', current_time('mysql', true));

	$stored_upload_result = corsivo_forms_store_submission_uploads($upload_submission_id, $prepared_upload['prepared']);
	corsivo_forms_test_check(
		!is_wp_error($stored_upload_result) && count($stored_upload_result['files'] ?? []) === 1,
		'storage CLI persiste il file sulla submission'
	);
	$stored_upload_meta = get_post_meta($upload_submission_id, corsivo_forms_upload_meta_key(), true);
	corsivo_forms_test_check(
		is_array($stored_upload_meta)
			&& ($stored_upload_meta[0]['original_name'] ?? '') === 'pixel-test.png'
			&& ($stored_upload_meta[0]['mime'] ?? '') === 'image/png'
			&& !array_key_exists('tmp_name', $stored_upload_meta[0] ?? []),
		'meta upload resta plain e non persiste percorsi temporanei'
	);

	$stored_upload = $stored_upload_result['files'][0];
	$resolved_upload = corsivo_forms_resolve_submission_upload($upload_submission_id, $stored_upload['id']);
	corsivo_forms_test_check(
		!is_wp_error($resolved_upload)
			&& is_file($resolved_upload['path'])
			&& hash_equals($stored_upload['sha256'], (string) hash_file('sha256', $resolved_upload['path'])),
		'resolver verifica percorso e hash del file'
	);
	$download_url = corsivo_forms_submission_upload_download_url($upload_submission_id, $stored_upload['id']);
	corsivo_forms_test_check(
		str_contains($download_url, 'admin-post.php')
			&& str_contains($download_url, 'action=corsivo_forms_download_upload')
			&& str_contains($download_url, '_wpnonce='),
		'URL download usa endpoint amministrativo e nonce'
	);

	$upload_privacy_export = corsivo_forms_privacy_exporter($upload_test_email, 1);
	$upload_privacy_values = [];
	foreach ($upload_privacy_export['data'] as $upload_privacy_item) {
		$upload_privacy_values = array_merge($upload_privacy_values, array_column($upload_privacy_item['data'], 'value'));
	}
	$upload_privacy_text = implode("\n", $upload_privacy_values);
	corsivo_forms_test_check(
		str_contains($upload_privacy_text, 'pixel-test.png')
			&& str_contains($upload_privacy_text, 'image/png')
			&& str_contains($upload_privacy_text, size_format($stored_upload['size'])),
		'export privacy mostra nome, MIME e dimensione del file'
	);
	corsivo_forms_test_check(
		!str_contains($upload_privacy_text, $stored_upload['sha256'])
			&& !str_contains($upload_privacy_text, $resolved_upload['path']),
		'export privacy non espone hash o percorso privato'
	);

	$stored_upload_path = $resolved_upload['path'];
	$stored_upload_directory = corsivo_forms_existing_submission_upload_directory($upload_submission_id);
	$deleted_upload_submission = wp_delete_post($upload_submission_id, true);
	corsivo_forms_test_check(
		$deleted_upload_submission instanceof WP_Post
			&& !file_exists($stored_upload_path)
			&& is_string($stored_upload_directory)
			&& !is_dir($stored_upload_directory),
		'eliminazione submission rimuove file e directory privata'
	);

	$token_definition = [
		'version'    => 1,
		'issued_at'  => time(),
		'form_id'    => hash('md5', 'corsivo-forms-token-test'),
		'settings'   => corsivo_forms_normalize_form_settings([
			'form_title'      => 'Token test',
			'recipients'      => 'token@example.com',
			'subject'         => 'Token',
			'success_message' => 'OK',
			'store'           => true,
		]),
		'fields'     => $definition_fields,
		'page_id'    => 0,
		'page_title' => '',
		'page_url'   => '',
		'language'   => 'it_it',
		'is_public'  => true,
		'has_submit' => true,
		'schema_truncated' => false,
		'too_many_fields'  => false,
		'source_mode' => 'rendered',
	];

	corsivo_forms_test_check(function_exists('openssl_encrypt') && function_exists('openssl_decrypt'), 'OpenSSL disponibile per i token');
	$token = corsivo_forms_create_form_token($token_definition);
	corsivo_forms_test_check(is_string($token) && str_starts_with($token, 'v1.'), 'creazione token form');
	$decoded_token = corsivo_forms_read_form_token($token);
	corsivo_forms_test_check(!is_wp_error($decoded_token) && $decoded_token['form_id'] === $token_definition['form_id'], 'lettura token form');
	$missing_source_definition = $token_definition;
	unset($missing_source_definition['source_mode']);
	$missing_source_token = corsivo_forms_create_form_token($missing_source_definition);
	$missing_source_result = corsivo_forms_read_form_token($missing_source_token);
	corsivo_forms_test_check(is_wp_error($missing_source_result) && $missing_source_result->get_error_code() === 'invalid_form_token', 'token senza modalità sorgente rifiutato');

	$first_token_character = $token[3];
	$tampered_token = substr($token, 0, 3) . ($first_token_character === 'A' ? 'B' : 'A') . substr($token, 4);
	$tampered_result = corsivo_forms_read_form_token($tampered_token);
	corsivo_forms_test_check(is_wp_error($tampered_result) && $tampered_result->get_error_code() === 'invalid_form_token', 'token alterato rifiutato');

	$corsivo_forms_test_ttl_filter = static fn() => HOUR_IN_SECONDS;
	add_filter('corsivo_forms_form_token_ttl', $corsivo_forms_test_ttl_filter, PHP_INT_MAX);
	$expired_definition = $token_definition;
	$expired_definition['issued_at'] = time() - HOUR_IN_SECONDS - 60;
	$expired_token = corsivo_forms_create_form_token($expired_definition);
	$expired_result = corsivo_forms_read_form_token($expired_token);
	corsivo_forms_test_check(is_wp_error($expired_result) && $expired_result->get_error_code() === 'expired_form_token', 'token scaduto rifiutato');
	remove_filter('corsivo_forms_form_token_ttl', $corsivo_forms_test_ttl_filter, PHP_INT_MAX);
	$corsivo_forms_test_ttl_filter = null;

	$corsivo_forms_test_admin_email_filter = static fn() => 'forms-admin@example.com';
	add_filter('pre_option_admin_email', $corsivo_forms_test_admin_email_filter, PHP_INT_MAX);
	$fallback_recipients = corsivo_forms_notification_recipients(['recipients' => '']);
	corsivo_forms_test_check($fallback_recipients === ['forms-admin@example.com'], 'destinatari vuoti usano admin email');
	remove_filter('pre_option_admin_email', $corsivo_forms_test_admin_email_filter, PHP_INT_MAX);
	$corsivo_forms_test_admin_email_filter = null;

	$invalid_recipients = corsivo_forms_notification_recipients(['recipients' => 'non-valido, ancora-non-valido']);
	corsivo_forms_test_check(is_wp_error($invalid_recipients) && $invalid_recipients->get_error_code() === 'invalid_recipients', 'destinatari configurati invalidi restituiscono WP_Error');
	$mixed_recipients = corsivo_forms_notification_recipients(['recipients' => 'ok@example.com, typo@example']);
	corsivo_forms_test_check(is_wp_error($mixed_recipients) && $mixed_recipients->get_error_code() === 'invalid_recipients', 'un destinatario errato non viene ignorato silenziosamente');
	$too_many_recipients = corsivo_forms_notification_recipients(['recipients' => implode(',', array_fill(0, 11, 'many@example.com'))]);
	corsivo_forms_test_check(is_wp_error($too_many_recipients) && $too_many_recipients->get_error_code() === 'too_many_recipients', 'oltre dieci destinatari la configurazione viene rifiutata');
	$deduplicated_recipients = corsivo_forms_notification_recipients(['recipients' => 'One@example.com, one@example.com, two@example.com']);
	$lowercase_recipients = array_map('strtolower', $deduplicated_recipients);
	sort($lowercase_recipients);
	corsivo_forms_test_check($lowercase_recipients === ['one@example.com', 'two@example.com'], 'destinatari duplicati rimossi case-insensitive');

	$merged_subject = corsivo_forms_notification_subject([
		'subject' => 'Ciao {name} - {unknown}',
		'rows'    => [['name' => 'name', 'display' => 'Mario']],
	]);
	corsivo_forms_test_check($merged_subject === 'Ciao Mario - {unknown}', 'merge campi nell’oggetto email');
	$notification_context = corsivo_forms_notification_context([
		'form_title'  => 'Contatti',
		'page_title'  => 'Pagina',
		'page_url'    => 'https://example.com',
		'submitted_at' => '11/07/2026 12:00:00',
		'sender_name' => '',
		'sender_email' => '',
		'rows'        => [],
	], 'Richiesta R&D');
	corsivo_forms_test_check($notification_context['notification_subject'] === 'Richiesta R&D', 'oggetto Corsivo Mail non contiene entità HTML');
	$unsafe_subject = corsivo_forms_mail_merge_subject('Test &quot; onmouseover&equals;alert(1) <tag>');
	corsivo_forms_test_check(!preg_match('/["\'`<>=]/', $unsafe_subject), 'oggetto merge non può uscire da un attributo HTML');
	$punctuated_merge = "O'Connor, 10:30 / (ok)";
	corsivo_forms_test_check(corsivo_forms_mail_merge_text($punctuated_merge) === esc_html($punctuated_merge), 'merge testuale conserva la punteggiatura legittima');
	$obfuscated_protocol = corsivo_forms_mail_merge_text('jav&#x61;script:alert(1)');
	corsivo_forms_test_check(!str_contains(strtolower(html_entity_decode($obfuscated_protocol, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'javascript:'), 'merge testuale rimuove protocolli pericolosi offuscati');

	$activity_install = corsivo_forms_activity_install();
	corsivo_forms_test_check($activity_install === true, 'install storage completa la verifica fisica');
	$activity_descriptor = corsivo_forms_activity_schema_describe();
	corsivo_forms_test_check(
		is_array($activity_descriptor) && corsivo_forms_activity_schema_descriptor_is_current($activity_descriptor),
		'descriptor fisico completo corrisponde al blueprint'
	);
	$activity_marker = get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, null);
	corsivo_forms_test_check(
		is_array($activity_marker)
			&& ($activity_marker['marker_format'] ?? null) === CORSIVO_FORMS_ACTIVITY_SCHEMA_MARKER_FORMAT
			&& !array_key_exists('payload', $activity_marker)
			&& !array_key_exists('signature', $activity_marker),
		'marker storage è plain e usa il formato corrente'
	);
	corsivo_forms_test_check(
		($activity_marker['physical_fingerprint'] ?? '') === corsivo_forms_activity_schema_physical_fingerprint($activity_descriptor),
		'marker conserva il fingerprint del descriptor verificato'
	);
	$fresh_intent = corsivo_forms_activity_schema_fresh_intent();
	corsivo_forms_test_check(
		is_array($fresh_intent)
			&& preg_match('/^[a-f0-9-]{36}$/D', (string) ($fresh_intent['operation_id'] ?? ''))
			&& preg_match('/^[a-f0-9]{64}$/D', (string) ($fresh_intent['token'] ?? ''))
			&& !array_key_exists('signature', $fresh_intent),
		'intent plain conserva operation id e token di fencing'
	);
	$stale_marker = $activity_marker;
	$stale_marker['verified_at'] = '2000-01-01 00:00:00';
	update_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, $stale_marker, false);
	corsivo_forms_test_check(corsivo_forms_activity_schema_is_ready(), 'marker scaduto resta valido sul fast gate');
	corsivo_forms_test_check(corsivo_forms_activity_schema_verification_due(), 'marker scaduto pianifica una verifica fisica');
	update_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, $activity_marker, false);

	update_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, '0', false);
	$invalid_zero_bytes = serialize(get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, null));
	corsivo_forms_test_check(
		!corsivo_forms_activity_schema_is_ready()
			&& corsivo_forms_activity_schema_verification_due()
			&& serialize(get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, null)) === $invalid_zero_bytes,
		'marker senza formato chiude il fast gate senza essere riscritto'
	);
	$invalid_zero_result = corsivo_forms_activity_install();
	corsivo_forms_test_check(
		is_wp_error($invalid_zero_result)
			&& $invalid_zero_result->get_error_code() === 'corsivo_forms_activity_schema_fresh_state_conflict'
			&& serialize(get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, null)) === $invalid_zero_bytes,
		'verifica fisica rifiuta il marker senza formato'
	);

	$invalid_marker = [
		'payload'   => $activity_marker,
		'signature' => str_repeat('0', 64),
	];
	update_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, $invalid_marker, false);
	$invalid_marker_bytes = serialize($invalid_marker);
	corsivo_forms_test_check(!corsivo_forms_activity_schema_is_ready(), 'envelope senza formato chiude il fast gate');
	$invalid_marker_result = corsivo_forms_activity_install();
	corsivo_forms_test_check(
		is_wp_error($invalid_marker_result)
			&& $invalid_marker_result->get_error_code() === 'corsivo_forms_activity_schema_fresh_state_conflict'
			&& serialize(get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, null)) === $invalid_marker_bytes,
		'verifica fisica rifiuta un envelope senza formato'
	);
	update_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, $activity_marker, false);
	$repaired_marker = $activity_marker;

	$mismatched_marker = $repaired_marker;
	$mismatched_marker['source_fingerprint'] = str_repeat('0', 64);
	update_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, $mismatched_marker, false);
	corsivo_forms_test_check(!corsivo_forms_activity_schema_is_ready(), 'source fingerprint diverso chiude il fast gate');
	$source_repair = corsivo_forms_activity_install();
	corsivo_forms_test_check($source_repair === true && corsivo_forms_activity_schema_is_ready(), 'update verifier ripristina il marker dopo controllo fisico');
	$current_marker = get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, null);
	$future_state_cases = [
		[
			'error'  => 'corsivo_forms_activity_schema_future_marker_format',
			'option' => CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION,
			'value'  => array_merge($current_marker, ['marker_format' => (string) (CORSIVO_FORMS_ACTIVITY_SCHEMA_MARKER_FORMAT + 1)]),
		],
		[
			'error'  => 'corsivo_forms_activity_schema_future_intent_format',
			'option' => CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION,
			'value'  => ['intent_format' => CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_FORMAT + 1],
		],
		[
			'error'  => 'corsivo_forms_activity_schema_future_failure_format',
			'option' => CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION,
			'value'  => ['failure_format' => CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_FORMAT + 1],
		],
	];
	foreach ($future_state_cases as $future_state_case) {
		$previous_state = get_option($future_state_case['option'], null);
		update_option($future_state_case['option'], $future_state_case['value'], false);
		$future_result = corsivo_forms_activity_install();
		corsivo_forms_test_check(
			is_wp_error($future_result)
				&& $future_result->get_error_code() === $future_state_case['error']
				&& get_option($future_state_case['option'], null) === $future_state_case['value'],
			'formato storage futuro resta intatto e chiude il verifier'
		);
		if ($previous_state === null) {
			delete_option($future_state_case['option']);
		} else {
			update_option($future_state_case['option'], $previous_state, false);
		}
	}
	$future_state_repair = corsivo_forms_activity_install();
	corsivo_forms_test_check($future_state_repair === true && corsivo_forms_activity_schema_is_ready(), 'ripristino test formati futuri riattesta lo storage');

	$storage_option_names = [
		CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION,
		CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION,
		CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION,
	];
	$storage_snapshot = static function() use ($storage_option_names) {
		$snapshot = [];
		foreach ($storage_option_names as $option_name) {
			$snapshot[$option_name] = corsivo_forms_activity_schema_option_record($option_name);
		}
		return $snapshot;
	};
	$restore_storage_snapshot = static function($snapshot) {
		foreach ($snapshot as $option_name => $record) {
			if ($record['exists']) {
				update_option($option_name, $record['value'], false);
			} else {
				delete_option($option_name);
			}
		}
	};

	$null_artifacts_rejected = true;
	foreach ([CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION, CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION] as $null_option) {
		$baseline = $storage_snapshot();
		delete_option($null_option);
		add_option($null_option, null, '', false);
		$before = corsivo_forms_activity_schema_option_record($null_option);
		$null_result = corsivo_forms_activity_install();
		$null_artifacts_rejected = $null_artifacts_rejected
			&& !corsivo_forms_activity_schema_is_ready()
			&& is_wp_error($null_result)
			&& $null_result->get_error_code() === 'corsivo_forms_activity_schema_fresh_state_conflict'
			&& corsivo_forms_activity_schema_option_record($null_option) === $before;
		$restore_storage_snapshot($baseline);
	}
	corsivo_forms_test_check(
		$null_artifacts_rejected && corsivo_forms_activity_install() === true,
		'intent e failure null persistiti chiudono senza essere riscritti'
	);

	$corrupt_cases = [
		CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION => [
			'intent_format' => CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_FORMAT,
			'corrupt' => true,
		],
		CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION => [
			'failure_format' => CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_FORMAT,
			'corrupt' => true,
		],
		CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION => [
			'marker_format' => CORSIVO_FORMS_ACTIVITY_SCHEMA_MARKER_FORMAT,
			'corrupt' => true,
		],
	];
	$corrupt_exact_rejected = true;
	foreach ($corrupt_cases as $corrupt_option => $corrupt_artifact) {
		$baseline = $storage_snapshot();
		update_option($corrupt_option, $corrupt_artifact, false);
		$before = corsivo_forms_activity_schema_option_record($corrupt_option);
		$corrupt_result = corsivo_forms_activity_install();
		$corrupt_exact_rejected = $corrupt_exact_rejected
			&& is_wp_error($corrupt_result)
			&& $corrupt_result->get_error_code() === 'corsivo_forms_activity_schema_fresh_state_conflict'
			&& corsivo_forms_activity_schema_option_record($corrupt_option) === $before;
		$restore_storage_snapshot($baseline);
	}
	corsivo_forms_test_check(
		$corrupt_exact_rejected && corsivo_forms_activity_install() === true,
		'schema esatto non adotta artefatti current-format corrotti'
	);

	$fence_baseline = $storage_snapshot();
	$expected_intent = corsivo_forms_activity_schema_fresh_intent();
	$other_intent = corsivo_forms_activity_schema_fresh_intent();
	delete_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION);
	add_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION, $other_intent, '', false);
	$ddl_queries = [];
	$ddl_observer = static function($query) use (&$ddl_queries) {
		if (preg_match('/^\s*(?:CREATE|ALTER|DROP)\s+TABLE\b/i', (string) $query)) {
			$ddl_queries[] = $query;
		}
		return $query;
	};
	add_filter('query', $ddl_observer);
	$create_fence_result = corsivo_forms_activity_schema_create_current($expected_intent);
	remove_filter('query', $ddl_observer);
	$restore_storage_snapshot($fence_baseline);
	delete_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION);
	add_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION, $expected_intent, '', false);
	$verified_record = corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION);
	$intent_record = corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION);
	$failure_record = corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION);
	update_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION, $other_intent, false);
	$finalize_fence_result = corsivo_forms_activity_schema_finalize(
		corsivo_forms_activity_schema_describe(),
		$verified_record,
		$intent_record,
		$failure_record
	);
	$winning_intent_preserved = get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION, null) === $other_intent;
	$restore_storage_snapshot($fence_baseline);
	corsivo_forms_test_check(
		is_wp_error($create_fence_result)
			&& $create_fence_result->get_error_code() === 'corsivo_forms_activity_schema_intent_fence_lost'
			&& $ddl_queries === []
			&& is_wp_error($finalize_fence_result)
			&& $finalize_fence_result->get_error_code() === 'corsivo_forms_activity_schema_finalize_fence_lost'
			&& $winning_intent_preserved,
		'creazione e finalizzazione sono fenced sull’intent atteso'
	);

	$empty_baseline = $storage_snapshot();
	$original_prefix = $wpdb->prefix;
	$wpdb->prefix = $original_prefix . 'corsivo_forms_empty_' . strtolower(wp_generate_password(8, false, false)) . '_';
	$empty_corrupt_rejected = true;
	$empty_ddl = [];
	$empty_ddl_observer = static function($query) use (&$empty_ddl) {
		if (preg_match('/^\s*(?:CREATE|ALTER|DROP)\s+TABLE\b/i', (string) $query)) {
			$empty_ddl[] = $query;
		}
		return $query;
	};
	add_filter('query', $empty_ddl_observer);
	foreach ($corrupt_cases as $corrupt_option => $corrupt_artifact) {
		foreach ($storage_option_names as $storage_option_name) {
			delete_option($storage_option_name);
		}
		add_option($corrupt_option, $corrupt_artifact, '', false);
		$before = corsivo_forms_activity_schema_option_record($corrupt_option);
		$empty_result = corsivo_forms_activity_install();
		$empty_corrupt_rejected = $empty_corrupt_rejected
			&& is_wp_error($empty_result)
			&& $empty_result->get_error_code() === 'corsivo_forms_activity_schema_fresh_state_conflict'
			&& corsivo_forms_activity_schema_option_record($corrupt_option) === $before;
	}
	remove_filter('query', $empty_ddl_observer);
	$wpdb->prefix = $original_prefix;
	$restore_storage_snapshot($empty_baseline);
	corsivo_forms_test_check(
		$empty_corrupt_rejected && $empty_ddl === [] && corsivo_forms_activity_install() === true,
		'namespace vuoto con artefatti corrotti non esegue DDL e li preserva'
	);

	wp_clear_scheduled_hook('corsivo_forms_activity_schema_verify');
	$future_update_marker = get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, null);
	$future_update_marker['marker_format'] = (string) (CORSIVO_FORMS_ACTIVITY_SCHEMA_MARKER_FORMAT + 1);
	update_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, $future_update_marker, false);
	$future_update_before = serialize(corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION));
	corsivo_forms_activity_schema_verify_after_update(null, [
		'action' => 'update',
		'type'   => 'plugin',
		'plugins' => [plugin_basename(CORSIVO_FORMS_FILE)],
	]);
	$future_update_preserved = serialize(corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION)) === $future_update_before;
	update_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, $current_marker, false);
	wp_clear_scheduled_hook('corsivo_forms_activity_schema_verify');
	corsivo_forms_test_check($future_update_preserved, 'update plugin preserva byte-identico un marker futuro');

	wp_clear_scheduled_hook('corsivo_forms_activity_schema_verify');
	$marker_before_update_hook = get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, null);
	$marker_before_update_hook['verified_at'] = '2000-01-01 00:00:00';
	update_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, $marker_before_update_hook, false);
	$update_hook_started_at = time();
	corsivo_forms_activity_schema_verify_after_update(null, [
		'action' => 'update',
		'type'   => 'plugin',
		'plugin' => plugin_basename(CORSIVO_FORMS_FILE),
	]);
	$update_hook_scheduled_at = wp_next_scheduled('corsivo_forms_activity_schema_verify');
	corsivo_forms_test_check(
		$update_hook_scheduled_at !== false
			&& $update_hook_scheduled_at <= $update_hook_started_at + 2
			&& !corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION)['exists']
			&& !corsivo_forms_activity_schema_is_ready(),
		'update plugin pianifica subito il processo successivo e chiude il marker precedente'
	);
	wp_clear_scheduled_hook('corsivo_forms_activity_schema_verify');
	$update_hook_repair = corsivo_forms_activity_install();
	corsivo_forms_test_check($update_hook_repair === true && corsivo_forms_activity_schema_is_ready(), 'verifier post-update riattesta lo storage nel processo successivo');

	$lock_order = [];
	$query_observer = static function($query) use (&$lock_order) {
		if (str_contains((string) $query, 'SELECT RELEASE_LOCK')) {
			$lock_order[] = 'lock_release';
		}

		return $query;
	};
	$failure_observer = static function($option_name) use (&$lock_order) {
		if ($option_name === CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION) {
			$lock_order[] = 'failure_write';
		}
	};
	add_filter('query', $query_observer);
	add_action('added_option', $failure_observer);
	add_action('updated_option', $failure_observer);
	try {
		$lock_failure_result = corsivo_forms_activity_schema_with_lock(static function() {
			return new WP_Error('corsivo_forms_activity_schema_order_probe', 'Storage ordering probe.');
		});
	} finally {
		remove_filter('query', $query_observer);
		remove_action('added_option', $failure_observer);
		remove_action('updated_option', $failure_observer);
	}
	$failure_write_position = array_search('failure_write', $lock_order, true);
	$lock_release_position = array_search('lock_release', $lock_order, true);
	corsivo_forms_test_check(
		is_wp_error($lock_failure_result)
			&& $failure_write_position !== false
			&& $lock_release_position !== false
			&& $failure_write_position < $lock_release_position,
		'failure storage persistita prima di RELEASE_LOCK'
	);
	delete_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION);

	$tampered_columns = $activity_descriptor;
	$tampered_columns['columns'][1]['type'] = 'varchar(65)';
	corsivo_forms_test_check(!corsivo_forms_activity_schema_descriptor_is_current($tampered_columns), 'verifica fisica rifiuta un tipo colonna diverso');
	$tampered_indexes = $activity_descriptor;
	$tampered_indexes['indexes']['request_id']['unique'] = false;
	corsivo_forms_test_check(!corsivo_forms_activity_schema_descriptor_is_current($tampered_indexes), 'verifica fisica rifiuta la semantica indice diversa');
	$tampered_create = $activity_descriptor;
	$tampered_create['create'] .= ' row_format=dynamic';
	corsivo_forms_test_check(!corsivo_forms_activity_schema_descriptor_is_current($tampered_create), 'verifica fisica rifiuta opzioni CREATE estranee');

	$redacted_activity = corsivo_forms_activity_redact_message('Errore user@example.com in C:\\Users\\Salvo\\secret.txt');
	corsivo_forms_test_check(!str_contains($redacted_activity, 'user@example.com') && !str_contains($redacted_activity, 'secret.txt') && str_contains($redacted_activity, '[email]') && str_contains($redacted_activity, '[path]'), 'registro attività redige email e percorsi locali');
	$exception_message = 'Token segreto-123 per Mario user@example.com';
	$exception_summary = corsivo_forms_activity_exception_summary(new RuntimeException($exception_message, 17));
	corsivo_forms_test_check(str_contains($exception_summary, 'RuntimeException (17)') && str_contains($exception_summary, substr(hash_hmac('sha256', $exception_message, wp_salt('nonce')), 0, 12)), 'riepilogo eccezione conserva classe, codice e fingerprint');
	corsivo_forms_test_check(!str_contains($exception_summary, 'segreto-123') && !str_contains($exception_summary, 'Mario') && !str_contains($exception_summary, 'user@example.com'), 'riepilogo eccezione non persiste il messaggio arbitrario');
	$original_audit_settings = corsivo_forms_get_settings();
	$enabled_audit_settings  = array_merge($original_audit_settings, ['log_enabled' => true, 'log_retention_days' => 90]);
	$disabled_audit_settings = array_merge($enabled_audit_settings, ['log_enabled' => false, 'log_retention_days' => 30]);
	update_option('corsivo_forms_settings', $disabled_audit_settings, false);
	$suppressed_settings_activity = corsivo_forms_activity_log_settings_update($enabled_audit_settings, $disabled_audit_settings);
	$GLOBALS['corsivo_forms_suppress_activity_log'] = false;
	$settings_activity = corsivo_forms_activity_log_settings_update($enabled_audit_settings, $disabled_audit_settings);
	$disabled_settings_activity = corsivo_forms_activity_log_settings_update(
		$disabled_audit_settings,
		array_merge($disabled_audit_settings, ['log_retention_days' => 45])
	);
	$GLOBALS['corsivo_forms_suppress_activity_log'] = true;
	update_option('corsivo_forms_settings', $enabled_audit_settings, false);
	$GLOBALS['corsivo_forms_suppress_activity_log'] = false;
	$enabled_settings_activity = corsivo_forms_activity_log_settings_update($disabled_audit_settings, $enabled_audit_settings);
	$GLOBALS['corsivo_forms_suppress_activity_log'] = true;
	update_option('corsivo_forms_settings', $original_audit_settings, false);
	foreach ([$settings_activity, $enabled_settings_activity] as $settings_test_activity) {
		if (is_array($settings_test_activity) && !empty($settings_test_activity['request_id'])) {
			$corsivo_forms_test_activity_ids[] = $settings_test_activity['request_id'];
		}
	}
	corsivo_forms_test_check($suppressed_settings_activity === null, 'soppressione blocca anche l’evento finale di disattivazione');
	corsivo_forms_test_check(is_array($settings_activity) && $settings_activity['event'] === 'settings_updated', 'disabilitazione registro lascia un evento finale');
	corsivo_forms_test_check(str_contains($settings_activity['message'], 'log_enabled: true → false') && str_contains($settings_activity['message'], 'log_retention_days: 90 → 30'), 'evento impostazioni contiene il diff normalizzato');
	corsivo_forms_test_check($disabled_settings_activity === null, 'registro disattivato non traccia altre modifiche');
	corsivo_forms_test_check(is_array($enabled_settings_activity) && str_contains($enabled_settings_activity['message'], 'log_enabled: false → true'), 'riattivazione registro viene tracciata');

	$previous_log_suppression = $GLOBALS['corsivo_forms_suppress_activity_log'];
	$activity_transaction_started = $wpdb->query('START TRANSACTION') !== false;
	$activity_purge_verified = false;
	$activity_clear_verified = false;
	$activity_rollback_verified = false;
	corsivo_forms_test_check($activity_transaction_started, 'transazione test registro attività avviata');
	if ($activity_transaction_started) {
		try {
			$activity_test_marker = hash('md5', 'corsivo-forms-activity-isolation:' . wp_generate_uuid4());
			$old_activity_request_id = wp_generate_uuid4();
			$recent_activity_request_id = wp_generate_uuid4();
			$processing_activity_request_id = wp_generate_uuid4();
			corsivo_forms_activity_claim_request($old_activity_request_id, [
				'event'   => 'test',
				'status'  => 'success',
				'form_id' => $activity_test_marker,
			]);
			corsivo_forms_activity_claim_request($recent_activity_request_id, [
				'event'   => 'test',
				'status'  => 'success',
				'form_id' => $activity_test_marker,
			]);
			$wpdb->update(
				corsivo_forms_activity_table_name(),
				[
					'created_at_gmt' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS),
					'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
				],
				['request_id' => $old_activity_request_id],
				['%s', '%s'],
				['%s']
			);
			$purged_activity_count = corsivo_forms_activity_purge(1);
			$activity_purge_verified = is_int($purged_activity_count)
				&& $purged_activity_count >= 1
				&& corsivo_forms_activity_get_record($old_activity_request_id) === null
				&& is_array(corsivo_forms_activity_get_record($recent_activity_request_id));
			corsivo_forms_activity_claim_request($processing_activity_request_id, [
				'event'   => 'submission',
				'status'  => 'processing',
				'form_id' => $activity_test_marker,
			]);
			$GLOBALS['corsivo_forms_suppress_activity_log'] = false;
			$cleared_activity_count = corsivo_forms_activity_clear();
			$GLOBALS['corsivo_forms_suppress_activity_log'] = true;
			$remaining_activity_rows = corsivo_forms_activity_query([
				'search'   => $activity_test_marker,
				'per_page' => 10,
			]);
			$activity_clear_verified = is_int($cleared_activity_count)
				&& $cleared_activity_count >= 1
				&& count($remaining_activity_rows) === 1
				&& ($remaining_activity_rows[0]['request_id'] ?? '') === $processing_activity_request_id;
		} finally {
			$GLOBALS['corsivo_forms_suppress_activity_log'] = $previous_log_suppression;
			$activity_rollback_verified = $wpdb->query('ROLLBACK') !== false;
		}
	}
	corsivo_forms_test_check($activity_purge_verified, 'retention attività usa created_at_gmt e non updated_at_gmt');
	corsivo_forms_test_check($activity_clear_verified, 'pulizia attività conserva il claim in elaborazione');
	corsivo_forms_test_check($activity_rollback_verified, 'test registro attività ripristinato con rollback');
	$activity_request_id = wp_generate_uuid4();
	$corsivo_forms_test_activity_ids[] = $activity_request_id;
	$activity_form_id = hash('md5', 'corsivo-forms-activity-test');
	$first_claim = corsivo_forms_activity_claim_request($activity_request_id, [
		'event'   => 'test',
		'status'  => 'processing',
		'form_id' => $activity_form_id,
	]);
	corsivo_forms_test_check(!is_wp_error($first_claim) && $first_claim['claimed'] === true, 'primo claim attività acquisito');
	$second_claim = corsivo_forms_activity_claim_request($activity_request_id, [
		'event'   => 'test',
		'status'  => 'processing',
		'form_id' => $activity_form_id,
	]);
	corsivo_forms_test_check(!is_wp_error($second_claim) && $second_claim['claimed'] === false, 'claim duplicato respinto dalla chiave unica');
	$stored_activity_response = corsivo_forms_activity_store_response(
		$activity_request_id,
		new WP_REST_Response(['sent' => true, 'message' => 'activity-ok'], 202),
		202
	);
	corsivo_forms_test_check(!is_wp_error($stored_activity_response), 'risposta attività persistita');
	$duplicate_response = corsivo_forms_activity_duplicate_response($activity_request_id);
	corsivo_forms_test_check($duplicate_response instanceof WP_REST_Response && $duplicate_response->get_status() === 202, 'risposta duplicata conserva status HTTP');
	corsivo_forms_test_check($duplicate_response->get_data() === ['sent' => true, 'message' => 'activity-ok'], 'risposta duplicata conserva body');

	$accepted_request_id = wp_generate_uuid4();
	$corsivo_forms_test_activity_ids[] = $accepted_request_id;
	corsivo_forms_activity_claim_request($accepted_request_id, [
		'event'   => 'test',
		'status'  => 'processing',
		'form_id' => $activity_form_id,
	]);
	corsivo_forms_activity_update_request($accepted_request_id, [
		'status'              => 'success',
		'notification_status' => 'accepted',
	]);
	$accepted_duplicate = corsivo_forms_activity_duplicate_response($accepted_request_id);
	corsivo_forms_test_check($accepted_duplicate instanceof WP_REST_Response && $accepted_duplicate->get_status() === 200, 'attività riuscita senza response non duplica una notifica accettata');

	$stale_request_id = wp_generate_uuid4();
	$corsivo_forms_test_activity_ids[] = $stale_request_id;
	corsivo_forms_activity_claim_request($stale_request_id, [
		'event'   => 'test',
		'status'  => 'processing',
		'form_id' => $activity_form_id,
	]);
	$wpdb->update(
		corsivo_forms_activity_table_name(),
		['updated_at_gmt' => gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS)],
		['request_id' => $stale_request_id],
		['%s'],
		['%s']
	);
	$stale_duplicate = corsivo_forms_activity_duplicate_response($stale_request_id);
	corsivo_forms_test_check(is_wp_error($stale_duplicate) && $stale_duplicate->get_error_code() === 'corsivo_forms_request_unknown', 'claim interrotto diventa ritentabile con esito esplicito');
	corsivo_forms_test_check(corsivo_forms_activity_get_record($stale_request_id)['status'] === 'unknown', 'claim interrotto marcato unknown');

	$failed_request_id = wp_generate_uuid4();
	$corsivo_forms_test_activity_ids[] = $failed_request_id;
	corsivo_forms_activity_claim_request($failed_request_id, ['event' => 'test', 'status' => 'processing', 'form_id' => $activity_form_id]);
	corsivo_forms_activity_update_request($failed_request_id, ['status' => 'failed']);
	$failed_duplicate = corsivo_forms_activity_duplicate_response($failed_request_id);
	corsivo_forms_test_check(is_wp_error($failed_duplicate) && $failed_duplicate->get_error_code() === 'submission_failed', 'attività failed senza envelope resta ritentabile');

	$stored_request_id = wp_generate_uuid4();
	$stored_at_gmt = current_time('mysql', true);
	$storage_email_suffix = substr(str_replace('-', '', wp_generate_uuid4()), 0, 10);
	$storage_email = 'storage-' . $storage_email_suffix . '@example.com';
	$secondary_email = 'secondary-' . $storage_email_suffix . '@example.com';
	$stored_submission = [
		'request_id'       => $stored_request_id,
		'form_id'          => hash('md5', 'corsivo-forms-storage-test'),
		'form_title'       => 'Storage test',
		'rows'             => [
			['name' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => $storage_email, 'display' => $storage_email],
			['name' => 'email_2', 'type' => 'email', 'label' => 'Email secondaria', 'value' => $secondary_email, 'display' => $secondary_email],
		],
		'page_id'          => 0,
		'page_title'       => 'Percorso C:\\Users\\Salvo',
		'page_url'         => 'https://example.com/storage',
		'sender_email'     => $storage_email,
		'email_addresses'  => [$storage_email, $secondary_email],
		'language'         => 'it_it',
		'consents'         => [[
			'field_name'      => 'privacy',
			'label'           => 'Consenso test',
			'policy_url'      => 'https://example.com/privacy',
			'evidence_hash'   => hash('sha256', 'consenso-test'),
			'accepted_at_gmt' => $stored_at_gmt,
		]],
		'submitted_at_gmt' => $stored_at_gmt,
	];
	$stored_submission_id = corsivo_forms_store_submission($stored_submission);
	corsivo_forms_test_check(!is_wp_error($stored_submission_id) && $stored_submission_id > 0, 'submission completa archiviata');
	$corsivo_forms_test_created_posts[] = (int) $stored_submission_id;
	corsivo_forms_test_check(get_post_status($stored_submission_id) === 'publish', 'submission pubblicata solo dopo il completamento dello storage');

	$stored_meta = [
		'_corsivo_forms_rows'                => $stored_submission['rows'],
		'_corsivo_forms_form_title'          => $stored_submission['form_title'],
		'_corsivo_forms_form_id'             => $stored_submission['form_id'],
		'_corsivo_forms_request_id'          => $stored_submission['request_id'],
		'_corsivo_forms_page_id'             => 0,
		'_corsivo_forms_page_title'          => $stored_submission['page_title'],
		'_corsivo_forms_page_url'            => $stored_submission['page_url'],
		'_corsivo_forms_sender_email'        => $stored_submission['sender_email'],
		'_corsivo_forms_submitted_at_gmt'    => $stored_at_gmt,
		'_corsivo_forms_language'            => 'it_it',
		'_corsivo_forms_consents'            => $stored_submission['consents'],
		'_corsivo_forms_notification_status' => 'pending',
	];

	foreach ($stored_meta as $meta_key => $expected_value) {
		corsivo_forms_test_check(
			corsivo_forms_meta_value_matches(get_post_meta($stored_submission_id, $meta_key, true), $expected_value),
			'meta submission completo: ' . $meta_key
		);
	}
	$stored_email_index = get_post_meta($stored_submission_id, '_corsivo_forms_email_index', false);
	sort($stored_email_index);
	$expected_email_index = [$secondary_email, $storage_email];
	sort($expected_email_index);
	corsivo_forms_test_check($stored_email_index === $expected_email_index, 'tutti i campi email sono indicizzati per la privacy');
	corsivo_forms_test_check(corsivo_forms_privacy_submission_ids($secondary_email, 10) === [(int) $stored_submission_id], 'privacy lookup trova anche il secondo campo email');
	$privacy_export = corsivo_forms_privacy_exporter($secondary_email, 1);
	$privacy_export_values = [];
	foreach ($privacy_export['data'] as $privacy_export_item) {
		$privacy_export_values = array_merge($privacy_export_values, array_column($privacy_export_item['data'], 'value'));
	}
	$privacy_export_text = implode("\n", $privacy_export_values);
	corsivo_forms_test_check(!empty($privacy_export['done']) && count($privacy_export['data']) === 1, 'export privacy trova la submission dal secondo campo email');
	corsivo_forms_test_check(str_contains($privacy_export_text, $stored_request_id) && str_contains($privacy_export_text, $stored_submission['consents'][0]['evidence_hash']) && str_contains($privacy_export_text, $stored_submission['page_url']), 'export privacy include origine, request ID ed evidenza consenso');
	$legacy_row_marker = 'legacy-row-' . wp_generate_uuid4();
	update_post_meta(
		$stored_submission_id,
		'_corsivo_forms_rows',
		array_merge($stored_submission['rows'], [['name' => $legacy_row_marker, 'value' => $legacy_row_marker]])
	);
	$legacy_privacy_export = corsivo_forms_privacy_exporter($secondary_email, 1);
	$legacy_privacy_values = [];
	foreach ($legacy_privacy_export['data'] as $legacy_privacy_item) {
		$legacy_privacy_values = array_merge($legacy_privacy_values, array_column($legacy_privacy_item['data'], 'value'));
	}
	corsivo_forms_test_check(!str_contains(implode("\n", $legacy_privacy_values), $legacy_row_marker), 'export privacy ignora righe fuori dal contratto corrente');
	update_post_meta($stored_submission_id, '_corsivo_forms_rows', $stored_submission['rows']);

	$existing_submission_max_id = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
	$old_submission_date        = gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS);
	$recent_submission_date     = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
	$retention_cutoff           = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
	$retention_cases            = [
		['post_date_gmt' => $recent_submission_date, 'submitted_at_gmt' => $old_submission_date],
		['post_date_gmt' => $old_submission_date, 'submitted_at_gmt' => $recent_submission_date],
		['post_date_gmt' => $old_submission_date, 'submitted_at_gmt' => ''],
		['post_date_gmt' => '0000-00-00 00:00:00', 'submitted_at_gmt' => ''],
	];
	$retention_case_ids = [];
	foreach ($retention_cases as $case_index => $retention_case) {
		$retention_case_id = wp_insert_post([
			'post_type'   => 'corsivo_submission',
			'post_status' => 'publish',
			'post_title'  => 'Retention case ' . $case_index,
		], true);
		corsivo_forms_test_check(!is_wp_error($retention_case_id), 'submission retention temporanea creata');
		$retention_case_id = (int) $retention_case_id;
		$retention_case_ids[] = $retention_case_id;
		$corsivo_forms_test_created_posts[] = $retention_case_id;
		$wpdb->update(
			$wpdb->posts,
			['post_date_gmt' => $retention_case['post_date_gmt']],
			['ID' => $retention_case_id],
			['%s'],
			['%d']
		);
		if ($retention_case['submitted_at_gmt'] !== '') {
			update_post_meta($retention_case_id, '_corsivo_forms_submitted_at_gmt', $retention_case['submitted_at_gmt']);
		}
		clean_post_cache($retention_case_id);
	}
	$expired_submission_ids = corsivo_forms_expired_submission_ids($existing_submission_max_id, $retention_cutoff);
	corsivo_forms_test_check(in_array($retention_case_ids[0], $expired_submission_ids, true), 'retention usa il timestamp canonico anche con post recente');
	corsivo_forms_test_check(!in_array($retention_case_ids[1], $expired_submission_ids, true), 'retention conserva il timestamp canonico recente');
	corsivo_forms_test_check(!in_array($retention_case_ids[2], $expired_submission_ids, true), 'retention ignora submission senza timestamp canonico');
	corsivo_forms_test_check(!in_array($retention_case_ids[3], $expired_submission_ids, true), 'retention conserva submission senza data valida');
	foreach ($retention_case_ids as $retention_case_id) {
		wp_delete_post($retention_case_id, true);
	}

	$partial_submission_id = wp_insert_post([
		'post_type'   => 'corsivo_submission',
		'post_status' => 'publish',
		'post_title'  => 'Partial storage test',
	], true);
	corsivo_forms_test_check(!is_wp_error($partial_submission_id), 'submission parziale temporanea creata');
	$corsivo_forms_test_created_posts[] = (int) $partial_submission_id;
	update_post_meta($partial_submission_id, '_corsivo_forms_rows', [['value' => $storage_email]]);
	corsivo_forms_test_check(corsivo_forms_discard_partial_submission($partial_submission_id), 'compensazione elimina la submission parziale');
	$partial_post_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $partial_submission_id));
	$partial_meta_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $partial_submission_id));
	corsivo_forms_test_check($partial_post_count === 0 && $partial_meta_count === 0, 'compensazione verifica anche l’assenza di metadati orfani');

	corsivo_forms_update_notification_status($stored_submission_id, [
		'status'     => 'failed',
		'total'      => 1,
		'accepted'   => 0,
		'failed'     => 1,
		'transports' => ['wp_mail' => 1],
	]);
	corsivo_forms_test_check(get_post_meta($stored_submission_id, '_corsivo_forms_notification_status', true) === 'failed', 'stato notifica failed persistito');
	corsivo_forms_update_notification_status($stored_submission_id, [
		'status'     => 'accepted',
		'total'      => 1,
		'accepted'   => 1,
		'failed'     => 0,
		'transports' => ['wp_mail' => 1],
	]);
	$notification_summary = get_post_meta($stored_submission_id, '_corsivo_forms_notification_summary', true);
	corsivo_forms_test_check(get_post_meta($stored_submission_id, '_corsivo_forms_notification_status', true) === 'accepted', 'stato notifica accepted persistito');
	corsivo_forms_test_check(($notification_summary['transports']['wp_mail'] ?? 0) === 1, 'riepilogo trasporto notifica persistito');

	$privacy_test_email = 'privacy-' . substr(wp_generate_uuid4(), 0, 8) . '@example.com';
	$privacy_test_ids   = [];
	foreach (['trattenuta', 'eliminabile'] as $privacy_test_title) {
		$privacy_test_id = wp_insert_post([
			'post_type'   => 'corsivo_submission',
			'post_status' => 'publish',
			'post_title'  => 'Privacy test ' . $privacy_test_title,
		], true);
		corsivo_forms_test_check(!is_wp_error($privacy_test_id), 'submission privacy temporanea creata');
		$privacy_test_ids[] = (int) $privacy_test_id;
		$corsivo_forms_test_created_posts[] = (int) $privacy_test_id;
		update_post_meta($privacy_test_id, '_corsivo_forms_sender_email', $privacy_test_email);
		add_post_meta($privacy_test_id, '_corsivo_forms_email_index', $privacy_test_email);
		update_post_meta($privacy_test_id, '_corsivo_forms_notification_status', 'unknown');
	}
	$protected_privacy_id = $privacy_test_ids[0];
	$corsivo_forms_test_privacy_delete_filter = static function ($delete, $post) use ($protected_privacy_id) {
		return $post instanceof WP_Post && $post->ID === $protected_privacy_id ? false : $delete;
	};
	add_filter('pre_delete_post', $corsivo_forms_test_privacy_delete_filter, 10, 2);
	$eraser_result = corsivo_forms_privacy_eraser($privacy_test_email, 1);
	remove_filter('pre_delete_post', $corsivo_forms_test_privacy_delete_filter, 10);
	$corsivo_forms_test_privacy_delete_filter = null;
	corsivo_forms_test_check($eraser_result['items_removed'] && $eraser_result['items_retained'] && $eraser_result['done'], 'eraser termina segnalando insieme eliminati e trattenuti');
	corsivo_forms_test_check(get_post($privacy_test_ids[0]) instanceof WP_Post && get_post($privacy_test_ids[1]) === null, 'eraser avanza oltre una submission non eliminabile');

	$reusable_post_id = wp_insert_post([
		'post_type'    => 'wp_block',
		'post_status'  => 'publish',
		'post_title'   => 'Corsivo Forms reusable cycle test',
		'post_content' => '',
	], true);
	corsivo_forms_test_check(!is_wp_error($reusable_post_id) && $reusable_post_id > 0, 'reusable temporaneo creato');
	$corsivo_forms_test_created_posts[] = (int) $reusable_post_id;
	$self_reference_content = '<!-- wp:block {"ref":' . (int) $reusable_post_id . '} /-->';
	wp_update_post(['ID' => $reusable_post_id, 'post_content' => $self_reference_content]);
	$cycle_started_at = microtime(true);
	$cycle_forms = corsivo_forms_collect_forms($self_reference_content);
	$cycle_elapsed = microtime(true) - $cycle_started_at;
	corsivo_forms_test_check($cycle_forms === [], 'walker form interrompe ciclo reusable');
	corsivo_forms_test_check($cycle_elapsed < 2, 'walker form ciclico termina rapidamente');
	$cycle_fields = corsivo_forms_collect_fields(['innerBlocks' => parse_blocks($self_reference_content)]);
	corsivo_forms_test_check($cycle_fields === [], 'walker campi interrompe ciclo reusable');

	$policy_page_id = wp_insert_post([
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Corsivo Forms privacy test',
		'post_content' => 'Informativa temporanea per i test.',
	], true);
	corsivo_forms_test_check(!is_wp_error($policy_page_id) && $policy_page_id > 0, 'informativa privacy temporanea pubblicata');
	$corsivo_forms_test_created_posts[] = (int) $policy_page_id;
	update_option('wp_page_for_privacy_policy', (int) $policy_page_id, false);
	$test_settings = corsivo_forms_get_settings();
	$test_settings['corsivo_mail_enabled'] = false;
	$test_settings['corsivo_privacy_enabled'] = false;
	update_option('corsivo_forms_settings', $test_settings, false);

	$form_attributes = [
		'values' => [
			'form_title'      => 'E2E test',
			'recipients'      => 'receiver@example.com',
			'subject'         => 'E2E {name}',
			'success_message' => 'Richiesta ricevuta.',
			'store'           => true,
		],
	];
	$text_attributes = ['values' => ['label' => 'Nome', 'field_key' => 'name', 'required' => true]];
	$email_attributes = ['values' => ['label' => 'Email', 'field_key' => 'email', 'required' => true]];
	$privacy_attributes = ['values' => ['label' => 'Accetto la privacy', 'link_label' => 'Informativa']];
	$submit_attributes = ['values' => ['label' => 'Invia']];
	$form_content = '<!-- wp:corsivo/form ' . wp_json_encode($form_attributes) . ' -->' . "\n"
		. '<!-- wp:corsivo/form-text ' . wp_json_encode($text_attributes) . ' /-->' . "\n"
		. '<!-- wp:corsivo/form-email ' . wp_json_encode($email_attributes) . ' /-->' . "\n"
		. '<!-- wp:corsivo/form-privacy ' . wp_json_encode($privacy_attributes) . ' /-->' . "\n"
		. '<!-- wp:corsivo/form-submit ' . wp_json_encode($submit_attributes) . ' /-->' . "\n"
		. '<!-- /wp:corsivo/form -->';
	$page_id = wp_insert_post([
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Corsivo Forms E2E ' . wp_generate_uuid4(),
		'post_content' => $form_content,
	], true);
	corsivo_forms_test_check(!is_wp_error($page_id) && $page_id > 0, 'pagina pubblica E2E creata');
	$corsivo_forms_test_created_posts[] = (int) $page_id;
	$parsed_forms = corsivo_forms_collect_forms($form_content);
	corsivo_forms_test_check(count($parsed_forms) === 1, 'form Gutenberg ritrovato nella pagina pubblica');
	$e2e_definition = corsivo_forms_form_definition($parsed_forms[0], $page_id);
	corsivo_forms_test_check($e2e_definition['is_public'] === true, 'definizione form pubblico valida');
	corsivo_forms_test_check($e2e_definition['source_mode'] === 'post', 'token legato alla definizione pubblicata');
	$e2e_token = corsivo_forms_create_form_token($e2e_definition);
	corsivo_forms_test_check($e2e_token !== '' && !is_wp_error(corsivo_forms_read_form_token($e2e_token)), 'token E2E valido');

	$changed_form_content = str_replace('receiver@example.com', 'changed@example.com', $form_content);
	wp_update_post(['ID' => $page_id, 'post_content' => wp_slash($changed_form_content)]);
	$revoked_token = corsivo_forms_read_form_token($e2e_token);
	corsivo_forms_test_check(is_wp_error($revoked_token) && $revoked_token->get_error_code() === 'form_changed', 'token revocato dopo modifica del form pubblicato');
	wp_update_post(['ID' => $page_id, 'post_content' => wp_slash($form_content)]);
	corsivo_forms_test_check(!is_wp_error(corsivo_forms_read_form_token($e2e_token)), 'token torna coerente dopo ripristino della definizione');

	$template_source_id = wp_insert_post([
		'post_type'    => 'wp_block',
		'post_status'  => 'publish',
		'post_title'   => 'Corsivo Forms template source test',
		'post_content' => $form_content,
	], true);
	corsivo_forms_test_check(!is_wp_error($template_source_id) && $template_source_id > 0, 'sorgente template temporanea creata');
	$corsivo_forms_test_created_posts[] = (int) $template_source_id;
	$template_definition = $e2e_definition;
	$template_definition['source_mode'] = 'template';
	$template_definition['source_chain'] = [[
		'kind' => 'wp_block',
		'id'   => (int) $template_source_id,
		'hash' => hash('sha256', $form_content),
	]];
	$template_token = corsivo_forms_create_form_token($template_definition);
	corsivo_forms_test_check(!is_wp_error(corsivo_forms_read_form_token($template_token)), 'token template valido sulla sorgente esatta');
	wp_update_post(['ID' => $template_source_id, 'post_content' => wp_slash($changed_form_content)]);
	$template_changed = corsivo_forms_read_form_token($template_token);
	corsivo_forms_test_check(is_wp_error($template_changed) && $template_changed->get_error_code() === 'form_changed', 'token template revocato quando cambia la sorgente esatta');

	$orphan_content = '<!-- wp:corsivo/form-text ' . wp_json_encode($text_attributes) . ' /-->' . "\n";
	$rendered_forms = do_blocks($orphan_content . $form_content);
	$form_position  = strpos($rendered_forms, '<form');
	corsivo_forms_test_check($form_position !== false, 'render dinamico produce il form');
	$form_markup = $form_position !== false ? substr($rendered_forms, $form_position) : '';
	corsivo_forms_test_check(str_contains($form_markup, 'name="name"'), 'campo orfano non contamina i nomi del form successivo');
	corsivo_forms_test_check(
		str_contains($form_markup, 'enctype="multipart/form-data"')
			&& str_contains($form_markup, 'type="button"')
			&& str_contains($form_markup, 'corsivo-form-form__js-required'),
		'form senza file conserva render, fallback JavaScript ed enctype multipart'
	);

	foreach (['pre_wp_mail', 'corsivo_forms_submission', 'corsivo_forms_client_ip', 'corsivo_forms_rate_limit', 'corsivo_forms_rate_window', 'corsivo_forms_validation_rate_limit', 'corsivo_forms_validation_rate_window', 'corsivo_forms_preflight_rate_limit', 'corsivo_forms_preflight_rate_window'] as $isolated_hook) {
		corsivo_forms_test_suspend_hook($isolated_hook);
	}

	$mail_calls = 0;
	$pre_mail_filter = static function ($short, $attributes) use (&$mail_calls) {
		$mail_calls++;
		return true;
	};
	add_filter('pre_wp_mail', $pre_mail_filter, 10, 2);
	add_filter('corsivo_forms_client_ip', static fn() => '203.0.113.77');
	add_filter('corsivo_forms_rate_limit', static fn() => 100);
	add_filter('corsivo_forms_rate_window', static fn() => MINUTE_IN_SECONDS);
	add_filter('corsivo_forms_validation_rate_limit', static fn() => 100);
	add_filter('corsivo_forms_validation_rate_window', static fn() => MINUTE_IN_SECONDS);
	add_filter('corsivo_forms_preflight_rate_limit', static fn() => 100);
	add_filter('corsivo_forms_preflight_rate_window', static fn() => MINUTE_IN_SECONDS);
	$rate_scope = hash_hmac('sha256', '203.0.113.77|form|' . $e2e_definition['form_id'], wp_salt('nonce'));
	$rate_transient = 'corsivo_forms_rate_' . $rate_scope;
	$rate_lock = 'corsivo_forms_rate_lock_' . $rate_scope;
	$preflight_scope = hash_hmac('sha256', '203.0.113.77|preflight', wp_salt('nonce'));
	$preflight_transient = 'corsivo_forms_rate_' . $preflight_scope;
	$preflight_lock = 'corsivo_forms_rate_lock_' . $preflight_scope;
	$validation_scope = hash_hmac('sha256', '203.0.113.77|validation|' . $e2e_definition['form_id'], wp_salt('nonce'));
	$validation_transient = 'corsivo_forms_rate_' . $validation_scope;
	$validation_lock = 'corsivo_forms_rate_lock_' . $validation_scope;
	$corsivo_forms_test_rate_keys = [$rate_transient, $rate_lock, $preflight_transient, $preflight_lock, $validation_transient, $validation_lock];
	delete_transient($rate_transient);
	delete_option($rate_lock);
	delete_transient($preflight_transient);
	delete_option($preflight_lock);
	delete_transient($validation_transient);
	delete_option($validation_lock);

	$rest_server = rest_get_server();
	$rest_routes = $rest_server->get_routes();
	corsivo_forms_test_check(isset($rest_routes['/corsivo/v1/forms/submit']), 'route REST submit registrata');
	corsivo_forms_test_check(!empty($rest_routes['/corsivo/v1/forms/submit'][0]['args']['token']['required']), 'route REST richiede il token autenticato');
	$oversize_request = new WP_REST_Request('POST', '/corsivo/v1/forms/submit');
	$oversize_request->set_header('content-length', (string) (CORSIVO_FORMS_MAX_BODY_BYTES + 1));
	$oversize_guard = corsivo_forms_guard_submit_body(null, $rest_server, $oversize_request);
	corsivo_forms_test_check(is_wp_error($oversize_guard) && $oversize_guard->get_error_code() === 'request_too_large', 'body oversize bloccato prima del parsing JSON');
	$multipart_request = new WP_REST_Request('POST', '/corsivo/v1/forms/submit');
	$multipart_request->set_header('content-type', 'multipart/form-data; boundary=corsivo-test');
	$multipart_request->set_header('content-length', (string) CORSIVO_FORMS_MAX_MULTIPART_BODY_BYTES);
	corsivo_forms_test_check(corsivo_forms_request_body_limit($multipart_request) === CORSIVO_FORMS_MAX_MULTIPART_BODY_BYTES, 'multipart usa il limite body dedicato');
	corsivo_forms_test_check(corsivo_forms_guard_submit_body(null, $rest_server, $multipart_request) === null, 'multipart entro il limite supera il pre-dispatch');
	$multipart_request->set_header('content-length', (string) (CORSIVO_FORMS_MAX_MULTIPART_BODY_BYTES + 1));
	$multipart_guard = corsivo_forms_guard_submit_body(null, $rest_server, $multipart_request);
	corsivo_forms_test_check(is_wp_error($multipart_guard) && $multipart_guard->get_error_code() === 'request_too_large', 'multipart oltre il limite viene bloccato');


	$e2e_request_id = wp_generate_uuid4();
	$corsivo_forms_test_activity_ids[] = $e2e_request_id;
	$valid_payload = [
		'token'      => $e2e_token,
		'request_id' => $e2e_request_id,
		'formId'     => $e2e_definition['form_id'],
		'name'       => 'Mario Rossi',
		'email'      => 'mario.rossi@example.com',
		'privacy'    => '1',
	];
	$first_response = corsivo_forms_test_rest_request($valid_payload);
	$first_response_data = $first_response->get_data();
	corsivo_forms_test_check($first_response->get_status() === 200, 'handler REST accetta submission valida');
	corsivo_forms_test_check(($first_response_data['sent'] ?? false) === true && ($first_response_data['message'] ?? '') === 'Richiesta ricevuta.', 'handler REST restituisce conferma configurata');
	$e2e_submission_ids = corsivo_forms_test_submission_ids($e2e_request_id);
	corsivo_forms_test_check(count($e2e_submission_ids) === 1, 'handler REST crea una submission');
	$corsivo_forms_test_created_posts = array_merge($corsivo_forms_test_created_posts, $e2e_submission_ids);
	$e2e_submission_id = $e2e_submission_ids[0];
	corsivo_forms_test_check(get_post_meta($e2e_submission_id, '_corsivo_forms_notification_status', true) === 'accepted', 'handler REST persiste stato notifica accepted');
	$e2e_activity = corsivo_forms_activity_get_record($e2e_request_id);
	corsivo_forms_test_check(is_array($e2e_activity) && $e2e_activity['status'] === 'success', 'handler REST completa attività con successo');
	corsivo_forms_test_check($e2e_activity['storage_status'] === 'stored' && $e2e_activity['notification_status'] === 'accepted', 'attività E2E conserva stati storage e notifica');

	$replay_payload = $valid_payload;
	$replay_payload['token'] = 'v1.invalid';
	$duplicate_rest_response = corsivo_forms_test_rest_request($replay_payload);
	$duplicate_submission_ids = corsivo_forms_test_submission_ids($e2e_request_id);
	corsivo_forms_test_check($duplicate_rest_response->get_status() === 200 && $duplicate_rest_response->get_data() === $first_response_data, 'stessa request_id restituisce la risposta salvata anche con token non più valido');
	corsivo_forms_test_check(count($duplicate_submission_ids) === 1, 'stessa request_id non duplica la submission');
	corsivo_forms_test_check($mail_calls === 1, 'stessa request_id non duplica la notifica');
	$changed_replay_payload = $valid_payload;
	$changed_replay_payload['name'] = 'Luigi Bianchi';
	$changed_replay_response = corsivo_forms_test_rest_request($changed_replay_payload);
	$changed_replay_data = $changed_replay_response->get_data();
	corsivo_forms_test_check(
		$changed_replay_response->get_status() === 409 && ($changed_replay_data['code'] ?? '') === 'request_id_conflict',
		'request_id non può essere riusata con un payload diverso'
	);
	$conflict_payload = $valid_payload;
	$conflict_payload['formId'] = str_repeat('a', 32);
	$conflict_response = corsivo_forms_test_rest_request($conflict_payload);
	$conflict_data = $conflict_response->get_data();
	corsivo_forms_test_check($conflict_response->get_status() === 409 && ($conflict_data['code'] ?? '') === 'request_id_conflict', 'request_id non può essere riusata da un form diverso');

	$invalid_request_id = wp_generate_uuid4();
	$corsivo_forms_test_activity_ids[] = $invalid_request_id;
	$invalid_payload = [
		'token'      => $e2e_token,
		'request_id' => $invalid_request_id,
		'formId'     => $e2e_definition['form_id'],
		'name'       => 'Mario Rossi',
		'email'      => 'email-non-valida',
	];
	$invalid_response = corsivo_forms_test_rest_request($invalid_payload);
	$invalid_response_data = $invalid_response->get_data();
	corsivo_forms_test_check($invalid_response->get_status() === 400 && ($invalid_response_data['code'] ?? '') === 'validation', 'handler REST restituisce validation error');
	corsivo_forms_test_check(corsivo_forms_test_submission_ids($invalid_request_id) === [], 'validation error non archivia submission');
	corsivo_forms_test_check(corsivo_forms_activity_get_record($invalid_request_id) === null, 'validation error non crea claim attività');
	corsivo_forms_test_check($mail_calls === 1, 'validation error non invia notifiche');
	$submission_rate_state = get_transient($rate_transient);
	$validation_rate_state = get_transient($validation_transient);
	$preflight_rate_state  = get_transient($preflight_transient);
	corsivo_forms_test_check((int) ($submission_rate_state['count'] ?? 0) === 1, 'solo gli invii validi consumano il budget submission');
	corsivo_forms_test_check((int) ($validation_rate_state['count'] ?? 0) === 1, 'validation error consuma il budget errori dedicato');
	corsivo_forms_test_check((int) ($preflight_rate_state['count'] ?? 0) === 2, 'pre-limit copre invio e richiesta invalida senza penalizzare il replay');
	$file_form_attributes = [
		'values' => [
			'form_title'      => 'File E2E test',
			'recipients'      => 'files-e2e@example.com',
			'subject'         => 'File E2E',
			'success_message' => 'File ricevuto.',
			'store'           => true,
		],
	];
	$file_field_attributes = [
		'values' => [
			'label'              => 'Documento',
			'field_key'          => 'documents',
			'required'           => true,
			'multiple'           => false,
			'allowed_extensions' => ['png'],
			'max_files'          => 1,
			'max_file_size_mb'   => 1,
		],
	];
	$file_form_content = '<!-- wp:corsivo/form ' . wp_json_encode($file_form_attributes) . ' -->' . "\n"
		. '<!-- wp:corsivo/form-file ' . wp_json_encode($file_field_attributes) . ' /-->' . "\n"
		. '<!-- wp:corsivo/form-submit ' . wp_json_encode($submit_attributes) . ' /-->' . "\n"
		. '<!-- /wp:corsivo/form -->';
	$file_page_id = wp_insert_post([
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Corsivo Forms file E2E ' . wp_generate_uuid4(),
		'post_content' => $file_form_content,
	], true);
	corsivo_forms_test_check(!is_wp_error($file_page_id) && $file_page_id > 0, 'pagina pubblica E2E con file creata');
	$corsivo_forms_test_created_posts[] = (int) $file_page_id;
	$file_parsed_forms = corsivo_forms_collect_forms($file_form_content);
	$file_definition = count($file_parsed_forms) === 1
		? corsivo_forms_form_definition($file_parsed_forms[0], $file_page_id)
		: null;
	corsivo_forms_test_check(
		is_array($file_definition)
			&& ($file_definition['is_public'] ?? false) === true
			&& ($file_definition['fields'][0]['type'] ?? '') === 'file',
		'definizione pubblica E2E include il campo file'
	);
	$file_token = corsivo_forms_create_form_token($file_definition);
	corsivo_forms_test_check(is_string($file_token) && $file_token !== '', 'token E2E file creato');

	$file_rate_scope = hash_hmac('sha256', '203.0.113.77|form|' . $file_definition['form_id'], wp_salt('nonce'));
	$file_rate_transient = 'corsivo_forms_rate_' . $file_rate_scope;
	$file_rate_lock = 'corsivo_forms_rate_lock_' . $file_rate_scope;
	$corsivo_forms_test_rate_keys[] = $file_rate_transient;
	$corsivo_forms_test_rate_keys[] = $file_rate_lock;
	delete_transient($file_rate_transient);
	delete_option($file_rate_lock);

	$file_request_id = wp_generate_uuid4();
	$corsivo_forms_test_activity_ids[] = $file_request_id;
	$file_payload = [
		'token'      => $file_token,
		'request_id' => $file_request_id,
		'formId'     => $file_definition['form_id'],
	];
	$file_mail_calls_before = $mail_calls;
	$file_upload_path = corsivo_forms_test_temp_file($png_contents, 'handler-file.png');
	$file_request = corsivo_forms_test_upload_request([
		'documents' => corsivo_forms_test_upload_entry('handler-file.png', $file_upload_path),
	], $file_payload);
	$file_request->set_header('content-type', 'multipart/form-data; boundary=corsivo-e2e');
	$file_response = rest_do_request($file_request);
	$file_response_data = $file_response->get_data();
	$file_submission_ids = corsivo_forms_test_submission_ids($file_request_id);
	$corsivo_forms_test_created_posts = array_merge($corsivo_forms_test_created_posts, $file_submission_ids);
	corsivo_forms_test_check(
		$file_response->get_status() === 200
			&& ($file_response_data['sent'] ?? false) === true
			&& ($file_response_data['message'] ?? '') === 'File ricevuto.',
		'handler REST multipart accetta il file e restituisce la conferma configurata'
	);
	corsivo_forms_test_check(count($file_submission_ids) === 1, 'handler REST multipart crea una submission');
	$file_submission_uploads = $file_submission_ids ? corsivo_forms_submission_uploads($file_submission_ids[0]) : [];
	corsivo_forms_test_check(
		is_array($file_submission_uploads)
			&& count($file_submission_uploads) === 1
			&& ($file_submission_uploads[0]['original_name'] ?? '') === 'handler-file.png',
		'handler REST multipart persiste metadati e file della submission'
	);

	$file_replay_payload = $file_payload;
	$file_replay_payload['token'] = 'v1.invalid';
	$file_replay_path = corsivo_forms_test_temp_file($png_contents, 'handler-file-replay.png');
	$file_replay_request = corsivo_forms_test_upload_request([
		'documents' => corsivo_forms_test_upload_entry('handler-file.png', $file_replay_path),
	], $file_replay_payload);
	$file_replay_request->set_header('content-type', 'multipart/form-data; boundary=corsivo-e2e-replay');
	$file_replay_response = rest_do_request($file_replay_request);
	corsivo_forms_test_check(
		$file_replay_response->get_status() === 200 && $file_replay_response->get_data() === $file_response_data,
		'replay multipart identico restituisce la risposta salvata'
	);
	corsivo_forms_test_check(
		count(corsivo_forms_test_submission_ids($file_request_id)) === 1
			&& $mail_calls === $file_mail_calls_before + 1,
		'replay multipart identico non duplica submission o notifica'
	);

	$file_conflict_path = corsivo_forms_test_temp_file($png_contents . 'changed', 'handler-file-conflict.png');
	$file_conflict_request = corsivo_forms_test_upload_request([
		'documents' => corsivo_forms_test_upload_entry('handler-file.png', $file_conflict_path),
	], $file_payload);
	$file_conflict_request->set_header('content-type', 'multipart/form-data; boundary=corsivo-e2e-conflict');
	$file_conflict_response = rest_do_request($file_conflict_request);
	$file_conflict_data = $file_conflict_response->get_data();
	corsivo_forms_test_check(
		$file_conflict_response->get_status() === 409
			&& ($file_conflict_data['code'] ?? '') === 'request_id_conflict'
			&& count(corsivo_forms_test_submission_ids($file_request_id)) === 1
			&& $mail_calls === $file_mail_calls_before + 1,
		'stessa request_id con contenuto file diverso restituisce conflitto senza duplicazioni'
	);
} catch (Throwable $error) {
	$corsivo_forms_test_failure = $error;
} finally {
	if ($corsivo_forms_test_admin_email_filter !== null) {
		remove_filter('pre_option_admin_email', $corsivo_forms_test_admin_email_filter, PHP_INT_MAX);
	}

	if ($corsivo_forms_test_ttl_filter !== null) {
		remove_filter('corsivo_forms_form_token_ttl', $corsivo_forms_test_ttl_filter, PHP_INT_MAX);
	}
	if ($corsivo_forms_test_privacy_delete_filter !== null) {
		remove_filter('pre_delete_post', $corsivo_forms_test_privacy_delete_filter, 10);
	}

	if ($corsivo_forms_test_settings_existed) {
		update_option('corsivo_forms_settings', $corsivo_forms_test_previous_settings, false);
	} else {
		delete_option('corsivo_forms_settings');
	}
	if ($corsivo_forms_test_privacy_page_existed) {
		update_option('wp_page_for_privacy_policy', $corsivo_forms_test_previous_privacy_page, false);
	} else {
		delete_option('wp_page_for_privacy_policy');
	}

	foreach ($corsivo_forms_test_rate_keys as $rate_key) {
		if (str_starts_with($rate_key, 'corsivo_forms_rate_lock_')) {
			delete_option($rate_key);
		} else {
			delete_transient($rate_key);
		}
	}

	$GLOBALS['corsivo_forms_cleanup_running'] = true;
	foreach (array_unique(array_filter(array_map('intval', $corsivo_forms_test_created_posts))) as $created_post_id) {
		wp_delete_post($created_post_id, true);
	}
	unset($GLOBALS['corsivo_forms_cleanup_running']);

	if (function_exists('corsivo_forms_activity_table_name')) {
		global $wpdb;
		foreach (array_unique($corsivo_forms_test_activity_ids) as $activity_request_id) {
			$wpdb->delete(corsivo_forms_activity_table_name(), ['request_id' => $activity_request_id], ['%s']);
		}
	}
	foreach (array_unique($corsivo_forms_test_temp_files) as $temp_file) {
		if (is_string($temp_file) && is_file($temp_file) && !is_link($temp_file)) {
			wp_delete_file($temp_file);
		}
	}
	$temp_directories = array_unique($corsivo_forms_test_temp_directories);
	usort($temp_directories, static fn($left, $right) => strlen($right) <=> strlen($left));
	$temp_root = realpath(sys_get_temp_dir());
	$temp_root = $temp_root ? corsivo_forms_upload_normalize_path($temp_root) : '';
	foreach ($temp_directories as $temp_directory) {
		$resolved_directory = realpath((string) $temp_directory);
		if (!$resolved_directory
			|| !$temp_root
			|| is_link($temp_directory)
			|| !corsivo_forms_upload_path_is_within($resolved_directory, $temp_root)) {
			continue;
		}
		foreach (array_values(array_diff(scandir($resolved_directory) ?: [], ['.', '..'])) as $entry) {
			$entry_path = corsivo_forms_upload_normalize_path($resolved_directory . '/' . $entry);
			if (is_file($entry_path) && !is_link($entry_path)) {
				wp_delete_file($entry_path);
			}
		}
		@rmdir($resolved_directory);
	}
	if ($corsivo_forms_test_had_prepared_upload_batches) {
		$GLOBALS['corsivo_forms_prepared_upload_batches'] = $corsivo_forms_test_previous_prepared_upload_batches;
	} else {
		unset($GLOBALS['corsivo_forms_prepared_upload_batches']);
	}
	if ($corsivo_forms_test_had_log_suppression) {
		$GLOBALS['corsivo_forms_suppress_activity_log'] = $corsivo_forms_test_previous_log_suppression;
	} else {
		unset($GLOBALS['corsivo_forms_suppress_activity_log']);
	}

	corsivo_forms_test_restore_hooks();
}

if ($corsivo_forms_test_failure instanceof Throwable) {
	fwrite(STDERR, 'not ok - ' . $corsivo_forms_test_failure->getMessage() . PHP_EOL);
	fwrite(STDERR, $corsivo_forms_test_failure->getFile() . ':' . $corsivo_forms_test_failure->getLine() . PHP_EOL);
	exit(1);
}

echo 'PASS - ' . $corsivo_forms_test_checks . ' check, ' . $corsivo_forms_test_skips . ' skip' . PHP_EOL;

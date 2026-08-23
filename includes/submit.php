<?php

if (!defined('ABSPATH')) {
	exit;
}

const CORSIVO_FORMS_MAX_REQUESTS = 10;
const CORSIVO_FORMS_THROTTLE_WINDOW = 15 * MINUTE_IN_SECONDS;
const CORSIVO_FORMS_PREFLIGHT_MAX_REQUESTS = 60;
const CORSIVO_FORMS_PREFLIGHT_WINDOW = 5 * MINUTE_IN_SECONDS;
const CORSIVO_FORMS_VALIDATION_MAX_REQUESTS = 30;
const CORSIVO_FORMS_VALIDATION_WINDOW = 15 * MINUTE_IN_SECONDS;
const CORSIVO_FORMS_MAX_BODY_BYTES = 2 * MB_IN_BYTES;
const CORSIVO_FORMS_MAX_MULTIPART_BODY_BYTES = 27 * MB_IN_BYTES;

add_action('rest_api_init', 'corsivo_forms_register_submit_route');
add_filter('rest_pre_dispatch', 'corsivo_forms_guard_submit_body', 5, 3);
function corsivo_forms_request_body_limit(WP_REST_Request $request) {
	$content_type = strtolower((string) $request->get_header('content-type'));
	return str_starts_with($content_type, 'multipart/form-data')
		? CORSIVO_FORMS_MAX_MULTIPART_BODY_BYTES
		: CORSIVO_FORMS_MAX_BODY_BYTES;
}

function corsivo_forms_guard_submit_body($result, $server, WP_REST_Request $request) {
	if ($request->get_route() !== '/corsivo/v1/forms/submit' || $request->get_method() !== 'POST') {
		return $result;
	}
	$body_limit = corsivo_forms_request_body_limit($request);
	$content_length = (int) $request->get_header('content-length');
	if ($content_length > $body_limit || strlen((string) $request->get_body()) > $body_limit) {
		return new WP_Error('request_too_large', __('La richiesta è troppo grande.', 'corsivo-forms'), ['status' => 413]);
	}
	return $result;
}

function corsivo_forms_request_identity_value($value) {
	if (!is_array($value)) {
		return is_scalar($value) || $value === null ? $value : null;
	}

	if (!array_is_list($value)) {
		ksort($value, SORT_STRING);
	}

	return array_map('corsivo_forms_request_identity_value', $value);
}

function corsivo_forms_request_fingerprint(WP_REST_Request $request) {
	$params = $request->get_params();
	unset($params['token'], $params['request_id'], $params['corsivo_forms_hp']);

	$upload_identity = corsivo_forms_request_upload_identity($request);
	if (is_wp_error($upload_identity)) {
		return $upload_identity;
	}

	$identity = [
		'params'  => corsivo_forms_request_identity_value($params),
		'uploads' => $upload_identity,
	];
	$encoded = wp_json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($encoded)) {
		return new WP_Error(
			'request_identity_unavailable',
			__('La richiesta non può essere verificata.', 'corsivo-forms'),
			['status' => 503]
		);
	}

	return hash('sha256', $encoded);
}


function corsivo_forms_register_submit_route() {
	register_rest_route('corsivo/v1', '/forms/submit', [
		'methods'             => 'POST',
		'callback'            => 'corsivo_forms_handle_submit',
		'permission_callback' => '__return_true',
		'args'                => [
			'token' => [
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => static fn($value) => is_string($value) && strlen($value) <= 49155,
			],
			'request_id' => [
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => static fn($value) => is_string($value) && (bool) preg_match('/^[a-zA-Z0-9-]{16,64}$/', $value),
			],
			'formId' => [
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => static fn($value) => is_string($value) && (bool) preg_match('/^[a-f0-9]{32}$/', $value),
			],
			'corsivo_forms_hp' => [
				'type'     => 'string',
				'required' => false,
			],
		],
	]);
}

function corsivo_forms_handle_submit(WP_REST_Request $request) {
	$fallback_success = ['sent' => true, 'message' => __('Grazie! Il tuo messaggio è stato inviato.', 'corsivo-forms')];

	if (strlen((string) $request->get_body()) > corsivo_forms_request_body_limit($request)) {
		return new WP_Error('request_too_large', __('La richiesta è troppo grande.', 'corsivo-forms'), ['status' => 413]);
	}

	if (!corsivo_forms_request_has_valid_origin($request)) {
		return new WP_Error('invalid_origin', __('La richiesta non è autorizzata.', 'corsivo-forms'), ['status' => 403]);
	}

	$request_id = (string) $request->get_param('request_id');
	if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/', $request_id)) {
		$request_id = wp_generate_uuid4();
	}
	$form_id_hint = (string) $request->get_param('formId');

	$activity_storage = corsivo_forms_activity_require_storage();
	if (is_wp_error($activity_storage)) {
		return $activity_storage;
	}
	$existing = corsivo_forms_activity_get_record($request_id);
	if ($existing) {
		$request_fingerprint = corsivo_forms_request_fingerprint($request);
		if (is_wp_error($request_fingerprint)) {
			return $request_fingerprint;
		}
		if (!hash_equals((string) ($existing['request_fingerprint'] ?? ''), $request_fingerprint)
			|| (preg_match('/^[a-f0-9]{32}$/', $form_id_hint) && !hash_equals((string) $existing['form_id'], $form_id_hint))) {
			return new WP_Error('request_id_conflict', __('Identificativo richiesta non valido.', 'corsivo-forms'), ['status' => 409]);
		}
		return corsivo_forms_activity_duplicate_response($existing);
	}

	$preflight_rate_limit = corsivo_forms_check_preflight_rate_limit();
	if (is_wp_error($preflight_rate_limit)) {
		return $preflight_rate_limit;
	}

	$request_fingerprint = corsivo_forms_request_fingerprint($request);
	if (is_wp_error($request_fingerprint)) {
		return $request_fingerprint;
	}

	if (trim((string) $request->get_param('corsivo_forms_hp')) !== '') {
		return new WP_Error('spam_detected', __('Invio non riuscito: ricarica la pagina e riprova.', 'corsivo-forms'), ['status' => 400]);
	}

	$definition = corsivo_forms_resolve_request_definition($request);
	if (is_wp_error($definition)) {
		return $definition;
	}

	$fields = $definition['fields'];
	if (!$fields) {
		return new WP_Error('empty_form', __('Il modulo non contiene campi validi.', 'corsivo-forms'), ['status' => 409]);
	}

	$upload_preparation = corsivo_forms_prepare_request_uploads($request, $fields);
	if (is_wp_error($upload_preparation)) {
		$validation_rate_limit = corsivo_forms_check_validation_rate_limit($definition['form_id']);
		return is_wp_error($validation_rate_limit) ? $validation_rate_limit : $upload_preparation;
	}
	$upload_values = is_array($upload_preparation['values'] ?? null)
		? $upload_preparation['values']
		: [];

	$clean  = [];
	$errors = [];
	foreach ($fields as $field) {
		if (($field['type'] ?? '') === 'file') {
			$clean[$field['name']] = $upload_values[$field['name']] ?? [];
			continue;
		}

		$result = corsivo_forms_validate_field($field, $request->get_param($field['name']));
		if ($result['error'] !== null) {
			$errors[$field['name']] = $result['error'];
		} else {
			$clean[$field['name']] = $result['value'];
		}
	}
	if ($errors) {
		$validation_rate_limit = corsivo_forms_check_validation_rate_limit($definition['form_id']);
		if (is_wp_error($validation_rate_limit)) {
			return $validation_rate_limit;
		}
		return new WP_Error('validation', __('Controlla i campi evidenziati.', 'corsivo-forms'), ['status' => 400, 'errors' => $errors]);
	}

	$rate_limit = corsivo_forms_check_rate_limit($definition['form_id']);
	if (is_wp_error($rate_limit)) {
		return $rate_limit;
	}

	$claim = corsivo_forms_activity_claim_request($request_id, [
		'event'      => 'submission',
		'status'     => 'processing',
		'form_id'    => $definition['form_id'],
		'form_title' => $definition['settings']['form_title'],
		'page_id'    => (int) $definition['page_id'],
		'request_fingerprint' => $request_fingerprint,
	]);
	if (is_wp_error($claim)) {
		return new WP_Error('activity_unavailable', __('Il servizio è temporaneamente non disponibile.', 'corsivo-forms'), ['status' => 503]);
	}
	if (empty($claim['claimed'])) {
		if (!hash_equals((string) ($claim['record']['request_fingerprint'] ?? ''), $request_fingerprint)
			|| !hash_equals((string) $claim['record']['form_id'], (string) $definition['form_id'])) {
			return new WP_Error('request_id_conflict', __('Identificativo richiesta non valido.', 'corsivo-forms'), ['status' => 409]);
		}
		$duplicate = corsivo_forms_activity_duplicate_response($claim['record']);
		return $duplicate ?: new WP_Error('request_in_progress', __('La richiesta è già in elaborazione.', 'corsivo-forms'), ['status' => 409]);
	}

	$submission = corsivo_forms_build_submission(
		$definition['settings'],
		$fields,
		$clean,
		$definition,
		$request_id
	);

	$requires_file_storage = (bool) array_filter($fields, static fn($field) => ($field['type'] ?? '') === 'file');
	$submission['prepared_uploads'] = $upload_preparation['prepared'] ?? [];

	$submission_id = 0;
	$storage_status = 'disabled';
	$storage_error  = '';
	if (!empty($definition['settings']['store'])) {
		$stored = corsivo_forms_store_submission($submission);
		if (is_wp_error($stored)) {
			$storage_status = 'failed';
			$storage_error  = $stored->get_error_code();
		} else {
			$submission_id = (int) $stored;
			$storage_status = 'stored';
		}
	}
	if ($submission_id > 0) {
		$submission['submission_id'] = $submission_id;
	}
	unset($submission['prepared_uploads']);

	if ($requires_file_storage && $submission_id < 1) {
		corsivo_forms_activity_update_request($request_id, [
			'status'         => 'failed',
			'storage_status' => $storage_status,
			'error_code'     => $storage_error ?: 'submission_storage_required',
		]);
		$error_response = new WP_Error('submission_failed', __('Invio non riuscito: riprova più tardi.', 'corsivo-forms'), ['status' => 500]);
		corsivo_forms_activity_store_response($request_id, $error_response, 500);
		return $error_response;
	}


	corsivo_forms_activity_update_request($request_id, [
		'submission_id' => $submission_id,
		'storage_status' => $storage_status,
		'error_code'     => $storage_error,
	]);

	$notification = corsivo_forms_send_notification($submission);
	corsivo_forms_update_notification_status($submission_id, $notification);
	$captured = $submission_id > 0 || (int) $notification['accepted'] > 0;
	if (!$captured) {
		corsivo_forms_activity_update_request($request_id, [
			'status'              => 'failed',
			'storage_status'      => $storage_status,
			'notification_status' => $notification['status'],
			'error_code'          => $storage_error ?: (string) $notification['error_code'],
		]);
		$error_response = new WP_Error('submission_failed', __('Invio non riuscito: riprova più tardi.', 'corsivo-forms'), ['status' => 500]);
		corsivo_forms_activity_store_response($request_id, $error_response, 500);
		return $error_response;
	}

	$integration_status = 'success';
	try {
		do_action('corsivo_forms_submission', $submission, $clean, $submission_id);
	} catch (Throwable $error) {
		$integration_status = 'failed';
		corsivo_forms_activity_record_event('integration', 'failed', [
			'submission_id' => $submission_id,
			'form_id'       => $definition['form_id'],
			'form_title'    => $definition['settings']['form_title'],
			'page_id'       => (int) $definition['page_id'],
			'error_code'    => 'integration_exception',
			'message'       => corsivo_forms_activity_exception_summary($error),
		]);
	}

	corsivo_forms_activity_update_request($request_id, [
		'status'              => 'success',
		'storage_status'      => $storage_status,
		'notification_status' => $notification['status'],
		'error_code'          => $storage_error ?: (string) $notification['error_code'],
		'message'             => $integration_status === 'failed' ? __('Una integrazione opzionale non è riuscita.', 'corsivo-forms') : '',
	]);

	$message  = trim((string) $definition['settings']['success_message']) ?: $fallback_success['message'];
	$response = rest_ensure_response(['sent' => true, 'message' => $message]);
	corsivo_forms_activity_store_response($request_id, $response, 200);
	return $response;
}

function corsivo_forms_resolve_request_definition(WP_REST_Request $request) {
	return corsivo_forms_read_form_token((string) $request->get_param('token'));
}

function corsivo_forms_build_submission(array $settings, array $fields, array $clean, array $definition, $request_id) {
	$rows                 = [];
	$consents             = [];
	$sender_email         = '';
	$sender_name          = '';
	$sender_name_priority = -1;
	$email_addresses      = [];
	$submitted_at_gmt     = current_time('mysql', true);

	foreach ($fields as $field) {
		$name  = $field['name'];
		$value = $clean[$name] ?? '';
		if (($field['type'] ?? '') === 'file') {
			$file_names = array_map(
				static fn($file) => is_array($file) ? (string) ($file['original_name'] ?? '') : '',
				(array) $value
			);
			$value = implode(', ', array_filter($file_names, static fn($file_name) => $file_name !== ''));
		}

		$display = in_array($field['type'], ['checkbox', 'privacy'], true)
			? ($value ? __('Sì', 'corsivo-forms') : __('No', 'corsivo-forms'))
			: (string) $value;

		if ($field['type'] === 'email' && is_email($value)) {
			$email_address = strtolower(sanitize_email($value));
			if ($email_address !== '' && !in_array($email_address, $email_addresses, true)) {
				$email_addresses[] = $email_address;
			}
			if ($sender_email === '') {
				$sender_email = $email_address;
			}
		}
		if ($field['type'] === 'text' && $value !== '') {
			$name_priority = ($field['values']['field_key'] ?? '') === 'sender_name' ? 1 : 0;
			if ($name_priority > $sender_name_priority) {
				$sender_name          = sanitize_text_field($value);
				$sender_name_priority = $name_priority;
			}
		}

		$label  = trim((string) ($field['values']['label'] ?? $name));
		$rows[] = [
			'name'    => $name,
			'type'    => $field['type'],
			'label'   => $label ?: $name,
			'value'   => $value,
			'display' => $display,
		];

		if ($field['type'] === 'privacy' && $value) {
			$policy_url        = esc_url_raw((string) ($field['policy_url'] ?? ''));
			$policy_hash       = (string) ($field['policy_hash'] ?? '');
			$policy_updated_at = (string) ($field['policy_updated_at_gmt'] ?? '');
			$consents[] = [
				'field_name'      => $name,
				'label'           => $label,
				'policy_url'      => $policy_url,
				'policy_id'       => (int) ($field['policy_id'] ?? 0),
				'policy_hash'     => $policy_hash,
				'policy_updated_at_gmt' => $policy_updated_at,
				'evidence_hash'   => hash('sha256', $label . "\n" . $policy_url . "\n" . $policy_hash . "\n" . $policy_updated_at),
				'accepted_at_gmt' => $submitted_at_gmt,
			];
		}
	}

	return [
		'request_id'       => $request_id,
		'form_id'          => (string) $definition['form_id'],
		'form_title'       => trim((string) $settings['form_title']) ?: 'Modulo',
		'recipients'       => (string) $settings['recipients'],
		'subject'          => trim((string) $settings['subject']) ?: 'Nuovo messaggio dal sito',
		'rows'             => $rows,
		'values'           => $clean,
		'sender_email'     => $sender_email,
		'email_addresses'  => $email_addresses,
		'sender_name'      => $sender_name,
		'consents'         => $consents,
		'page_id'          => (int) $definition['page_id'],
		'page_title'       => (string) $definition['page_title'],
		'page_url'         => (string) $definition['page_url'],
		'language'         => (string) $definition['language'],
		'submitted_at_gmt' => $submitted_at_gmt,
		'submitted_at'     => get_date_from_gmt($submitted_at_gmt, 'd/m/Y H:i:s'),
	];
}

function corsivo_forms_request_has_valid_origin(WP_REST_Request $request) {
	$fetch_site = strtolower((string) $request->get_header('sec-fetch-site'));
	if ($fetch_site === 'cross-site') {
		return false;
	}

	$origin = (string) $request->get_header('origin');
	if ($origin === '') {
		return true;
	}

	$origin_value = corsivo_forms_normalize_origin($origin);
	$site_origin  = corsivo_forms_normalize_origin(home_url('/'));
	return $origin_value !== '' && $site_origin !== '' && hash_equals($site_origin, $origin_value);
}

function corsivo_forms_normalize_origin($url) {
	$parts  = wp_parse_url((string) $url);
	$scheme = strtolower((string) ($parts['scheme'] ?? ''));
	$host   = strtolower((string) ($parts['host'] ?? ''));
	if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
		return '';
	}

	$port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
	return $scheme . '://' . $host . ':' . $port;
}

function corsivo_forms_check_rate_limit($form_id) {
	$limit  = max(1, (int) apply_filters('corsivo_forms_rate_limit', CORSIVO_FORMS_MAX_REQUESTS, $form_id));
	$window = max(MINUTE_IN_SECONDS, (int) apply_filters('corsivo_forms_rate_window', CORSIVO_FORMS_THROTTLE_WINDOW, $form_id));
	return corsivo_forms_increment_rate_limit('form|' . $form_id, $limit, $window);
}

function corsivo_forms_check_preflight_rate_limit() {
	$limit  = max(1, (int) apply_filters('corsivo_forms_preflight_rate_limit', CORSIVO_FORMS_PREFLIGHT_MAX_REQUESTS));
	$window = max(MINUTE_IN_SECONDS, (int) apply_filters('corsivo_forms_preflight_rate_window', CORSIVO_FORMS_PREFLIGHT_WINDOW));
	return corsivo_forms_increment_rate_limit('preflight', $limit, $window);
}

function corsivo_forms_check_validation_rate_limit($form_id) {
	$limit  = max(1, (int) apply_filters('corsivo_forms_validation_rate_limit', CORSIVO_FORMS_VALIDATION_MAX_REQUESTS, $form_id));
	$window = max(MINUTE_IN_SECONDS, (int) apply_filters('corsivo_forms_validation_rate_window', CORSIVO_FORMS_VALIDATION_WINDOW, $form_id));
	return corsivo_forms_increment_rate_limit('validation|' . $form_id, $limit, $window);
}

function corsivo_forms_increment_rate_limit($bucket, $limit, $window) {
	$ip = apply_filters('corsivo_forms_client_ip', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
	$ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
	$scope = hash_hmac('sha256', $ip . '|' . $bucket, wp_salt('nonce'));
	$key   = 'corsivo_forms_rate_' . $scope;
	$lock  = 'corsivo_forms_rate_lock_' . $scope;

	$locked = add_option($lock, time(), '', false);
	if (!$locked) {
		$started = (int) get_option($lock, 0);
		if ($started > 0 && $started < time() - 10) {
			delete_option($lock);
			$locked = add_option($lock, time(), '', false);
		}
	}
	if (!$locked) {
		return new WP_Error('rate_limit_busy', __('Riprova tra qualche secondo.', 'corsivo-forms'), ['status' => 429]);
	}

	try {
		$state = get_transient($key);
		$now   = time();
		if (!is_array($state) || empty($state['started']) || (int) $state['started'] <= $now - $window) {
			$state = ['count' => 0, 'started' => $now];
		}
		if ((int) $state['count'] >= $limit) {
			return new WP_Error('throttled', __('Troppe richieste: riprova tra qualche minuto.', 'corsivo-forms'), ['status' => 429]);
		}
		$state['count'] = (int) $state['count'] + 1;
		$expires_in     = max(1, (int) $state['started'] + $window - $now);
		if (!set_transient($key, $state, $expires_in)) {
			return new WP_Error('rate_limit_unavailable', __('Riprova tra qualche secondo.', 'corsivo-forms'), ['status' => 503]);
		}
	} finally {
		delete_option($lock);
	}

	return true;
}

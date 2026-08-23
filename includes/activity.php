<?php

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_post_corsivo_forms_clear_activity', 'corsivo_forms_activity_handle_clear');
add_action('update_option_corsivo_forms_settings', 'corsivo_forms_activity_log_settings_update', 10, 2);

function corsivo_forms_activity_logging_enabled() {
	return function_exists('corsivo_forms_get_setting')
		? (bool) corsivo_forms_get_setting('log_enabled', true)
		: true;
}

function corsivo_forms_activity_retention_days() {
	if (!corsivo_forms_activity_logging_enabled()) {
		return 1;
	}

	$days = function_exists('corsivo_forms_get_setting')
		? (int) corsivo_forms_get_setting('log_retention_days', 90)
		: 90;

	return max(1, min(3650, $days));
}

function corsivo_forms_activity_normalize_request_id($request_id) {
	$request_id = is_scalar($request_id) ? trim((string) $request_id) : '';
	$request_id = preg_replace('/[^A-Za-z0-9._:-]/', '', $request_id);

	return substr((string) $request_id, 0, 64);
}

function corsivo_forms_activity_sanitize_fingerprint($fingerprint) {
	$fingerprint = is_scalar($fingerprint) ? strtolower(trim((string) $fingerprint)) : '';

	return preg_match('/^[a-f0-9]{64}$/D', $fingerprint) ? $fingerprint : '';
}


function corsivo_forms_activity_redact_message($message) {
	$message = is_scalar($message) ? (string) $message : '';
	$message = wp_strip_all_tags($message);
	$message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $message);
	$message = preg_replace('~\b[A-Z]:[\\\\/][^\r\n<>"\']+~i', '[path]', $message);
	$message = preg_replace('~(?<![A-Za-z0-9])/(?:var|home|srv|tmp|opt|usr|etc|mnt|Users|Volumes|private)(?:/[^\s<>"\']+)+~', '[path]', $message);
	$message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message);

	return trim(corsivo_forms_activity_limit_text((string) $message, 2000));
}

function corsivo_forms_activity_exception_summary(Throwable $error) {
	$summary = get_class($error);
	if ($error->getCode() !== 0) {
		$summary .= ' (' . $error->getCode() . ')';
	}

	return $summary . ' [' . substr(hash_hmac('sha256', $error->getMessage(), wp_salt('nonce')), 0, 12) . ']';
}

function corsivo_forms_activity_limit_text($value, $length) {
	return function_exists('mb_substr') ? mb_substr((string) $value, 0, $length) : substr((string) $value, 0, $length);
}

function corsivo_forms_activity_sanitize_key($value, $length = 64) {
	$value = is_scalar($value) ? sanitize_key((string) $value) : '';

	return substr($value, 0, $length);
}

function corsivo_forms_activity_sanitize_text($value, $length = 191) {
	$value = is_scalar($value) ? sanitize_text_field((string) $value) : '';

	return corsivo_forms_activity_limit_text($value, $length);
}

function corsivo_forms_activity_sanitize_response_body($body) {
	if ($body instanceof WP_REST_Response) {
		$body = $body->get_data();
	}

	if (is_wp_error($body)) {
		$error_data = $body->get_error_data();
		$body = [
			'code'    => $body->get_error_code(),
			'message' => $body->get_error_message(),
			'data'    => is_array($error_data) ? $error_data : [],
		];
	}

	if (!is_array($body)) {
		return ['message' => corsivo_forms_activity_redact_message($body)];
	}

	$clean = [];
	if (array_key_exists('sent', $body)) {
		$clean['sent'] = (bool) $body['sent'];
	}
	if (array_key_exists('success', $body)) {
		$clean['success'] = (bool) $body['success'];
	}
	if (isset($body['code'])) {
		$clean['code'] = corsivo_forms_activity_sanitize_key($body['code']);
	}
	if (isset($body['message'])) {
		$clean['message'] = corsivo_forms_activity_redact_message($body['message']);
	}

	$body_data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
	if ($body_data) {
		$clean_data = [];
		if (isset($body_data['status'])) {
			$clean_data['status'] = max(100, min(599, (int) $body_data['status']));
		}
		if (isset($body_data['errors']) && is_array($body_data['errors'])) {
			$errors = [];
			foreach (array_slice($body_data['errors'], 0, 100, true) as $field => $error_message) {
				$field = corsivo_forms_activity_sanitize_key($field);
				if ($field !== '') {
					$errors[$field] = corsivo_forms_activity_limit_text(corsivo_forms_activity_redact_message($error_message), 500);
				}
			}
			if ($errors) {
				$clean_data['errors'] = $errors;
			}
		}
		if ($clean_data) {
			$clean['data'] = $clean_data;
		}
	}

	return $clean;
}

function corsivo_forms_activity_response_envelope($response, $http_status = 200) {
	if ($response instanceof WP_REST_Response) {
		$http_status = $response->get_status();
	}

	if (is_wp_error($response)) {
		$error_data = $response->get_error_data();
		$http_status = is_array($error_data) && isset($error_data['status'])
			? (int) $error_data['status']
			: 500;
	}

	$http_status = (int) $http_status;
	if ($http_status < 100 || $http_status > 599) {
		$http_status = 200;
	}

	return [
		'status' => $http_status,
		'body'   => corsivo_forms_activity_sanitize_response_body($response),
	];
}

function corsivo_forms_activity_encode_response($response, $http_status = 200) {
	if (is_string($response)) {
		$decoded = json_decode($response, true);
		if (is_array($decoded) && isset($decoded['status'], $decoded['body'])) {
			$http_status = (int) $decoded['status'];
			$response = $decoded['body'];
		} elseif (is_array($decoded)) {
			$response = $decoded;
		}
	} elseif (is_array($response) && isset($response['status'], $response['body'])) {
		$http_status = (int) $response['status'];
		$response = $response['body'];
	}

	return (string) wp_json_encode(corsivo_forms_activity_response_envelope($response, $http_status));
}

function corsivo_forms_activity_prepare_record($request_id, array $attributes = []) {
	$now = gmdate('Y-m-d H:i:s');

	return [
		'request_id'          => corsivo_forms_activity_normalize_request_id($request_id),
		'request_fingerprint'  => corsivo_forms_activity_sanitize_fingerprint($attributes['request_fingerprint'] ?? ''),
		'event'               => corsivo_forms_activity_sanitize_key($attributes['event'] ?? 'request'),
		'status'              => corsivo_forms_activity_sanitize_key($attributes['status'] ?? 'processing', 32),
		'form_id'             => corsivo_forms_activity_sanitize_text($attributes['form_id'] ?? '', 64),
		'form_title'          => corsivo_forms_activity_sanitize_text($attributes['form_title'] ?? ''),
		'submission_id'       => absint($attributes['submission_id'] ?? 0),
		'page_id'             => absint($attributes['page_id'] ?? 0),
		'actor_id'            => absint($attributes['actor_id'] ?? get_current_user_id()),
		'storage_status'      => corsivo_forms_activity_sanitize_key($attributes['storage_status'] ?? '', 32),
		'notification_status' => corsivo_forms_activity_sanitize_key($attributes['notification_status'] ?? '', 32),
		'error_code'          => corsivo_forms_activity_sanitize_key($attributes['error_code'] ?? ''),
		'message'             => corsivo_forms_activity_redact_message($attributes['message'] ?? ''),
		'response'            => array_key_exists('response', $attributes)
			? corsivo_forms_activity_encode_response($attributes['response'], $attributes['http_status'] ?? 200)
			: '',
		'created_at_gmt'      => $now,
		'updated_at_gmt'      => $now,
	];
}

function corsivo_forms_activity_get_record($request_id) {
	global $wpdb;

	if (!corsivo_forms_activity_schema_is_ready()) {
		return null;
	}

	$request_id = corsivo_forms_activity_normalize_request_id($request_id);
	if ($request_id === '') {
		return null;
	}

	$table_name = corsivo_forms_activity_table_name();

	return $wpdb->get_row(
		$wpdb->prepare("SELECT * FROM {$table_name} WHERE request_id = %s LIMIT 1", $request_id),
		ARRAY_A
	);
}

function corsivo_forms_activity_claim($request_id, array $attributes = []) {
	global $wpdb;

	$storage = corsivo_forms_activity_require_storage();
	if (is_wp_error($storage)) {
		return $storage;
	}

	$request_id = corsivo_forms_activity_normalize_request_id($request_id);
	if ($request_id === '') {
		return new WP_Error('corsivo_forms_invalid_request_id', 'Identificativo richiesta non valido.', ['status' => 400]);
	}

	$record = corsivo_forms_activity_prepare_record($request_id, $attributes);
	$errors_hidden = $wpdb->suppress_errors();
	$inserted = $wpdb->insert(
		corsivo_forms_activity_table_name(),
		$record,
		['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
	);
	$wpdb->suppress_errors($errors_hidden);

	if ($inserted) {
		$claimed_record = corsivo_forms_activity_get_record($request_id);
		if (!$claimed_record) {
			return new WP_Error('corsivo_forms_activity_unavailable', 'Registro attività non disponibile.', ['status' => 503]);
		}

		return [
			'claimed' => true,
			'record'  => $claimed_record,
		];
	}

	$existing = corsivo_forms_activity_get_record($request_id);
	if ($existing) {
		return [
			'claimed' => false,
			'record'  => $existing,
		];
	}

	return new WP_Error('corsivo_forms_activity_unavailable', 'Registro attività non disponibile.', ['status' => 503]);
}

function corsivo_forms_activity_claim_request($request_id, array $context = []) {
	return corsivo_forms_activity_claim($request_id, $context);
}

function corsivo_forms_activity_update_record($request_id, array $changes) {
	global $wpdb;

	$storage = corsivo_forms_activity_require_storage();
	if (is_wp_error($storage)) {
		return $storage;
	}

	$request_id = corsivo_forms_activity_normalize_request_id($request_id);
	if ($request_id === '') {
		return new WP_Error('corsivo_forms_invalid_request_id', 'Identificativo richiesta non valido.');
	}

	$sanitizers = [
		'event'               => static fn($value) => corsivo_forms_activity_sanitize_key($value),
		'status'              => static fn($value) => corsivo_forms_activity_sanitize_key($value, 32),
		'form_id'             => static fn($value) => corsivo_forms_activity_sanitize_text($value, 64),
		'form_title'          => static fn($value) => corsivo_forms_activity_sanitize_text($value),
		'submission_id'       => 'absint',
		'page_id'             => 'absint',
		'actor_id'            => 'absint',
		'storage_status'      => static fn($value) => corsivo_forms_activity_sanitize_key($value, 32),
		'notification_status' => static fn($value) => corsivo_forms_activity_sanitize_key($value, 32),
		'error_code'          => static fn($value) => corsivo_forms_activity_sanitize_key($value),
		'message'             => 'corsivo_forms_activity_redact_message',
	];
	$formats = [
		'event'               => '%s',
		'status'              => '%s',
		'form_id'             => '%s',
		'form_title'          => '%s',
		'submission_id'       => '%d',
		'page_id'             => '%d',
		'actor_id'            => '%d',
		'storage_status'      => '%s',
		'notification_status' => '%s',
		'error_code'          => '%s',
		'message'             => '%s',
		'response'            => '%s',
		'updated_at_gmt'      => '%s',
	];
	$changes_to_store = [];

	foreach ($sanitizers as $key => $sanitizer) {
		if (array_key_exists($key, $changes)) {
			$changes_to_store[$key] = $sanitizer($changes[$key]);
		}
	}

	if (array_key_exists('response', $changes)) {
		$changes_to_store['response'] = corsivo_forms_activity_encode_response(
			$changes['response'],
			$changes['http_status'] ?? 200
		);
	}

	$changes_to_store['updated_at_gmt'] = gmdate('Y-m-d H:i:s');
	$used_formats = array_map(static fn($key) => $formats[$key], array_keys($changes_to_store));
	$updated = $wpdb->update(
		corsivo_forms_activity_table_name(),
		$changes_to_store,
		['request_id' => $request_id],
		$used_formats,
		['%s']
	);

	if ($updated === false) {
		return new WP_Error('corsivo_forms_activity_update_failed', 'Aggiornamento attività non riuscito.');
	}

	$record = corsivo_forms_activity_get_record($request_id);
	if (!$record) {
		return new WP_Error('corsivo_forms_activity_not_found', 'Attività non trovata.');
	}

	return $record;
}

function corsivo_forms_activity_update_request($request_id, array $changes) {
	return corsivo_forms_activity_update_record($request_id, $changes);
}

function corsivo_forms_activity_store_response($request_id, $response, $http_status = 200) {
	return corsivo_forms_activity_update_record($request_id, [
		'response'    => $response,
		'http_status' => $http_status,
	]);
}

function corsivo_forms_activity_duplicate_response($record) {
	if (is_string($record)) {
		$record = corsivo_forms_activity_get_record($record);
	}

	if (!is_array($record)) {
		return null;
	}

	$envelope = json_decode((string) ($record['response'] ?? ''), true);
	if (!is_array($envelope) || !isset($envelope['status'], $envelope['body']) || !is_array($envelope['body'])) {
		$submission_id = (int) ($record['submission_id'] ?? 0);
		if (!$submission_id && !empty($record['request_id'])) {
			$matches = get_posts([
				'post_type'      => 'corsivo_submission',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_corsivo_forms_request_id',
				'meta_value'     => $record['request_id'],
			]);
			$submission_id = (int) ($matches[0] ?? 0);
		}
		if ($submission_id) {
			return new WP_REST_Response([
				'sent'    => true,
				'message' => __('La richiesta è già stata ricevuta.', 'corsivo-forms'),
			], 200);
		}
		$status = (string) ($record['status'] ?? 'processing');
		$notification = (string) ($record['notification_status'] ?? '');
		if ($status === 'success' && in_array($notification, ['accepted', 'partial'], true)) {
			return new WP_REST_Response([
				'sent'    => true,
				'message' => __('La richiesta è già stata ricevuta.', 'corsivo-forms'),
			], 200);
		}
		if ($status === 'failed') {
			return new WP_Error(
				'submission_failed',
				__('Invio non riuscito: riprova più tardi.', 'corsivo-forms'),
				['status' => 500]
			);
		}
		$updated_at = strtotime((string) ($record['updated_at_gmt'] ?? '') . ' UTC');
		$stale_after = max(MINUTE_IN_SECONDS, (int) apply_filters('corsivo_forms_processing_timeout', 5 * MINUTE_IN_SECONDS));
		$is_stale = !$updated_at || $updated_at < time() - $stale_after;
		if ($status === 'unknown' || ($status === 'processing' && $is_stale)) {
			if ($status !== 'unknown' && !empty($record['request_id'])) {
				corsivo_forms_activity_update_request($record['request_id'], [
					'status'     => 'unknown',
					'error_code' => 'processing_interrupted',
				]);
			}
			return new WP_Error(
				'corsivo_forms_request_unknown',
				__('Non è possibile verificare l’esito del tentativo precedente. Controlla prima di riprovare.', 'corsivo-forms'),
				['status' => 409, 'can_retry' => true]
			);
		}
		return new WP_Error(
			'corsivo_forms_request_in_progress',
			__('La richiesta è già in elaborazione.', 'corsivo-forms'),
			['status' => 409, 'retry_after' => 2]
		);
	}

	return new WP_REST_Response(
		$envelope['body'],
		max(100, min(599, (int) $envelope['status']))
	);
}

function corsivo_forms_activity_insert_event($event, $status, array $attributes = [], $allow_when_disabled = false) {
	if ((!$allow_when_disabled && !corsivo_forms_activity_logging_enabled()) || !empty($GLOBALS['corsivo_forms_suppress_activity_log'])) {
		return null;
	}

	$attributes['event'] = $event;
	$attributes['status'] = $status;

	for ($attempt = 0; $attempt < 3; $attempt++) {
		$result = corsivo_forms_activity_claim(wp_generate_uuid4(), $attributes);
		if (is_wp_error($result)) {
			return $result;
		}
		if ($result['claimed']) {
			return $result['record'];
		}
	}

	return new WP_Error('corsivo_forms_activity_insert_failed', 'Registrazione attività non riuscita.');
}

function corsivo_forms_activity_record_event($event, $status, array $context = []) {
	return corsivo_forms_activity_insert_event($event, $status, $context);
}

function corsivo_forms_activity_query_filters(array $args) {
	$clauses = [];
	$params = [];
	$status = corsivo_forms_activity_sanitize_key($args['status'] ?? '', 32);
	$event = corsivo_forms_activity_sanitize_key($args['event'] ?? '');
	$search = isset($args['search']) && is_scalar($args['search'])
		? sanitize_text_field((string) $args['search'])
		: '';

	if ($status !== '') {
		$clauses[] = 'status = %s';
		$params[] = $status;
	}
	if ($event !== '') {
		$clauses[] = 'event = %s';
		$params[] = $event;
	}
	if ($search !== '') {
		global $wpdb;

		$like = '%' . $wpdb->esc_like($search) . '%';
		$clauses[] = '(request_id LIKE %s OR form_id LIKE %s OR form_title LIKE %s OR error_code LIKE %s OR message LIKE %s)';
		array_push($params, $like, $like, $like, $like, $like);
	}

	return [
		'where'  => $clauses ? ' WHERE ' . implode(' AND ', $clauses) : '',
		'params' => $params,
	];
}

function corsivo_forms_activity_query(array $args = []) {
	global $wpdb;

	if (!corsivo_forms_activity_schema_is_ready()) {
		return [];
	}

	$filters = corsivo_forms_activity_query_filters($args);
	$page = max(1, (int) ($args['page'] ?? 1));
	$per_page = max(1, min(100, (int) ($args['per_page'] ?? 20)));
	$offset = ($page - 1) * $per_page;
	$sql = 'SELECT * FROM ' . corsivo_forms_activity_table_name()
		. $filters['where']
		. ' ORDER BY created_at_gmt DESC, id DESC LIMIT %d OFFSET %d';
	$params = array_merge($filters['params'], [$per_page, $offset]);

	return (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
}

function corsivo_forms_activity_count(array $args = []) {
	global $wpdb;

	if (!corsivo_forms_activity_schema_is_ready()) {
		return 0;
	}

	$filters = corsivo_forms_activity_query_filters($args);
	$sql = 'SELECT COUNT(*) FROM ' . corsivo_forms_activity_table_name() . $filters['where'];

	if ($filters['params']) {
		$sql = $wpdb->prepare($sql, $filters['params']);
	}

	return (int) $wpdb->get_var($sql);
}

function corsivo_forms_activity_distinct_values($column) {
	global $wpdb;

	if (!corsivo_forms_activity_schema_is_ready()
		|| !in_array($column, ['event', 'status', 'storage_status', 'notification_status'], true)) {
		return [];
	}

	$table_name = corsivo_forms_activity_table_name();
	$values = (array) $wpdb->get_col("SELECT DISTINCT {$column} FROM {$table_name} WHERE {$column} <> '' ORDER BY {$column} ASC");

	return array_values(array_filter(array_map('sanitize_key', $values)));
}

function corsivo_forms_activity_purge($days = null) {
	global $wpdb;

	$storage = corsivo_forms_activity_require_storage();
	if (is_wp_error($storage)) {
		return $storage;
	}

	$days = $days === null ? corsivo_forms_activity_retention_days() : max(1, min(3650, (int) $days));
	$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
	$table_name = corsivo_forms_activity_table_name();

	return $wpdb->query($wpdb->prepare("DELETE FROM {$table_name} WHERE created_at_gmt < %s", $cutoff));
}

function corsivo_forms_activity_clear() {
	global $wpdb;

	$storage = corsivo_forms_activity_require_storage();
	if (is_wp_error($storage)) {
		return $storage;
	}

	$table_name = corsivo_forms_activity_table_name();
	$cutoff = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
	$result = $wpdb->query($wpdb->prepare(
		"DELETE FROM {$table_name}
		 WHERE NOT (event = 'submission' AND (status = 'processing' OR updated_at_gmt >= %s))",
		$cutoff
	));
	corsivo_forms_activity_insert_event('activity_clear', $result === false ? 'failed' : 'success', [
		'actor_id'   => get_current_user_id(),
		'error_code' => $result === false ? 'activity_clear_failed' : '',
		'message'    => $result === false
			? __('Impossibile ripulire il registro attività.', 'corsivo-forms')
			: sprintf(_n('%d attività eliminata.', '%d attività eliminate.', $result, 'corsivo-forms'), $result),
	], true);

	return $result;
}

function corsivo_forms_activity_handle_clear() {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html('Non hai i permessi per eseguire questa operazione.'), '', ['response' => 403]);
	}

	check_admin_referer('corsivo_forms_clear_activity');
	$result = corsivo_forms_activity_clear();
	$failed = $result === false || is_wp_error($result);
	$args = [
		'page' => 'corsivo-forms-settings',
		'tab'  => 'activity',
	];

	$args[$failed ? 'activity_clear_error' : 'activity_cleared'] = '1';
	wp_safe_redirect(add_query_arg($args, admin_url('tools.php')));
	exit;
}

function corsivo_forms_activity_log_settings_update($old_value, $value) {
	$old_settings = corsivo_forms_normalize_settings($old_value);
	$new_settings = corsivo_forms_normalize_settings($value);
	if ($old_settings === $new_settings) {
		return;
	}

	$changes = [];
	foreach (array_keys(corsivo_forms_settings_defaults()) as $key) {
		if ($old_settings[$key] === $new_settings[$key]) {
			continue;
		}
		$old_setting = is_bool($old_settings[$key]) ? ($old_settings[$key] ? 'true' : 'false') : (string) $old_settings[$key];
		$new_setting = is_bool($new_settings[$key]) ? ($new_settings[$key] ? 'true' : 'false') : (string) $new_settings[$key];
		$changes[] = $key . ': ' . $old_setting . ' → ' . $new_setting;
	}

	$allow_when_disabled = !empty($old_settings['log_enabled']) && empty($new_settings['log_enabled']);
	return corsivo_forms_activity_insert_event('settings_updated', 'success', [
		'actor_id' => get_current_user_id(),
		'message'  => implode('; ', $changes),
	], $allow_when_disabled);
}

<?php

if (!defined('ABSPATH')) {
	exit;
}

const CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION = 'corsivo_forms_activity_schema_intent';
const CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION = 'corsivo_forms_activity_schema_verified';
const CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION = 'corsivo_forms_activity_schema_failure';
const CORSIVO_FORMS_ACTIVITY_SCHEMA_MARKER_FORMAT = 1;
const CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_FORMAT = 1;
const CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_FORMAT = 1;
const CORSIVO_FORMS_ACTIVITY_SCHEMA_SCOPE = 'corsivo-forms:activity';
const CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFICATION_INTERVAL = 86400;
const CORSIVO_FORMS_ACTIVITY_SCHEMA_ATTEMPT_TRANSIENT = 'corsivo_forms_activity_schema_verification_attempt';

function corsivo_forms_activity_table_name() {
	global $wpdb;

	return $wpdb->prefix . 'corsivo_forms_activity';
}

function corsivo_forms_activity_schema_canonicalize($value) {
	if (!is_array($value)) {
		return $value;
	}

	if (array_is_list($value)) {
		return array_map('corsivo_forms_activity_schema_canonicalize', $value);
	}

	ksort($value, SORT_STRING);
	foreach ($value as $key => $item) {
		$value[$key] = corsivo_forms_activity_schema_canonicalize($item);
	}

	return $value;
}

function corsivo_forms_activity_schema_namespace() {
	global $wpdb;

	return [
		'blog_id' => get_current_blog_id(),
		'prefix'  => $wpdb->prefix,
		'table'   => corsivo_forms_activity_table_name(),
	];
}

function corsivo_forms_activity_schema_inventory() {
	global $wpdb;

	$prefix = $wpdb->prefix . 'corsivo_forms_';
	$wpdb->last_error = '';
	$tables = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT TABLE_NAME FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE()
			 AND LEFT(TABLE_NAME, CHAR_LENGTH(%s)) = %s
			 ORDER BY TABLE_NAME ASC',
			$prefix,
			$prefix
		)
	);
	if (!is_array($tables) || $wpdb->last_error !== '') {
		return new WP_Error('corsivo_forms_activity_schema_inventory_failed', 'Inventario dello storage non riuscito.');
	}

	return array_values(array_map('strval', $tables));
}

function corsivo_forms_activity_schema_inventory_is_current() {
	$inventory = corsivo_forms_activity_schema_inventory();

	return is_array($inventory) && $inventory === [corsivo_forms_activity_table_name()];
}

function corsivo_forms_activity_schema_has_exact_keys($value, $expected_keys) {
	if (!is_array($value)) {
		return false;
	}

	$keys = array_keys($value);
	sort($keys, SORT_STRING);
	sort($expected_keys, SORT_STRING);

	return $keys === $expected_keys;
}

function corsivo_forms_activity_schema_valid_timestamp($value) {
	if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
		return false;
	}

	$timestamp = DateTimeImmutable::createFromFormat(
		'!Y-m-d H:i:s',
		$value,
		new DateTimeZone('UTC')
	);

	return $timestamp instanceof DateTimeImmutable && $timestamp->format('Y-m-d H:i:s') === $value;
}

function corsivo_forms_activity_schema_write_option($name, $value) {
	if (!update_option($name, $value, false) && get_option($name, null) !== $value) {
		return false;
	}

	return get_option($name, null) === $value;
}

function corsivo_forms_activity_schema_delete_option($name) {
	if (!corsivo_forms_activity_schema_option_record($name)['exists']) {
		return true;
	}
	delete_option($name);

	return !corsivo_forms_activity_schema_option_record($name)['exists'];
}

function corsivo_forms_activity_schema_option_record($name) {
	$missing = new stdClass();
	$value = get_option($name, $missing);

	return [
		'exists' => $value !== $missing,
		'value'  => $value !== $missing ? $value : null,
	];
}

function corsivo_forms_activity_schema_quoted_table() {
	$table = corsivo_forms_activity_table_name();
	if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
		return new WP_Error('corsivo_forms_activity_schema_table_invalid', 'Nome tabella non valido.');
	}

	return '`' . $table . '`';
}

function corsivo_forms_activity_schema_sql() {
	global $wpdb;

	$quoted_table = corsivo_forms_activity_schema_quoted_table();
	if (is_wp_error($quoted_table)) {
		return $quoted_table;
	}
	$charset_collate = $wpdb->get_charset_collate();

	return "CREATE TABLE {$quoted_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		request_id VARCHAR(64) NOT NULL,
		request_fingerprint CHAR(64) NOT NULL DEFAULT '',
		event VARCHAR(64) NOT NULL DEFAULT '',
		status VARCHAR(32) NOT NULL DEFAULT '',
		form_id VARCHAR(64) NOT NULL DEFAULT '',
		form_title VARCHAR(191) NOT NULL DEFAULT '',
		submission_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		storage_status VARCHAR(32) NOT NULL DEFAULT '',
		notification_status VARCHAR(32) NOT NULL DEFAULT '',
		error_code VARCHAR(64) NOT NULL DEFAULT '',
		message TEXT NOT NULL,
		response LONGTEXT NOT NULL,
		created_at_gmt DATETIME NOT NULL,
		updated_at_gmt DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY request_id (request_id),
		KEY event_status_created (event, status, created_at_gmt),
		KEY event_created (event, created_at_gmt),
		KEY status_created (status, created_at_gmt),
		KEY form_created (form_id, created_at_gmt),
		KEY submission_created (submission_id, created_at_gmt),
		KEY created_at_gmt (created_at_gmt),
		KEY updated_at_gmt (updated_at_gmt)
	) ENGINE=InnoDB {$charset_collate}";
}

function corsivo_forms_activity_schema_source_fingerprint() {
	$sql = corsivo_forms_activity_schema_sql();
	if (is_wp_error($sql)) {
		return '';
	}

	return hash('sha256', 'corsivo-forms:activity-schema-source:v1:' . $sql);
}

function corsivo_forms_activity_schema_normalize_default($value) {
	if ($value === null) {
		return null;
	}

	return str_replace('current_timestamp()', 'current_timestamp', strtolower(trim((string) $value)));
}

function corsivo_forms_activity_schema_normalize_type($value) {
	return preg_replace(
		'/\b(bigint|int|mediumint|smallint|tinyint)\(\d+\)/',
		'$1',
		strtolower(trim((string) $value))
	);
}

function corsivo_forms_activity_schema_describe($include_rows = true) {
	global $wpdb;

	$table = corsivo_forms_activity_table_name();
	$quoted_table = corsivo_forms_activity_schema_quoted_table();
	if (is_wp_error($quoted_table)) {
		return $quoted_table;
	}

	$wpdb->last_error = '';
	$status = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		),
		ARRAY_A
	);
	if (!$status) {
		return $wpdb->last_error === ''
			? null
			: new WP_Error('corsivo_forms_activity_schema_inspection_failed', 'Ispezione dello schema non riuscita.');
	}

	$columns = $wpdb->get_results("SHOW FULL COLUMNS FROM {$quoted_table}", ARRAY_A);
	$indexes = $wpdb->get_results("SHOW INDEX FROM {$quoted_table}", ARRAY_A);
	$create_row = $wpdb->get_row("SHOW CREATE TABLE {$quoted_table}", ARRAY_N);
	$rows = $include_rows ? $wpdb->get_var("SELECT COUNT(*) FROM {$quoted_table}") : null;
	if (!is_array($columns)
		|| !is_array($indexes)
		|| !is_array($create_row)
		|| ($include_rows && $rows === null)
		|| $wpdb->last_error !== '') {
		return new WP_Error('corsivo_forms_activity_schema_inspection_failed', 'Ispezione dello schema non riuscita.');
	}

	$normalized_columns = [];
	foreach ($columns as $column) {
		$normalized_columns[] = [
			'name'      => (string) $column['Field'],
			'type'      => corsivo_forms_activity_schema_normalize_type($column['Type']),
			'nullable'  => (string) $column['Null'] === 'YES',
			'default'   => corsivo_forms_activity_schema_normalize_default($column['Default']),
			'collation' => $column['Collation'] === null ? null : strtolower((string) $column['Collation']),
			'extra'     => strtolower(preg_replace('/\s+/', ' ', trim((string) $column['Extra']))),
		];
	}

	$normalized_indexes = [];
	foreach ($indexes as $index) {
		$name = (string) $index['Key_name'];
		$sequence = (int) $index['Seq_in_index'];
		$normalized_indexes[$name]['unique'] = (int) $index['Non_unique'] === 0;
		$normalized_indexes[$name]['type'] = strtoupper((string) $index['Index_type']);
		$normalized_indexes[$name]['columns'][$sequence] = [
			'name'      => (string) ($index['Column_name'] ?? ''),
			'sub_part'  => $index['Sub_part'] === null ? null : (int) $index['Sub_part'],
			'collation' => $index['Collation'] === null ? null : (string) $index['Collation'],
		];
	}
	foreach ($normalized_indexes as &$index) {
		ksort($index['columns'], SORT_NUMERIC);
		$index['columns'] = array_values($index['columns']);
	}
	unset($index);
	ksort($normalized_indexes, SORT_STRING);

	$create = preg_replace('/\s+AUTO_INCREMENT=\d+\b/i', '', trim((string) ($create_row[1] ?? '')));

	$descriptor = [
		'engine'    => strtolower((string) ($status['ENGINE'] ?? '')),
		'collation' => strtolower((string) ($status['TABLE_COLLATION'] ?? '')),
		'columns'   => $normalized_columns,
		'indexes'   => $normalized_indexes,
		'create'    => strtolower(preg_replace('/\s+/', ' ', $create)),
	];
	if ($include_rows) {
		$descriptor['rows'] = (int) $rows;
	}

	return $descriptor;
}

function corsivo_forms_activity_schema_expected_column($name, $type, $nullable, $default, $collation, $extra = '') {
	return [
		'name'      => $name,
		'type'      => $type,
		'nullable'  => $nullable,
		'default'   => $default,
		'collation' => $collation,
		'extra'     => $extra,
	];
}

function corsivo_forms_activity_schema_expected_index($unique, $columns) {
	return [
		'unique'  => $unique,
		'type'    => 'BTREE',
		'columns' => array_map(
			static fn($name) => [
				'name'      => $name,
				'sub_part'  => null,
				'collation' => 'A',
			],
			$columns
		),
	];
}

function corsivo_forms_activity_schema_expected_descriptor($collation) {
	return [
		'engine'    => 'innodb',
		'collation' => $collation,
		'columns'   => [
			corsivo_forms_activity_schema_expected_column('id', 'bigint unsigned', false, null, null, 'auto_increment'),
			corsivo_forms_activity_schema_expected_column('request_id', 'varchar(64)', false, null, $collation),
			corsivo_forms_activity_schema_expected_column('request_fingerprint', 'char(64)', false, '', $collation),
			corsivo_forms_activity_schema_expected_column('event', 'varchar(64)', false, '', $collation),
			corsivo_forms_activity_schema_expected_column('status', 'varchar(32)', false, '', $collation),
			corsivo_forms_activity_schema_expected_column('form_id', 'varchar(64)', false, '', $collation),
			corsivo_forms_activity_schema_expected_column('form_title', 'varchar(191)', false, '', $collation),
			corsivo_forms_activity_schema_expected_column('submission_id', 'bigint unsigned', false, '0', null),
			corsivo_forms_activity_schema_expected_column('page_id', 'bigint unsigned', false, '0', null),
			corsivo_forms_activity_schema_expected_column('actor_id', 'bigint unsigned', false, '0', null),
			corsivo_forms_activity_schema_expected_column('storage_status', 'varchar(32)', false, '', $collation),
			corsivo_forms_activity_schema_expected_column('notification_status', 'varchar(32)', false, '', $collation),
			corsivo_forms_activity_schema_expected_column('error_code', 'varchar(64)', false, '', $collation),
			corsivo_forms_activity_schema_expected_column('message', 'text', false, null, $collation),
			corsivo_forms_activity_schema_expected_column('response', 'longtext', false, null, $collation),
			corsivo_forms_activity_schema_expected_column('created_at_gmt', 'datetime', false, null, null),
			corsivo_forms_activity_schema_expected_column('updated_at_gmt', 'datetime', false, null, null),
		],
		'indexes'   => [
			'PRIMARY'             => corsivo_forms_activity_schema_expected_index(true, ['id']),
			'created_at_gmt'      => corsivo_forms_activity_schema_expected_index(false, ['created_at_gmt']),
			'event_created'       => corsivo_forms_activity_schema_expected_index(false, ['event', 'created_at_gmt']),
			'event_status_created' => corsivo_forms_activity_schema_expected_index(false, ['event', 'status', 'created_at_gmt']),
			'form_created'        => corsivo_forms_activity_schema_expected_index(false, ['form_id', 'created_at_gmt']),
			'request_id'          => corsivo_forms_activity_schema_expected_index(true, ['request_id']),
			'status_created'      => corsivo_forms_activity_schema_expected_index(false, ['status', 'created_at_gmt']),
			'submission_created'  => corsivo_forms_activity_schema_expected_index(false, ['submission_id', 'created_at_gmt']),
			'updated_at_gmt'      => corsivo_forms_activity_schema_expected_index(false, ['updated_at_gmt']),
		],
	];
}

function corsivo_forms_activity_schema_create_is_current($create, $collation) {
	global $wpdb;

	if (!is_string($create) || $create === '' || $collation === '') {
		return false;
	}

	$charset = strtolower(trim((string) $wpdb->charset));
	if ($charset === '' && str_contains($collation, '_')) {
		$charset = strstr($collation, '_', true);
	}
	if ($charset === '') {
		return false;
	}

	$normalized_create = strtolower(trim($create));
	$expected_start = 'create table `' . strtolower(corsivo_forms_activity_table_name()) . '` ( ';
	$expected_end = ') engine=innodb default charset=' . $charset . ' collate=' . $collation;
	if (!str_starts_with($normalized_create, $expected_start)
		|| !str_ends_with($normalized_create, $expected_end)) {
		return false;
	}

	foreach ([' foreign key ', ' constraint ', ' check (', ' partition by ', ' row_format=', ' tablespace '] as $forbidden) {
		if (str_contains($normalized_create, $forbidden)) {
			return false;
		}
	}

	return true;
}

function corsivo_forms_activity_schema_descriptor_is_current($descriptor) {
	global $wpdb;

	if (!is_array($descriptor) || ($descriptor['engine'] ?? '') !== 'innodb') {
		return false;
	}

	$collation = strtolower((string) ($descriptor['collation'] ?? ''));
	$configured_collation = strtolower(trim((string) $wpdb->collate));
	if ($collation === '' || ($configured_collation !== '' && $collation !== $configured_collation)) {
		return false;
	}

	$expected = corsivo_forms_activity_schema_expected_descriptor($collation);

	return ($descriptor['columns'] ?? null) === $expected['columns']
		&& ($descriptor['indexes'] ?? null) === $expected['indexes']
		&& corsivo_forms_activity_schema_create_is_current($descriptor['create'] ?? null, $collation);
}

function corsivo_forms_activity_schema_physical_fingerprint($descriptor) {
	if (!is_array($descriptor)) {
		return '';
	}

	unset($descriptor['rows']);
	$encoded = wp_json_encode(
		corsivo_forms_activity_schema_canonicalize($descriptor),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);

	return is_string($encoded)
		? hash('sha256', 'corsivo-forms:activity-schema-physical:v1:' . $encoded)
		: '';
}

function corsivo_forms_activity_schema_verified_payload() {
	$marker = get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, null);
	if (!corsivo_forms_activity_schema_has_exact_keys($marker, [
		'kind',
		'marker_format',
		'namespace',
		'physical_fingerprint',
		'scope',
		'source_fingerprint',
		'verified_at',
	])) {
		return false;
	}

	$source_fingerprint = corsivo_forms_activity_schema_source_fingerprint();
	if ($marker['marker_format'] !== CORSIVO_FORMS_ACTIVITY_SCHEMA_MARKER_FORMAT
		|| $marker['kind'] !== 'verified'
		|| $marker['scope'] !== CORSIVO_FORMS_ACTIVITY_SCHEMA_SCOPE
		|| $marker['namespace'] !== corsivo_forms_activity_schema_namespace()
		|| !preg_match('/^[a-f0-9]{64}$/D', (string) $marker['source_fingerprint'])
		|| $marker['source_fingerprint'] !== $source_fingerprint
		|| !preg_match('/^[a-f0-9]{64}$/D', (string) $marker['physical_fingerprint'])
		|| !corsivo_forms_activity_schema_valid_timestamp($marker['verified_at'])) {
		return false;
	}

	return $marker;
}


function corsivo_forms_activity_schema_marker_is_reattestable($marker) {
	return corsivo_forms_activity_schema_has_exact_keys($marker, [
		'kind',
		'marker_format',
		'namespace',
		'physical_fingerprint',
		'scope',
		'source_fingerprint',
		'verified_at',
	])
		&& $marker['marker_format'] === CORSIVO_FORMS_ACTIVITY_SCHEMA_MARKER_FORMAT
		&& $marker['kind'] === 'verified'
		&& $marker['scope'] === CORSIVO_FORMS_ACTIVITY_SCHEMA_SCOPE
		&& $marker['namespace'] === corsivo_forms_activity_schema_namespace()
		&& preg_match('/^[a-f0-9]{64}$/D', (string) $marker['source_fingerprint'])
		&& preg_match('/^[a-f0-9]{64}$/D', (string) $marker['physical_fingerprint'])
		&& corsivo_forms_activity_schema_valid_timestamp($marker['verified_at']);
}

function corsivo_forms_activity_schema_marker_is_stale($marker = null) {
	$marker = is_array($marker) ? $marker : corsivo_forms_activity_schema_verified_payload();
	if (!is_array($marker)) {
		return true;
	}

	$verified_at = DateTimeImmutable::createFromFormat(
		'!Y-m-d H:i:s',
		$marker['verified_at'],
		new DateTimeZone('UTC')
	);
	if (!$verified_at instanceof DateTimeImmutable) {
		return true;
	}

	$verified_timestamp = $verified_at->getTimestamp();
	$current_timestamp = time();

	return $verified_timestamp > $current_timestamp + 300
		|| $verified_timestamp <= $current_timestamp - CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFICATION_INTERVAL;
}

function corsivo_forms_activity_schema_is_ready() {
	if (!empty($GLOBALS['corsivo_forms_activity_schema_release_failed'])
		|| corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION)['exists']
		|| corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION)['exists']
		|| is_wp_error(corsivo_forms_activity_schema_future_format_error())) {
		return false;
	}
	return is_array(corsivo_forms_activity_schema_verified_payload());
}

function corsivo_forms_activity_schema_verification_due() {
	$marker = corsivo_forms_activity_schema_verified_payload();

	return corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION)['exists']
		|| corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION)['exists']
		|| !is_array($marker)
		|| corsivo_forms_activity_schema_marker_is_stale($marker);
}

function corsivo_forms_activity_schema_future_format_error() {
	$states = [
		[
			'current' => CORSIVO_FORMS_ACTIVITY_SCHEMA_MARKER_FORMAT,
			'key'     => 'marker_format',
			'name'    => 'marker',
			'option'  => CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION,
		],
		[
			'current' => CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_FORMAT,
			'key'     => 'intent_format',
			'name'    => 'intent',
			'option'  => CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION,
		],
		[
			'current' => CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_FORMAT,
			'key'     => 'failure_format',
			'name'    => 'failure',
			'option'  => CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION,
		],
	];

	foreach ($states as $state_definition) {
		$state = get_option($state_definition['option'], null);
		$format = is_array($state) ? ($state[$state_definition['key']] ?? null) : null;
		$is_numeric_format = is_int($format) || (is_string($format) && ctype_digit($format));
		if ($is_numeric_format && (int) $format > $state_definition['current']) {
			return new WP_Error(
				'corsivo_forms_activity_schema_future_' . $state_definition['name'] . '_format',
				'Lo stato storage usa un formato più recente di quello supportato.'
			);
		}
	}

	return null;
}

function corsivo_forms_activity_schema_record_failure($error) {
	if (is_wp_error(corsivo_forms_activity_schema_future_format_error())) {
		return $error;
	}

	$failure = [
		'code'               => is_wp_error($error) ? $error->get_error_code() : 'corsivo_forms_activity_schema_failed',
		'failure_format'     => CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_FORMAT,
		'failed_at'          => gmdate('Y-m-d H:i:s'),
		'kind'               => 'failure',
		'namespace'          => corsivo_forms_activity_schema_namespace(),
		'scope'              => CORSIVO_FORMS_ACTIVITY_SCHEMA_SCOPE,
		'source_fingerprint' => corsivo_forms_activity_schema_source_fingerprint(),
	];
	if (!corsivo_forms_activity_schema_write_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION, $failure)) {
		corsivo_forms_activity_schema_delete_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION);
	}

	return $error;
}

function corsivo_forms_activity_schema_failure_payload() {
	$failure = corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION);
	if (!$failure['exists'] || !corsivo_forms_activity_schema_has_exact_keys($failure['value'], [
		'code',
		'failure_format',
		'failed_at',
		'kind',
		'namespace',
		'scope',
		'source_fingerprint',
	])) {
		return false;
	}
	$failure = $failure['value'];

	return $failure['failure_format'] === CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_FORMAT
		&& $failure['kind'] === 'failure'
		&& $failure['namespace'] === corsivo_forms_activity_schema_namespace()
		&& $failure['scope'] === CORSIVO_FORMS_ACTIVITY_SCHEMA_SCOPE
		&& is_string($failure['code'])
		&& '' !== $failure['code']
		&& preg_match('/^[a-f0-9]{64}$/D', (string) $failure['source_fingerprint'])
		&& $failure['source_fingerprint'] === corsivo_forms_activity_schema_source_fingerprint()
		&& corsivo_forms_activity_schema_valid_timestamp($failure['failed_at'])
		? $failure
		: false;
}

function corsivo_forms_activity_schema_fresh_intent() {
	try {
		$token = bin2hex(random_bytes(32));
	} catch (Throwable $error) {
		return new WP_Error('corsivo_forms_activity_schema_intent_token_failed', 'Creazione del token di installazione non riuscita.');
	}

	return [
		'intent_format'      => CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_FORMAT,
		'kind'               => 'fresh',
		'namespace'          => corsivo_forms_activity_schema_namespace(),
		'operation_id'       => wp_generate_uuid4(),
		'scope'              => CORSIVO_FORMS_ACTIVITY_SCHEMA_SCOPE,
		'source_fingerprint' => corsivo_forms_activity_schema_source_fingerprint(),
		'started_at'         => gmdate('Y-m-d H:i:s'),
		'token'              => $token,
	];
}

function corsivo_forms_activity_schema_intent_payload() {
	$intent = get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION, null);
	if (!corsivo_forms_activity_schema_has_exact_keys($intent, [
		'intent_format',
		'kind',
		'namespace',
		'operation_id',
		'scope',
		'source_fingerprint',
		'started_at',
		'token',
	])) {
		return false;
	}

	return $intent['intent_format'] === CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_FORMAT
		&& $intent['kind'] === 'fresh'
		&& $intent['scope'] === CORSIVO_FORMS_ACTIVITY_SCHEMA_SCOPE
		&& $intent['namespace'] === corsivo_forms_activity_schema_namespace()
		&& preg_match('/^[a-f0-9-]{36}$/D', (string) $intent['operation_id'])
		&& preg_match('/^[a-f0-9]{64}$/D', (string) $intent['token'])
		&& preg_match('/^[a-f0-9]{64}$/D', (string) $intent['source_fingerprint'])
		&& $intent['source_fingerprint'] === corsivo_forms_activity_schema_source_fingerprint()
		&& corsivo_forms_activity_schema_valid_timestamp($intent['started_at'])
		? $intent
		: false;
}

function corsivo_forms_activity_schema_has_valid_fresh_intent() {
	return is_array(corsivo_forms_activity_schema_intent_payload());
}

function corsivo_forms_activity_schema_ensure_fresh_intent() {
	$intent_record = corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION);
	if ($intent_record['exists']) {
		$intent = corsivo_forms_activity_schema_intent_payload();
		return is_array($intent)
			? $intent
			: new WP_Error('corsivo_forms_activity_schema_fresh_state_conflict', 'Lo storage vuoto contiene un intent non verificabile.');
	}
	if (corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION)['exists']
		|| corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION)['exists']) {
		return new WP_Error('corsivo_forms_activity_schema_fresh_state_conflict', 'Lo storage vuoto contiene artefatti che non autorizzano una nuova installazione.');
	}

	$intent = corsivo_forms_activity_schema_fresh_intent();
	if (is_wp_error($intent)) {
		return $intent;
	}
	if (!add_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION, $intent, '', false)) {
		$winner = corsivo_forms_activity_schema_intent_payload();
		if (is_array($winner)) {
			return $winner;
		}
		return corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION)['exists']
			? new WP_Error('corsivo_forms_activity_schema_fresh_state_conflict', 'Un intent concorrente non è verificabile.')
			: new WP_Error('corsivo_forms_activity_schema_intent_write_failed', 'Scrittura dell’intent non riuscita.');
	}

	if (get_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION, null) === $intent) {
		return $intent;
	}
	return corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION)['exists']
		? new WP_Error('corsivo_forms_activity_schema_fresh_state_conflict', 'L’intent appena creato è stato sostituito.')
		: new WP_Error('corsivo_forms_activity_schema_intent_write_failed', 'Scrittura dell’intent non verificata.');
}

function corsivo_forms_activity_schema_create_current($expected_intent) {
	global $wpdb;

	if (corsivo_forms_activity_schema_intent_payload() !== $expected_intent) {
		return new WP_Error('corsivo_forms_activity_schema_intent_fence_lost', 'L’intent di installazione non è più quello atteso.');
	}
	$sql = corsivo_forms_activity_schema_sql();
	if (is_wp_error($sql)) {
		return $sql;
	}
	if ($wpdb->query($sql) === false) {
		return new WP_Error(
			'corsivo_forms_activity_schema_create_failed',
			'Creazione dello schema non riuscita.',
			['db_error' => $wpdb->last_error]
		);
	}
	if (corsivo_forms_activity_schema_intent_payload() !== $expected_intent) {
		return new WP_Error('corsivo_forms_activity_schema_intent_fence_lost', 'L’intent di installazione è cambiato durante la creazione.');
	}

	return true;
}

function corsivo_forms_activity_schema_finalize($descriptor, $expected_verified_record, $expected_intent_record, $expected_failure_record) {
	$future_format = corsivo_forms_activity_schema_future_format_error();
	if (is_wp_error($future_format)) {
		return $future_format;
	}
	if (corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION) !== $expected_verified_record
		|| corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION) !== $expected_intent_record
		|| corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION) !== $expected_failure_record) {
		return new WP_Error('corsivo_forms_activity_schema_finalize_fence_lost', 'Gli artefatti storage sono cambiati prima della finalizzazione.');
	}

	$descriptor = is_array($descriptor) ? $descriptor : corsivo_forms_activity_schema_describe();
	if (is_wp_error($descriptor)) {
		return $descriptor;
	}
	if (!corsivo_forms_activity_schema_inventory_is_current()
		|| !corsivo_forms_activity_schema_descriptor_is_current($descriptor)) {
		return new WP_Error('corsivo_forms_activity_schema_target_invalid', 'Lo schema corrente non è verificabile.');
	}

	$physical_fingerprint = corsivo_forms_activity_schema_physical_fingerprint($descriptor);
	if (!preg_match('/^[a-f0-9]{64}$/D', $physical_fingerprint)) {
		return new WP_Error('corsivo_forms_activity_schema_fingerprint_failed', 'Fingerprint fisico dello schema non valido.');
	}

	$verified = [
		'kind'                 => 'verified',
		'marker_format'        => CORSIVO_FORMS_ACTIVITY_SCHEMA_MARKER_FORMAT,
		'namespace'            => corsivo_forms_activity_schema_namespace(),
		'physical_fingerprint' => $physical_fingerprint,
		'scope'                => CORSIVO_FORMS_ACTIVITY_SCHEMA_SCOPE,
		'source_fingerprint'   => corsivo_forms_activity_schema_source_fingerprint(),
		'verified_at'          => gmdate('Y-m-d H:i:s'),
	];
	if (!corsivo_forms_activity_schema_write_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION, $verified)
		|| !corsivo_forms_activity_schema_delete_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION)
		|| !corsivo_forms_activity_schema_delete_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION)) {
		return new WP_Error('corsivo_forms_activity_schema_attestation_failed', 'Attestazione dello schema non riuscita.');
	}

	return true;
}

function corsivo_forms_activity_schema_with_lock($callback) {
	global $wpdb;

	$resource = wp_json_encode(corsivo_forms_activity_schema_namespace(), JSON_UNESCAPED_SLASHES);
	$lock_name = substr('corsivo_forms_' . hash('sha256', (string) $resource), 0, 64);
	$acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 10));
	if ((string) $acquired !== '1') {
		return new WP_Error('corsivo_forms_activity_schema_lock_timeout', 'Verifica schema già in corso.');
	}

	$result = null;
	$released = null;
	try {
		try {
			$result = call_user_func($callback);
		} catch (Throwable $error) {
			$result = new WP_Error(
				'corsivo_forms_activity_schema_exception',
				'Installazione schema interrotta.',
				['exception' => get_class($error)]
			);
		}
		if (is_wp_error($result) && !str_starts_with($result->get_error_code(), 'corsivo_forms_activity_schema_future_')
			&& !in_array($result->get_error_code(), [
				'corsivo_forms_activity_schema_fresh_state_conflict',
				'corsivo_forms_activity_schema_intent_fence_lost',
				'corsivo_forms_activity_schema_finalize_fence_lost',
				'corsivo_forms_activity_schema_lock_release_failed',
				'corsivo_forms_activity_schema_lock_timeout',
			], true)) {
			$result = corsivo_forms_activity_schema_record_failure($result);
		}
	} finally {
		$released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
	}

	if ((string) $released !== '1') {
		$GLOBALS['corsivo_forms_activity_schema_release_failed'] = true;
		corsivo_forms_activity_schema_schedule_verification(60);
		return new WP_Error('corsivo_forms_activity_schema_lock_release_failed', 'Rilascio del lock schema non riuscito.');
	}
	$GLOBALS['corsivo_forms_activity_schema_release_failed'] = false;

	return $result;
}

function corsivo_forms_activity_schema_verify_and_self_heal($force = false) {
	if (!$force && !corsivo_forms_activity_schema_verification_due()) {
		return true;
	}
	if (!$force && get_transient(CORSIVO_FORMS_ACTIVITY_SCHEMA_ATTEMPT_TRANSIENT) !== false) {
		return corsivo_forms_activity_require_storage();
	}

	set_transient(CORSIVO_FORMS_ACTIVITY_SCHEMA_ATTEMPT_TRANSIENT, '1', 300);
	$result = corsivo_forms_activity_schema_with_lock(static function() {
		$future_format = corsivo_forms_activity_schema_future_format_error();
		if (is_wp_error($future_format)) {
			return $future_format;
		}

		$inventory = corsivo_forms_activity_schema_inventory();
		if (is_wp_error($inventory)) {
			return $inventory;
		}
		if (!in_array($inventory, [[], [corsivo_forms_activity_table_name()]], true)) {
			return new WP_Error(
				'corsivo_forms_activity_schema_namespace_untrusted',
				'Il namespace dello storage contiene tabelle inattese.'
			);
		}

		$descriptor = corsivo_forms_activity_schema_describe();
		if (is_wp_error($descriptor)) {
			return $descriptor;
		}
		$intent_record = corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION);
		$intent = $intent_record['exists'] ? corsivo_forms_activity_schema_intent_payload() : null;
		if ($intent_record['exists'] && !is_array($intent)) {
			return new WP_Error('corsivo_forms_activity_schema_fresh_state_conflict', 'L’intent storage non è verificabile.');
		}
		$failure_record = corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION);
		if ($failure_record['exists'] && !is_array(corsivo_forms_activity_schema_failure_payload())) {
			return new WP_Error('corsivo_forms_activity_schema_fresh_state_conflict', 'Lo stato di errore storage non è verificabile.');
		}
		$verified_record = corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION);
		if ($verified_record['exists']
			&& !corsivo_forms_activity_schema_marker_is_reattestable($verified_record['value'])) {
			return new WP_Error('corsivo_forms_activity_schema_fresh_state_conflict', 'Il marker storage non è verificabile.');
		}
		if (is_array($descriptor) && corsivo_forms_activity_schema_descriptor_is_current($descriptor)) {
			return corsivo_forms_activity_schema_finalize($descriptor, $verified_record, $intent_record, $failure_record);
		}

		if ($descriptor === null) {
			if ($inventory !== []) {
				return new WP_Error('corsivo_forms_activity_schema_inventory_drift', 'Inventario e descriptor dello storage non coincidono.');
			}
			if ($verified_record['exists']) {
				return new WP_Error('corsivo_forms_activity_schema_fresh_state_conflict', 'Un marker preesistente non autorizza una nuova installazione.');
			}

			$intent = corsivo_forms_activity_schema_ensure_fresh_intent();
			if (is_wp_error($intent)) {
				return $intent;
			}
			$intent_record = corsivo_forms_activity_schema_option_record(CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION);
		} else {
			return new WP_Error(
				'corsivo_forms_activity_schema_incompatible',
				'Lo storage esistente non corrisponde al blueprint corrente.'
			);
		}

		$created = corsivo_forms_activity_schema_create_current($intent);
		if (is_wp_error($created)) {
			return $created;
		}

		$created_descriptor = corsivo_forms_activity_schema_describe();
		return is_wp_error($created_descriptor)
			? $created_descriptor
			: corsivo_forms_activity_schema_finalize($created_descriptor, $verified_record, $intent_record, $failure_record);
	});

	if (!is_wp_error($result)) {
		delete_transient(CORSIVO_FORMS_ACTIVITY_SCHEMA_ATTEMPT_TRANSIENT);
		wp_clear_scheduled_hook('corsivo_forms_activity_schema_verify');
	}

	return $result;
}

function corsivo_forms_activity_install() {
	return corsivo_forms_activity_schema_verify_and_self_heal(true);
}

function corsivo_forms_activity_require_storage() {
	return corsivo_forms_activity_schema_is_ready()
		? true
		: new WP_Error(
			'corsivo_forms_activity_schema_not_ready',
			'Il registro attività non è disponibile.',
			['status' => 503]
		);
}

function corsivo_forms_activity_schema_schedule_verification($delay = 60) {
	$scheduled_for = time() + max(1, (int) $delay);
	$existing = wp_next_scheduled('corsivo_forms_activity_schema_verify');
	if ($existing !== false && $existing <= $scheduled_for) {
		return true;
	}
	if ($existing !== false) {
		$unscheduled = wp_unschedule_event($existing, 'corsivo_forms_activity_schema_verify', [], true);
		if (is_wp_error($unscheduled) || $unscheduled === false) {
			return false;
		}
	}

	$scheduled = wp_schedule_single_event(
		$scheduled_for,
		'corsivo_forms_activity_schema_verify',
		[],
		true
	);

	return !is_wp_error($scheduled) && $scheduled !== false;
}

function corsivo_forms_activity_schema_update_runtime_error($maintenance_result = true) {
	$storage = corsivo_forms_activity_require_storage();
	$GLOBALS['corsivo_forms_activity_schema_error'] = is_wp_error($storage)
		? (is_wp_error($maintenance_result) ? $maintenance_result : $storage)
		: null;

	return $storage;
}

function corsivo_forms_activity_verify_boot() {
	if (function_exists('wp_prime_option_caches')) {
		wp_prime_option_caches([
			CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION,
			CORSIVO_FORMS_ACTIVITY_SCHEMA_INTENT_OPTION,
			CORSIVO_FORMS_ACTIVITY_SCHEMA_FAILURE_OPTION,
			'corsivo_forms_settings',
		]);
	}
	if (corsivo_forms_activity_schema_verification_due()) {
		corsivo_forms_activity_schema_schedule_verification();
	}

	return corsivo_forms_activity_schema_update_runtime_error();
}

function corsivo_forms_activity_schema_verify_admin() {
	if (!current_user_can('manage_options') || !corsivo_forms_activity_schema_verification_due()) {
		return;
	}

	$result = corsivo_forms_activity_schema_verify_and_self_heal();
	corsivo_forms_activity_schema_update_runtime_error($result);
}

function corsivo_forms_activity_schema_verify_scheduled() {
	$result = corsivo_forms_activity_schema_verify_and_self_heal();
	corsivo_forms_activity_schema_update_runtime_error($result);
}

function corsivo_forms_activity_schema_verify_after_update($upgrader, $hook_extra) {
	if (($hook_extra['action'] ?? '') !== 'update' || ($hook_extra['type'] ?? '') !== 'plugin') {
		return;
	}

	$plugins = array_values(array_filter(array_merge(
		(array) ($hook_extra['plugins'] ?? []),
		isset($hook_extra['plugin']) ? [(string) $hook_extra['plugin']] : []
	)));
	if (!in_array(plugin_basename(CORSIVO_FORMS_FILE), $plugins, true)) {
		return;
	}

	delete_transient(CORSIVO_FORMS_ACTIVITY_SCHEMA_ATTEMPT_TRANSIENT);
	if (!is_wp_error(corsivo_forms_activity_schema_future_format_error())) {
		corsivo_forms_activity_schema_delete_option(CORSIVO_FORMS_ACTIVITY_SCHEMA_VERIFIED_OPTION);
	}
	corsivo_forms_activity_schema_schedule_verification(1);
}

function corsivo_forms_activity_schema_notice() {
	if (!current_user_can('manage_options') || empty($GLOBALS['corsivo_forms_activity_schema_error'])) {
		return;
	}

	echo '<div class="notice notice-error"><p>' . esc_html($GLOBALS['corsivo_forms_activity_schema_error']->get_error_message()) . '</p></div>';
}

add_action('plugins_loaded', 'corsivo_forms_activity_verify_boot', 20);
add_action('admin_init', 'corsivo_forms_activity_schema_verify_admin', 1);
add_action('corsivo_forms_activity_schema_verify', 'corsivo_forms_activity_schema_verify_scheduled');
add_action('corsivo_forms_daily_cleanup', 'corsivo_forms_activity_schema_verify_scheduled', 1);
add_action('upgrader_process_complete', 'corsivo_forms_activity_schema_verify_after_update', 10, 2);
add_action('admin_notices', 'corsivo_forms_activity_schema_notice');

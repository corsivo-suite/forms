<?php

if (PHP_SAPI !== 'cli') {
	exit(1);
}

define('ABSPATH', dirname(__DIR__, 4) . '/');
define('WPINC', 'wp-includes');
define('CORSIVO_FORMS_FILE', dirname(__DIR__) . '/corsivo-forms.php');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MB_IN_BYTES', 1048576);
require ABSPATH . 'wp-includes/class-wp-error.php';
require ABSPATH . 'wp-includes/compat.php';
require ABSPATH . 'wp-includes/formatting.php';
require ABSPATH . 'wp-includes/utf8.php';
require ABSPATH . 'wp-includes/kses.php';
require ABSPATH . 'wp-includes/class-wp-http-response.php';
require ABSPATH . 'wp-includes/rest-api/class-wp-rest-response.php';
require ABSPATH . 'wp-includes/rest-api/class-wp-rest-request.php';

function __($text) { return $text; }
function absint($value) { return abs((int) $value); }
function is_utf8_charset($charset = null) { return true; }
function _canonical_charset($charset) { return 'UTF-8'; }
function get_allowed_mime_types() { return ['png' => 'image/png', 'pdf' => 'application/pdf']; }
function wp_get_mime_types() { return get_allowed_mime_types(); }
function wp_allowed_protocols() { return ['http', 'https', 'mailto', 'tel']; }
function wp_check_filetype($filename, $mimes = null) { return ['ext' => pathinfo($filename, PATHINFO_EXTENSION), 'type' => 'image/png']; }
function add_action($hook, $callback, $priority = 10, $accepted = 1) { $GLOBALS['hooks'][$hook][] = [$callback, $accepted]; }
function add_filter(...$arguments) {}
function apply_filters($hook, $value, ...$arguments) { return $value; }
function do_action($hook, ...$arguments) { foreach ($GLOBALS['hooks'][$hook] ?? [] as [$callback, $accepted]) { $callback(...array_slice($arguments, 0, $accepted)); } }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function plugin_basename($file) { return $file; }
function wp_salt($purpose) { return 'isolated-forms-fixture-' . $purpose; }
function home_url($path = '') { return 'https://example.test' . $path; }
function get_current_blog_id() { return 1; }
function get_current_network_id() { return 1; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_is_json_media_type($type) { return str_contains((string) $type, 'json'); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function current_time($format, $gmt = false) { return '2026-10-13 12:00:00'; }
function wp_timezone() { return new DateTimeZone('UTC'); }
function wp_date($format, $timestamp, $timezone = null) { return gmdate($format, $timestamp); }
function wp_generate_uuid4() { return sprintf('11111111-2222-4333-8444-%012d', ++$GLOBALS['uuid_sequence']); }
function wp_unique_id($prefix = '') { return $prefix . ++$GLOBALS['uuid_sequence']; }
function wp_using_ext_object_cache() { return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
function rest_ensure_response($value) { return $value instanceof WP_REST_Response ? $value : new WP_REST_Response($value); }
function corsivo_forms_activity_require_storage() { return true; }
function corsivo_forms_activity_get_record($request_id) { return $GLOBALS['activity'][$request_id] ?? null; }
function corsivo_forms_activity_duplicate_response($record) { return $record['response'] ?? new WP_Error('request_in_progress', 'In progress', ['status' => 409]); }
function corsivo_forms_activity_claim_request($request_id, $record) {
	if (isset($GLOBALS['activity'][$request_id])) { return ['claimed' => false, 'record' => $GLOBALS['activity'][$request_id]]; }
	$GLOBALS['activity'][$request_id] = $record;
	return ['claimed' => true, 'record' => $record];
}
function corsivo_forms_activity_update_request($request_id, $changes) { $GLOBALS['activity'][$request_id] = array_merge($GLOBALS['activity'][$request_id], $changes); }
function corsivo_forms_activity_store_response($request_id, $response, $status) { $GLOBALS['activity'][$request_id]['response'] = $response; }
function corsivo_forms_update_notification_status($submission_id, $notification) {}
function corsivo_forms_store_submission($submission) { $GLOBALS['stored_submissions'][] = $submission; return count($GLOBALS['stored_submissions']); }
function wp_mail($recipient, $subject, $body, $headers) { $GLOBALS['mail_attempts'][] = compact('recipient', 'subject', 'body', 'headers'); return true; }

$GLOBALS['options'] = ['admin_email' => 'admin@example.test', 'blog_charset' => 'UTF-8', 'corsivo_forms_settings' => ['allowed_recipients' => 'sales@example.test, support@example.test', 'corsivo_mail_enabled' => false]];
$GLOBALS['uuid_sequence'] = 0;
$GLOBALS['mail_attempts'] = [];
$GLOBALS['activity'] = [];
$GLOBALS['stored_submissions'] = [];
$GLOBALS['transients'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$wpdb = new class {
	public $prefix = 'isolated_';
	public function prepare($query, ...$arguments) { return $query; }
	public function get_var($query) { return '1'; }
};
require dirname(__DIR__) . '/includes/settings.php';
require dirname(__DIR__) . '/includes/conditions.php';
require dirname(__DIR__) . '/includes/fields.php';
require dirname(__DIR__) . '/includes/blocks.php';
require dirname(__DIR__) . '/includes/token.php';
require dirname(__DIR__) . '/includes/mail.php';
require dirname(__DIR__) . '/includes/uploads.php';
require dirname(__DIR__) . '/includes/submit.php';

$checks = 0;
function extension_check($condition, $message) {
	$GLOBALS['checks']++;
	if (!$condition) { throw new RuntimeException($message); }
}
function extension_field($type, $name, $values = []) {
	return ['type' => $type, 'name' => $name, 'values' => corsivo_forms_normalize_field_values($type, array_merge(['field_key' => $name, 'label' => ucfirst($name)], $values))];
}
function extension_definition($fields, $settings = []) {
	$definition = ['version' => 1, 'issued_at' => time(), 'fields' => $fields, 'settings' => corsivo_forms_normalize_form_settings(array_merge(['store' => false], $settings)), 'has_submit' => true, 'is_public' => true, 'page_id' => 0, 'page_title' => '', 'page_url' => '', 'language' => 'it_it', 'source_mode' => 'rendered'];
	$definition['form_id'] = corsivo_forms_definition_form_id($definition['settings'], $fields, $definition);
	return $definition;
}
function extension_request($definition, $values, $request_id) {
	$request = new WP_REST_Request('POST', '/corsivo/v1/forms/submit');
	$request->set_body_params(array_merge($values, ['token' => corsivo_forms_create_form_token($definition), 'formId' => $definition['form_id'], 'request_id' => $request_id]));
	return $request;
}

foreach (json_decode(file_get_contents(__DIR__ . '/conditions-fixtures.json'), true, 512, JSON_THROW_ON_ERROR) as $fixture) {
	$fields = array_map(static fn($field) => extension_field($field['type'], $field['name'], $field['values']), $fixture['fields']);
	extension_check(!is_wp_error(corsivo_forms_validate_condition_graph($fields)), 'Fixture graph: ' . $fixture['name']);
	extension_check(array_column(corsivo_forms_resolve_active_fields($fields, $fixture['values']), 'name') === $fixture['active'], 'Shared condition fixture: ' . $fixture['name']);
}

foreach ([null, 'all', [['field' => []]], [['field' => 'name', 'operator' => 'equal', 'value' => []]], array_fill(0, 11, ['field' => 'name', 'operator' => 'equal', 'value' => ''])] as $invalid) {
	extension_check(is_wp_error(corsivo_forms_normalize_conditions($invalid)), 'Malformed condition schema fails closed');
}
extension_check(!is_wp_error(corsivo_forms_normalize_conditions(array_fill(0, 10, ['field' => 'name', 'operator' => 'equal', 'value' => '']))), 'Exactly ten conditions accepted');
$toggle = extension_field('checkbox', 'toggle');
$conditional = extension_field('text', 'details', ['required' => true, 'conditions' => [['field' => 'toggle', 'operator' => 'equal', 'value' => '1']]]);
$descendant = extension_field('email', 'email', ['required' => true, 'conditions' => [['field' => 'details', 'operator' => 'not_equal', 'value' => 'Sales']]]);
extension_check(array_column(corsivo_forms_resolve_active_fields([$descendant, $conditional, $toggle], ['toggle' => '1', 'details' => 'Support', 'email' => 'person@example.test']), 'name') === ['email', 'details', 'toggle'], 'Dependencies resolve independently from field order');
extension_check(array_column(corsivo_forms_resolve_active_fields([$descendant, $conditional, $toggle], ['toggle' => '0', 'details' => 'Forged', 'email' => 'forged@example.test']), 'name') === ['toggle'], 'Hidden source values cannot activate descendants');
foreach ([['field' => 'missing', 'operator' => 'equal', 'value' => 'x'], ['field' => 'details', 'operator' => 'equal', 'value' => 'x']] as $condition) {
	extension_check(is_wp_error(corsivo_forms_validate_condition_graph([$toggle, extension_field('text', 'details', ['conditions' => [$condition]])])), 'Missing and self source are rejected');
}
$cycle = [extension_field('text', 'first', ['conditions' => [['field' => 'second', 'operator' => 'is_empty']]]), extension_field('text', 'second', ['conditions' => [['field' => 'first', 'operator' => 'is_empty']]])];
extension_check(corsivo_forms_validate_condition_graph($cycle)->get_error_code() === 'condition_cycle', 'Dependency cycles rejected');
extension_check(is_wp_error(corsivo_forms_validate_condition_graph([$toggle, extension_field('privacy', 'privacy', ['conditions' => [['field' => 'toggle', 'operator' => 'equal', 'value' => '1']]])])), 'Privacy cannot be conditional');
foreach (['privacy', 'file'] as $type) {
	extension_check(is_wp_error(corsivo_forms_validate_condition_graph([extension_field($type, 'source'), extension_field('text', 'details', ['conditions' => [['field' => 'source', 'operator' => 'is_empty']]])])), 'Sensitive and upload fields cannot be condition sources');
}
$number = extension_field('number', 'amount', ['min' => '0.1', 'step' => '0.2']);
extension_check(corsivo_forms_validate_field($number, '0.3')['error'] === null, 'Decimal step tolerates representation error');
foreach (['1e309', '0.2', ['1'], true] as $invalid) {
	extension_check(corsivo_forms_validate_field($number, $invalid)['error'] !== null, 'Invalid numeric values rejected');
}
extension_check(corsivo_forms_validate_field(extension_field('number', 'amount'), true)['error'] !== null, 'Boolean rejected independently from numeric step');
extension_check(corsivo_forms_validate_field(extension_field('radio', 'topic', ['options' => [['option' => '1']]]), true)['error'] !== null, 'Boolean cannot select numeric-looking radio option');
foreach (['text', 'privacy'] as $type) {
	$field = extension_field($type, 'details', ['conditions' => null]);
	extension_check(is_wp_error(corsivo_forms_validate_condition_graph([$field])), 'Explicit null conditions fail closed');
	extension_check(is_wp_error(corsivo_forms_validate_condition_graph(corsivo_forms_normalize_definition_fields([$field]))), 'Invalid condition marker survives repeated normalization');
}
$null_mode_field = extension_field('text', 'details', ['condition_mode' => null, 'conditions' => [['field' => 'toggle', 'operator' => 'equal', 'value' => '1']]]);
extension_check(is_wp_error(corsivo_forms_form_configuration_error(extension_definition([$toggle, $null_mode_field]))), 'Explicit null condition mode disables the form');
extension_check(is_wp_error(corsivo_forms_form_configuration_error(extension_definition(corsivo_forms_normalize_definition_fields([$toggle, $null_mode_field])))), 'Null condition mode error survives repeated normalization');
foreach (['select', 'radio'] as $type) {
	$choice_field = extension_field($type, 'topic', ['options' => [['option' => 'Acme &amp; Sons'], ['option' => 'He said "Hello"'], ['option' => '1']]]);
	$html = corsivo_forms_render_field_input($type, $choice_field['name'], $choice_field['values']);
	preg_match_all('/(?:<option|<input)\b[^>]*\bvalue="([^"]*)"/', $html, $attributes);
	$roundtrip_values = array_values(array_filter(array_map(static fn($attribute) => html_entity_decode($attribute, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $attributes[1]), static fn($value) => $value !== ''));
	extension_check($roundtrip_values === corsivo_forms_select_options($choice_field['values']), 'Rendered option attributes retain literal entities, quotes and numeric strings: ' . $type);
	foreach ($roundtrip_values as $value) {
		$validated = corsivo_forms_validate_field($choice_field, $value);
		extension_check($validated['error'] === null && $validated['value'] === $value, 'Browser-decoded option value accepted unchanged: ' . $type);
	}
}
$date = extension_field('date', 'arrival');
extension_check(corsivo_forms_validate_field($date, '2024-02-29')['error'] === null, 'Leap day is valid in leap year');
extension_check(corsivo_forms_validate_field($date, '2025-02-29')['error'] !== null, 'Leap day is invalid in ordinary year');
extension_check(is_wp_error(corsivo_forms_form_configuration_error(extension_definition([extension_field('number', 'amount', ['min' => '10', 'max' => '1'])]))), 'Invalid numeric bounds disable form');
foreach (['min', 'max', 'step'] as $bound) {
	foreach ([true, false] as $invalid) {
		extension_check(is_wp_error(corsivo_forms_form_configuration_error(extension_definition([extension_field('number', 'amount', [$bound => $invalid])]))), 'Boolean numeric bounds rejected');
	}
}

$legacy_settings = ['form_title' => 'Contatti', 'recipients' => '', 'subject' => 'Nuovo messaggio dal sito', 'success_message' => 'Grazie! Il tuo messaggio è stato inviato.', 'store' => true];
$legacy_fields = [['type' => 'text', 'name' => 'nome', 'values' => ['label' => 'Nome', 'field_key' => '', 'required' => true, 'placeholder' => '']]];
extension_check(corsivo_forms_normalize_form_settings(array_merge($legacy_settings, ['notification_rules' => []])) === $legacy_settings, 'Empty notification rules preserve legacy settings bytes');
extension_check(corsivo_forms_normalize_definition_fields($legacy_fields) === $legacy_fields, 'Legacy field normalization preserves bytes');
$legacy_context = ['has_submit' => true, 'is_public' => true];
$legacy_identity = substr(hash('sha256', json_encode(['settings' => $legacy_settings, 'fields' => $legacy_fields, 'has_submit' => true, 'schema_truncated' => false, 'too_many_fields' => false, 'is_public' => true])), 0, 32);
extension_check(corsivo_forms_definition_form_id(corsivo_forms_normalize_form_settings($legacy_settings), corsivo_forms_normalize_definition_fields($legacy_fields), $legacy_context) === $legacy_identity, 'Legacy form identity unchanged');
extension_check($legacy_identity === '9c4c5982c55ad3104e4f7b311e383891', 'Legacy form identity matches fixed pre-extension fixture');
$legacy_token_definition = extension_definition($legacy_fields, $legacy_settings);
$legacy_token_definition['source_mode'] = 'template';
$legacy_token_definition['issued_at'] = time() - 89 * DAY_IN_SECONDS;
$legacy_token = corsivo_forms_create_form_token($legacy_token_definition);
$token_result = corsivo_forms_read_form_token($legacy_token);
extension_check(is_wp_error($token_result) && $token_result->get_error_code() === 'form_changed', 'Legacy template token at 89 days is decoded, not expired');
$legacy_token_definition['source_mode'] = 'post';
extension_check(corsivo_forms_read_form_token(corsivo_forms_create_form_token($legacy_token_definition))->get_error_code() === 'form_changed', 'Legacy post token at 89 days is decoded, not expired');
$legacy_token_definition['issued_at'] = time() - 91 * DAY_IN_SECONDS;
extension_check(corsivo_forms_read_form_token(corsivo_forms_create_form_token($legacy_token_definition))->get_error_code() === 'expired_form_token', 'Legacy token retains ninety day expiry');

$topic = extension_field('radio', 'topic', ['options' => [['option' => 'Sales'], ['option' => 'Support']]]);
$rules = [
	['label' => 'Sales', 'recipients' => 'sales@example.test', 'subject' => 'Sales: {topic}', 'conditions' => [['field' => 'topic', 'operator' => 'equal', 'value' => 'Sales']]],
	['label' => 'Second match', 'recipients' => 'support@example.test', 'subject' => 'Second', 'conditions' => [['field' => 'topic', 'operator' => 'is_not_empty']]],
];
$notification_definition = extension_definition([$topic], ['notification_rules' => $rules]);
extension_check(corsivo_forms_form_configuration_error($notification_definition) === null, 'Authorized notification rules accepted');
$selected = corsivo_forms_select_notification_settings($notification_definition['settings'], [$topic], ['topic' => 'Sales']);
extension_check($selected['recipients'] === 'sales@example.test' && $selected['subject'] === 'Sales: {topic}', 'Only first matching route selects recipients and subject');
$selected = corsivo_forms_select_notification_settings($notification_definition['settings'], [$topic], ['topic' => '']);
extension_check($selected['recipients'] === '' && $selected['subject'] === 'Nuovo messaggio dal sito', 'Unmatched notification uses base configuration');
$bad_rules = $rules;
$bad_rules[1]['recipients'] = 'outsider@example.test';
extension_check(is_wp_error(corsivo_forms_form_configuration_error(extension_definition([$topic], ['notification_rules' => $bad_rules]))), 'All rule recipients verified even when a different rule matches');
foreach ([null, 'invalid', array_fill(0, 11, $rules[0]), [['recipients' => []]], [['recipients' => 'sales@example.test', 'conditions' => []]]] as $invalid) {
	extension_check(is_wp_error(corsivo_forms_form_configuration_error(extension_definition([$topic], ['notification_rules' => $invalid]))), 'Malformed notification routing fails closed');
}
$revoked_token = corsivo_forms_create_form_token($notification_definition);
$GLOBALS['options']['corsivo_forms_settings']['allowed_recipients'] = 'support@example.test';
extension_check(corsivo_forms_read_form_token($revoked_token)->get_error_code() === 'invalid_rule_recipients', 'Revoked rule recipient invalidates previously emitted token');
$GLOBALS['options']['corsivo_forms_settings']['allowed_recipients'] = 'sales@example.test, support@example.test';

$upload = extension_field('file', 'attachment', ['required' => true, 'conditions' => [['field' => 'toggle', 'operator' => 'equal', 'value' => '1']]]);
$upload_request = new WP_REST_Request('POST', '/corsivo/v1/forms/submit');
$upload_request->set_file_params(['attachment' => ['name' => 'forged.png', 'error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'size' => 0]]);
$filtered_request = corsivo_forms_active_upload_request($upload_request, [$toggle, $upload], [$toggle]);
extension_check($filtered_request->get_file_params() === [] && count($upload_request->get_file_params()) === 1, 'Hidden uploads removed on copy without mutating raw request');
$upload_result = corsivo_forms_prepare_request_uploads($filtered_request, [$toggle]);
extension_check(!is_wp_error($upload_result) && $upload_result['prepared']['files'] === [], 'Hidden mandatory upload does not validate or prepare files');
$upload_request->set_file_params(['unknown' => ['name' => 'forged.png', 'error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'size' => 0]]);
$upload_result = corsivo_forms_prepare_request_uploads(corsivo_forms_active_upload_request($upload_request, [$toggle, $upload], [$toggle]), [$toggle]);
extension_check(is_wp_error($upload_result) && $upload_result->get_error_code() === 'corsivo_forms_upload_unexpected_field', 'Unknown upload fields remain rejected');
$upload_request->set_file_params(['attachment' => ['name' => 'forged.png']]);
$raw_fingerprint = corsivo_forms_request_fingerprint($upload_request);
extension_check(is_string($raw_fingerprint) && strlen($raw_fingerprint) === 64, 'Malformed hidden upload can be fingerprinted before visibility resolution');
$filtered_request = corsivo_forms_active_upload_request($upload_request, [$toggle, $upload], [$toggle]);
extension_check(!is_wp_error(corsivo_forms_prepare_request_uploads($filtered_request, [$toggle])), 'Malformed known hidden upload ignored entirely');
extension_check(is_wp_error(corsivo_forms_prepare_request_uploads($upload_request, [$toggle, $upload])), 'Malformed active upload still rejected');
$upload_request->set_file_params(['unknown' => ['name' => 'forged.png']]);
extension_check(is_wp_error(corsivo_forms_prepare_request_uploads(corsivo_forms_active_upload_request($upload_request, [$toggle, $upload], [$toggle]), [$toggle])), 'Malformed unknown upload still rejected');
$temporary_paths = [tempnam(sys_get_temp_dir(), 'forms-hash-'), tempnam(sys_get_temp_dir(), 'forms-hash-')];
try {
	file_put_contents($temporary_paths[0], 'same content');
	file_put_contents($temporary_paths[1], 'same content');
	$first_identity = corsivo_forms_unverified_upload_identity(['attachment' => ['name' => 'forged.txt', 'tmp_name' => $temporary_paths[0]]]);
	$second_identity = corsivo_forms_unverified_upload_identity(['attachment' => ['name' => 'forged.txt', 'tmp_name' => $temporary_paths[1]]]);
	extension_check($first_identity === $second_identity, 'Fallback fingerprints readable content instead of temporary path');
	file_put_contents($temporary_paths[1], 'different content');
	extension_check($first_identity !== corsivo_forms_unverified_upload_identity(['attachment' => ['name' => 'forged.txt', 'tmp_name' => $temporary_paths[1]]]), 'Fallback distinguishes different readable upload content');
	$valid_upload_request = new WP_REST_Request('POST', '/corsivo/v1/forms/submit');
	$valid_upload_request->set_file_params(['attachment' => ['name' => 'real.txt', 'tmp_name' => $temporary_paths[0], 'error' => UPLOAD_ERR_OK, 'size' => filesize($temporary_paths[0])]]);
	$valid_identity = corsivo_forms_request_upload_identity($valid_upload_request);
	$expected_fingerprint = hash('sha256', wp_json_encode(['params' => [], 'uploads' => $valid_identity], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	extension_check(corsivo_forms_request_fingerprint($valid_upload_request) === $expected_fingerprint, 'Valid upload fingerprint unchanged by fallback support');
} finally {
	foreach ($temporary_paths as $path) { unlink($path); }
}

$captured = [];
add_action('corsivo_forms_submission', static function ($submission, $clean) use (&$captured) { $captured[] = [$submission, $clean]; }, 10, 2);
$submit_definition = extension_definition([$topic, $toggle, $conditional, $descendant], ['notification_rules' => $rules, 'store' => true]);
$request_id = wp_generate_uuid4();
$request = extension_request($submit_definition, ['topic' => 'Sales', 'toggle' => '0', 'details' => 'Forged hidden answer', 'email' => 'forged@example.test'], $request_id);
$response = corsivo_forms_handle_submit($request);
extension_check($response instanceof WP_REST_Response && $response->get_status() === 200, 'Handler accepts submission with inactive required fields');
extension_check(count($GLOBALS['mail_attempts']) === 1 && $GLOBALS['mail_attempts'][0]['recipient'] === 'sales@example.test' && $GLOBALS['mail_attempts'][0]['subject'] === 'Sales: Sales', 'Handler delivers only selected notification');
extension_check(count($captured) === 1 && array_column($captured[0][0]['rows'], 'name') === ['topic', 'toggle'] && array_keys($captured[0][1]) === ['topic', 'toggle'] && $captured[0][0]['sender_email'] === '', 'Hidden forged values absent from rows, values, integration and sender');
extension_check(count($GLOBALS['stored_submissions']) === 1 && array_column($GLOBALS['stored_submissions'][0]['rows'], 'name') === ['topic', 'toggle'] && array_keys($GLOBALS['stored_submissions'][0]['values']) === ['topic', 'toggle'], 'Archive receives only active fields');
$replay = clone $request;
$replay->set_param('token', 'v1.invalid');
extension_check(corsivo_forms_handle_submit($replay) === $response && count($GLOBALS['mail_attempts']) === 1 && count($captured) === 1, 'Identical replay returns saved response without duplicate delivery or integration');
extension_check(count($GLOBALS['stored_submissions']) === 1, 'Replay does not duplicate archived submission');
$conflict = clone $request;
$conflict->set_param('details', 'Changed hidden raw answer');
$conflict_result = corsivo_forms_handle_submit($conflict);
extension_check(is_wp_error($conflict_result) && $conflict_result->get_error_code() === 'request_id_conflict' && count($GLOBALS['mail_attempts']) === 1, 'Raw hidden payload changes retain replay conflict semantics');

echo 'PASS - ' . $checks . ' Forms extension regressions' . PHP_EOL;

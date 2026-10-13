<?php

if (!defined('ABSPATH') || !function_exists('corsivo_forms_handle_submit')) {
	exit(1);
}

global $wpdb, $wp_filter, $extension_checks, $extension_requests, $extension_forms;

$extension_checks = 0;
$extension_requests = [];
$extension_forms = [];
$extension_submissions = [];
$extension_deliveries = [];
$extension_integrations = [];
$extension_allowed = 'sales@example.test, support@example.test';
$extension_client_ip = '203.0.113.218';
$extension_hooks = $wp_filter['corsivo_forms_submission'] ?? null;
unset($wp_filter['corsivo_forms_submission']);
$extension_storage = sys_get_temp_dir() . '/corsivo-forms-extension-' . wp_generate_uuid4();
$extension_owns_storage = !defined('CORSIVO_FORMS_PRIVATE_DIR');
if ($extension_owns_storage) {
	define('CORSIVO_FORMS_PRIVATE_DIR', $extension_storage);
}
$extension_previous_batches = $GLOBALS['corsivo_forms_prepared_upload_batches'] ?? null;
$extension_settings_filter = static function () use (&$extension_allowed) {
	return array_merge(corsivo_forms_settings_defaults(), ['allowed_recipients' => $extension_allowed, 'corsivo_mail_enabled' => false]);
};
$extension_admin_filter = static fn() => 'admin@example.test';
$extension_mail_filter = static function ($preempt, $attributes) use (&$extension_deliveries) { $extension_deliveries[] = $attributes; return true; };
$extension_ip_filter = static fn() => $extension_client_ip;
$extension_integration = static function ($submission, $values, $submission_id) use (&$extension_integrations) { $extension_integrations[] = [$submission, $values]; };

function corsivo_forms_extension_wp_check($condition, $message) {
	$GLOBALS['extension_checks']++;
	if (!$condition) { throw new RuntimeException($message); }
}
function corsivo_forms_extension_wp_field($type, $name, array $values = []) {
	return ['type' => $type, 'name' => $name, 'values' => corsivo_forms_normalize_field_values($type, array_merge(['field_key' => $name, 'label' => ucfirst($name)], $values))];
}
function corsivo_forms_extension_wp_definition(array $fields, array $settings = []) {
	$definition = ['version' => 1, 'issued_at' => time(), 'fields' => $fields, 'settings' => corsivo_forms_normalize_form_settings(array_merge(['store' => true], $settings)), 'has_submit' => true, 'is_public' => true, 'page_id' => 0, 'page_title' => '', 'page_url' => '', 'language' => 'it_it', 'source_mode' => 'rendered'];
	$definition['form_id'] = corsivo_forms_definition_form_id($definition['settings'], $fields, $definition);
	$GLOBALS['extension_forms'][] = $definition['form_id'];
	return $definition;
}
function corsivo_forms_extension_wp_request(array $definition, array $values, array $uploads = []) {
	$request_id = wp_generate_uuid4();
	$GLOBALS['extension_requests'][] = $request_id;
	$request = new WP_REST_Request('POST', '/corsivo/v1/forms/submit');
	$request->set_body_params(array_merge($values, ['token' => corsivo_forms_create_form_token($definition), 'formId' => $definition['form_id'], 'request_id' => $request_id]));
	$request->set_file_params($uploads);
	return $request;
}

add_filter('pre_option_corsivo_forms_settings', $extension_settings_filter);
add_filter('pre_option_admin_email', $extension_admin_filter);
add_filter('pre_wp_mail', $extension_mail_filter, PHP_INT_MAX, 2);
add_filter('corsivo_forms_client_ip', $extension_ip_filter);
add_action('corsivo_forms_submission', $extension_integration, 10, 3);

try {
	$topic = corsivo_forms_extension_wp_field('radio', 'topic', ['required' => true, 'options' => [['option' => 'Sales'], ['option' => 'Support']]]);
	$toggle = corsivo_forms_extension_wp_field('checkbox', 'toggle');
	$conditional_values = ['required' => true, 'conditions' => [['field' => 'toggle', 'operator' => 'equal', 'value' => '1']]];
	$fields = [$topic, $toggle, corsivo_forms_extension_wp_field('email', 'email', $conditional_values), corsivo_forms_extension_wp_field('file', 'attachment', $conditional_values)];
	$rules = [
		['label' => 'Sales', 'recipients' => 'sales@example.test', 'subject' => 'Sales: {topic}', 'conditions' => [['field' => 'topic', 'operator' => 'equal', 'value' => 'Sales']]],
		['label' => 'Second', 'recipients' => 'support@example.test', 'subject' => 'Second', 'conditions' => [['field' => 'topic', 'operator' => 'is_not_empty']]],
	];
	$definition = corsivo_forms_extension_wp_definition($fields, ['notification_rules' => $rules]);
	corsivo_forms_extension_wp_check(corsivo_forms_form_configuration_error($definition) === null, 'Conditional form configuration valid in WordPress');
	$request = corsivo_forms_extension_wp_request($definition, ['topic' => 'Sales', 'toggle' => '0', 'email' => 'forged@example.test'], ['attachment' => ['name' => 'hidden.png']]);
	$response = rest_do_request($request);
	corsivo_forms_extension_wp_check($response->get_status() === 200 && ($response->get_data()['sent'] ?? false), 'REST accepts inactive required email and malformed hidden upload');
	corsivo_forms_extension_wp_check(count($extension_deliveries) === 1 && $extension_deliveries[0]['to'] === 'sales@example.test' && $extension_deliveries[0]['subject'] === 'Sales: Sales', 'REST uses first matching static notification');
	corsivo_forms_extension_wp_check(count($extension_integrations) === 1 && array_keys($extension_integrations[0][1]) === ['topic', 'toggle'], 'Real integration action excludes hidden values and files');
	$record = corsivo_forms_activity_get_record($request->get_param('request_id'));
	$submission_id = (int) ($record['submission_id'] ?? 0);
	corsivo_forms_extension_wp_check($submission_id > 0 && array_column(get_post_meta($submission_id, '_corsivo_forms_rows', true), 'name') === ['topic', 'toggle'], 'WordPress archive stores only active field rows');
	corsivo_forms_extension_wp_check(get_post_meta($submission_id, '_corsivo_forms_sender_email', true) === '' && corsivo_forms_submission_uploads($submission_id) === [] && get_post_meta($submission_id, '_corsivo_forms_consents', true) === [], 'Hidden email and file do not leak into sender, uploads or consents');
	$replay = clone $request;
	$replay->set_param('token', 'v1.invalid');
	$replayed = rest_do_request($replay);
	corsivo_forms_extension_wp_check($replayed->get_status() === 200 && $replayed->get_data() === $response->get_data() && count($extension_deliveries) === 1 && count($extension_integrations) === 1, 'REST replay sends and stores once even with expired token');
	$changed = clone $request;
	$changed->set_file_params(['attachment' => ['name' => 'different-hidden.png']]);
	$changed_response = rest_do_request($changed);
	corsivo_forms_extension_wp_check($changed_response->get_status() === 409 && ($changed_response->get_data()['code'] ?? '') === 'request_id_conflict' && count($extension_deliveries) === 1, 'Changed malformed hidden upload preserves raw replay conflict');
	foreach ([['unknown', '0'], ['attachment', '1']] as [$upload_name, $enabled]) {
		$invalid = corsivo_forms_extension_wp_request($definition, ['topic' => 'Sales', 'toggle' => $enabled, 'email' => 'person@example.test'], [$upload_name => ['name' => 'forged.png']]);
		$invalid_response = rest_do_request($invalid);
		corsivo_forms_extension_wp_check($invalid_response->get_status() === 400 && count($extension_deliveries) === 1, 'Active or unknown malformed upload fails without delivery');
	}
	$missing = corsivo_forms_extension_wp_request($definition, ['topic' => 'Sales', 'toggle' => '1', 'email' => 'person@example.test']);
	$missing_response = rest_do_request($missing);
	corsivo_forms_extension_wp_check($missing_response->get_status() === 400 && isset($missing_response->get_data()['data']['errors']['attachment']), 'Active required upload remains required');
	$extension_allowed = 'support@example.test';
	$revoked_response = rest_do_request(corsivo_forms_extension_wp_request($definition, ['topic' => 'Sales', 'toggle' => '0']));
	corsivo_forms_extension_wp_check($revoked_response->get_status() === 409 && ($revoked_response->get_data()['code'] ?? '') === 'invalid_rule_recipients' && count($extension_deliveries) === 1, 'Rule recipient revocation blocks signed token');
	$extension_allowed = 'sales@example.test, support@example.test';
	$fallback_definition = corsivo_forms_extension_wp_definition([$topic], ['notification_rules' => [$rules[0]], 'subject' => 'Base: {topic}']);
	$fallback_response = rest_do_request(corsivo_forms_extension_wp_request($fallback_definition, ['topic' => 'Support']));
	corsivo_forms_extension_wp_check($fallback_response->get_status() === 200 && count($extension_deliveries) === 2 && $extension_deliveries[1]['to'] === 'admin@example.test' && $extension_deliveries[1]['subject'] === 'Base: Support', 'Unmatched routing sends base notification');
} finally {
	remove_filter('pre_option_corsivo_forms_settings', $extension_settings_filter);
	remove_filter('pre_option_admin_email', $extension_admin_filter);
	remove_filter('pre_wp_mail', $extension_mail_filter, PHP_INT_MAX);
	remove_filter('corsivo_forms_client_ip', $extension_ip_filter);
	remove_action('corsivo_forms_submission', $extension_integration, 10);
	if ($extension_hooks !== null) { $wp_filter['corsivo_forms_submission'] = $extension_hooks; }
	foreach ($extension_requests as $request_id) {
		$record = corsivo_forms_activity_get_record($request_id);
		if (!empty($record['submission_id'])) { $extension_submissions[] = (int) $record['submission_id']; }
		$wpdb->delete(corsivo_forms_activity_table_name(), ['request_id' => $request_id], ['%s']);
	}
	foreach (array_unique($extension_submissions) as $submission_id) { wp_delete_post($submission_id, true); }
	$extension_buckets = ['preflight'];
	foreach (array_unique($extension_forms) as $form_id) { $extension_buckets[] = 'form|' . $form_id; $extension_buckets[] = 'validation|' . $form_id; }
	foreach ($extension_buckets as $bucket) { delete_transient('corsivo_forms_rate_' . hash_hmac('sha256', $extension_client_ip . '|' . $bucket, wp_salt('nonce'))); }
	if ($extension_owns_storage && is_dir($extension_storage) && !is_link($extension_storage)) {
		$extension_resolved_storage = realpath($extension_storage);
		$extension_resolved_temp = realpath(sys_get_temp_dir());
		if ($extension_resolved_storage && $extension_resolved_temp && corsivo_forms_upload_path_is_within($extension_resolved_storage, $extension_resolved_temp)) {
			foreach (array_diff(scandir($extension_resolved_storage), ['.', '..']) as $entry) {
				$path = $extension_resolved_storage . '/' . $entry;
				if (is_file($path) && !is_link($path)) { unlink($path); }
			}
			rmdir($extension_resolved_storage);
		}
	}
	if ($extension_previous_batches === null) { unset($GLOBALS['corsivo_forms_prepared_upload_batches']); } else { $GLOBALS['corsivo_forms_prepared_upload_batches'] = $extension_previous_batches; }
}

echo 'PASS - ' . $extension_checks . ' WordPress Forms extension checks' . PHP_EOL;

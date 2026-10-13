<?php

if (PHP_SAPI !== 'cli') { exit(1); }

define('ABSPATH', dirname(__DIR__, 4) . '/');
define('CORSIVO_FORMS_FILE', dirname(__DIR__) . '/corsivo-forms.php');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MB_IN_BYTES', 1048576);
require ABSPATH . 'wp-includes/class-wp-error.php';
function add_action(...$arguments) {}
function do_action(...$arguments) {}
function add_filter(...$arguments) {}
function plugin_basename($file) { return $file; }
function __($text) { return $text; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function sanitize_file_name($name) { return $name; }
function wp_basename($name) { return basename($name); }
function apply_filters($hook, $value, ...$arguments) { return $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url_raw($value, $protocols = []) { return filter_var($value, FILTER_VALIDATE_URL) ? $value : ''; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function wp_kses_post($value) { return strip_tags($value, '<strong><br>'); }
function get_bloginfo($key) { return 'Fixture site'; }
function home_url($path) { return 'https://example.test' . $path; }
function date_i18n($format) { return '2026-09-25'; }
function wp_salt($purpose) { return 'fixture-only-salt'; }
function wp_using_ext_object_cache() { return $GLOBALS['external_cache'] ?? false; }
function wp_cache_delete($key, $group) { $GLOBALS['cache_deletes'][] = [$key, $group]; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; $GLOBALS['ttls'][$key] = $ttl; return true; }
$GLOBALS['options'] = ['admin_email' => 'admin@example.test', 'corsivo_forms_settings' => ['allowed_recipients' => 'office@example.test']];
$wpdb = new class {
	public string $prefix = 'fixture_';
	public bool $busy = false;
	public function prepare($sql, ...$values) { return $sql; }
	public function get_var($sql) { return str_contains($sql, 'GET_LOCK') && $this->busy ? '0' : '1'; }
};
require dirname(__DIR__) . '/includes/settings.php';
require dirname(__DIR__) . '/includes/mail.php';
require dirname(__DIR__) . '/includes/uploads.php';
require dirname(__DIR__) . '/includes/submit.php';
require dirname(__DIR__, 2) . '/corsivo-mail/includes/template/merge-tags.php';

function check($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
	echo "PASS $message\n";
}

check(corsivo_forms_notification_recipients(['recipients' => '']) === ['admin@example.test'], 'default administrative recipient remains available');
check(corsivo_forms_notification_recipients(['recipients' => 'office@example.test']) === ['office@example.test'], 'administrator-approved recipient is accepted');
check(is_wp_error(corsivo_forms_notification_recipients(['recipients' => 'outsider@example.test'])), 'block content cannot select an unapproved recipient');
check(corsivo_forms_notification_recipients(['recipients' => 'outsider@example.test, invalid'])->get_error_code() === 'invalid_recipients', 'malformed recipient is reported before authorization');
$context = corsivo_forms_notification_context([
	'form_title' => 'A & B', 'page_title' => 'Contact', 'page_url' => 'https://example.test/contact',
	'submitted_at' => '2026-09-25', 'sender_name' => 'A & B', 'sender_email' => 'sender@example.test',
	'rows' => [['type' => 'text', 'name' => 'name', 'label' => 'Name', 'display' => 'A & B {{admin_email}}']],
], 'A & B');
$merged = \CorsivoMail\Template\apply_merge_tags('{{form_title}}|{{sender_name}}|{{field_name}}|{{fields_html}}', $context);
check($merged === 'A &amp; B|A &amp; B|A &amp; B {{admin_email}}|<strong>Name:</strong> A &amp; B {{admin_email}}', 'Forms to Mail escapes text once, preserves fields markup and treats submitted merge tags as literal');
check(\CorsivoMail\Template\apply_merge_tags('{{notification_subject}}', $context, 'text') === 'A & B', 'Forms to Mail subject keeps plain text');
$name = corsivo_forms_upload_original_name(str_repeat('x', 200) . '.pdf');
check(strlen($name) === 180 && str_ends_with($name, '.pdf'), 'long upload names preserve the extension');
$GLOBALS['transients'] = [];
$_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678::1';
check(corsivo_forms_increment_rate_limit('fixture', 1, 60) === true, 'first submission is allowed');
$_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678::2';
check(is_wp_error(corsivo_forms_increment_rate_limit('fixture', 1, 60)), 'IPv6 addresses in one network share the submission budget');
check(corsivo_forms_increment_rate_limit('uploads', 100, 86400, 70, 'all-clients') === true, 'upload bytes consume the shared budget');
$_SERVER['REMOTE_ADDR'] = '198.51.100.22';
check(is_wp_error(corsivo_forms_increment_rate_limit('uploads', 100, 86400, 31, 'all-clients')), 'changing IP cannot exceed the upload budget');
$GLOBALS['external_cache'] = true;
$GLOBALS['cache_deletes'] = [];
check(corsivo_forms_increment_rate_limit('external', 1, 60) === true, 'external object cache first request');
check(is_wp_error(corsivo_forms_increment_rate_limit('external', 1, 60)) && !$GLOBALS['cache_deletes'], 'persistent counter is not deleted before reading');
$wpdb->busy = true;
check(is_wp_error(corsivo_forms_increment_rate_limit('busy', 100, 60)), 'lock contention does not allow an unchecked request');

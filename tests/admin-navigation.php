<?php

define('ABSPATH', dirname(__DIR__) . '/');
define('CORSIVO_FORMS_FILE', ABSPATH . 'corsivo-forms.php');
define('CORSIVO_FORMS_DIR', ABSPATH);
define('CORSIVO_FORMS_URL', 'https://example.test/plugins/corsivo-forms/');

$actions = [];
$post_types = [];
$submenus = [];
$scripts = [];
$styles = [];
$can_manage = true;
$assertions = 0;

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['actions'][$hook][] = [$callback, $priority];
}
function add_filter() {}
function __($text, $domain = '') { return $text; }
function plugin_basename($file) { return basename($file); }
function current_user_can($capability) { return $GLOBALS['can_manage'] && $capability === 'manage_options'; }
function register_post_type($type, $args) { $GLOBALS['post_types'][$type] = $args; }
function add_submenu_page($parent, $title, $label, $capability, $slug, $callback) {
	$GLOBALS['submenus'][$slug] = compact('parent', 'title', 'label', 'capability', 'callback');
	return get_plugin_page_hookname($slug, $parent);
}
function get_plugin_page_hookname($slug, $parent) { return 'translated-parent_page_' . $slug; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function rest_url($path) { return 'https://example.test/wp-json/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function wp_safe_redirect($url) { throw new RuntimeException($url); }
function wp_enqueue_script($handle, ...$args) { $GLOBALS['scripts'][] = $handle; }
function wp_enqueue_style($handle, ...$args) { $GLOBALS['styles'][] = $handle; }
function wp_style_add_data() {}
function wp_add_inline_script() {}
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_create_nonce($action) { return 'fixture-nonce'; }
function corsivo_forms_activity_distinct_values($column) { return []; }

function test_assert($condition, $message) {
	$GLOBALS['assertions']++;
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

require ABSPATH . 'includes/submissions.php';
require ABSPATH . 'includes/settings.php';

corsivo_forms_register_submission_cpt();
corsivo_forms_register_settings_page();
test_assert($post_types['corsivo_submission']['show_in_menu'] === true, 'Requests require another menu or plugin.');
test_assert($post_types['corsivo_submission']['menu_position'] === 30, 'Requests are outside the operational menu group.');
test_assert($post_types['corsivo_submission']['capabilities']['edit_posts'] === 'manage_options', 'Request permissions changed.');
test_assert($post_types['corsivo_submission']['capabilities']['create_posts'] === 'do_not_allow', 'Manual creation became available.');
test_assert($submenus['corsivo-forms-settings']['parent'] === 'edit.php?post_type=corsivo_submission', 'Settings are not under Requests.');
test_assert($submenus['corsivo-forms-settings']['capability'] === 'manage_options', 'Settings permissions changed.');
test_assert($submenus['corsivo-forms-settings']['callback'] === 'corsivo_forms_render_settings_page', 'Settings renderer changed.');
test_assert(in_array(['corsivo_forms_redirect_legacy_settings_page', 100], $actions['admin_menu'], true), 'Legacy redirect runs after the WordPress access check.');
test_assert(str_starts_with(corsivo_forms_settings_page_url('settings'), admin_url('admin.php') . '?'), 'Settings links still target Tools.');

$pagenow = 'tools.php';
$_GET = ['page' => 'corsivo-forms-settings', 'tab' => 'activity', 'view' => '{"search":"O\\\'Brien","page":2}', 'activity_cleared' => '1'];
$target = null;
try {
	corsivo_forms_redirect_legacy_settings_page();
} catch (RuntimeException $redirect) {
	$target = $redirect->getMessage();
}
test_assert(is_string($target) && str_starts_with($target, admin_url('admin.php') . '?'), 'The old Tools URL is not redirected.');
parse_str(parse_url($target, PHP_URL_QUERY), $redirect_args);
test_assert($redirect_args === wp_unslash($_GET), 'Legacy redirect loses tabs, filters or operation notices.');

$can_manage = false;
corsivo_forms_redirect_legacy_settings_page();
$can_manage = true;
$pagenow = 'admin.php';
corsivo_forms_redirect_legacy_settings_page();
$pagenow = 'tools.php';
$_GET['page'] = 'unrelated';
corsivo_forms_redirect_legacy_settings_page();

$_GET = ['tab' => 'activity'];
if (corsivo_forms_activity_assets_available()) {
	corsivo_forms_enqueue_activity_assets(get_plugin_page_hookname('corsivo-forms-settings', 'edit.php?post_type=corsivo_submission'));
	test_assert(in_array('corsivo-forms-activity', $scripts, true), 'Activity assets do not load on the registered page hook.');
	test_assert(in_array('corsivo-forms-activity-ui', $styles, true) && in_array('corsivo-forms-activity-app', $styles, true), 'Activity styles do not load under Requests.');
} else {
	echo "Activity enqueue check skipped: the build is not readable in this PHP runtime.\n";
}
$scripts = $styles = [];
corsivo_forms_enqueue_activity_assets('unrelated_page');
$_GET['tab'] = 'settings';
corsivo_forms_enqueue_activity_assets(get_plugin_page_hookname('corsivo-forms-settings', 'edit.php?post_type=corsivo_submission'));
test_assert($scripts === [] && $styles === [], 'Activity assets leak to other screens or tabs.');

foreach (['activity', 'settings', 'integrations'] as $tab) {
	$scripts = $styles = [];
	$_GET['tab'] = $tab;
	corsivo_forms_enqueue_workspace(get_plugin_page_hookname('corsivo-forms-settings', 'edit.php?post_type=corsivo_submission'));
	test_assert($scripts === ['corsivo-forms-workspace'], 'Standalone workspace navigation is missing on ' . $tab);
	test_assert($styles === ['corsivo-forms-admin-workspace', 'corsivo-forms-admin-workspace-layout'], 'Standalone workspace styles are missing on ' . $tab);
}
$scripts = $styles = [];
corsivo_forms_enqueue_workspace('unrelated_page');
$can_manage = false;
corsivo_forms_enqueue_workspace(get_plugin_page_hookname('corsivo-forms-settings', 'edit.php?post_type=corsivo_submission'));
test_assert($scripts === [] && $styles === [], 'Workspace assets leak to another screen or permission context.');

echo 'Forms admin navigation: ' . $assertions . " assertions passed.\n";

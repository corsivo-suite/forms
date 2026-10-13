<?php
/**
 * Plugin Name: Corsivo Forms
 * Description: Moduli di contatto nativi Gutenberg: componi il form a blocchi, ricevi le notifiche via Corsivo Mail e archivia le richieste in bacheca.
 * Version: 0.5.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: define-blocks
 * Author: Salvatore Corsi
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: corsivo-forms
 * Update URI: https://www.corsivo.dev/plugins/corsivo-forms
 */

if (!defined('ABSPATH')) {
	exit;
}

define('CORSIVO_FORMS_VERSION', '0.5.0');
define('CORSIVO_FORMS_FILE', __FILE__);
define('CORSIVO_FORMS_DIR', plugin_dir_path(__FILE__));
define('CORSIVO_FORMS_URL', plugin_dir_url(__FILE__));

require CORSIVO_FORMS_DIR . 'includes/settings.php';
require CORSIVO_FORMS_DIR . 'includes/uploads.php';
require CORSIVO_FORMS_DIR . 'includes/conditions.php';
require CORSIVO_FORMS_DIR . 'includes/fields.php';
require CORSIVO_FORMS_DIR . 'includes/blocks.php';
require CORSIVO_FORMS_DIR . 'includes/token.php';
require CORSIVO_FORMS_DIR . 'includes/editor.php';
require CORSIVO_FORMS_DIR . 'includes/activity-storage.php';
require CORSIVO_FORMS_DIR . 'includes/activity.php';
require CORSIVO_FORMS_DIR . 'includes/mail.php';
require CORSIVO_FORMS_DIR . 'includes/submissions.php';
require CORSIVO_FORMS_DIR . 'includes/submit.php';
require CORSIVO_FORMS_DIR . 'includes/privacy.php';

function corsivo_forms_activate_site() {
	corsivo_forms_install_settings();
	$result = corsivo_forms_activity_install();
	if (is_wp_error($result)) {
		return $result;
	}

	corsivo_forms_schedule_daily_cleanup();

	return true;
}

function corsivo_forms_activate($network_wide = false) {
	if (!$network_wide || !is_multisite()) {
		$result = corsivo_forms_activate_site();
		if (is_wp_error($result)) {
			wp_die(esc_html($result->get_error_message()));
		}
		return;
	}

	$offset = 0;
	do {
		$site_ids = get_sites([
			'fields' => 'ids',
			'number' => 100,
			'offset' => $offset,
		]);
		foreach ($site_ids as $site_id) {
			switch_to_blog((int) $site_id);
			try {
				$result = corsivo_forms_activate_site();
			} finally {
				restore_current_blog();
			}
			if (is_wp_error($result)) {
				wp_die(esc_html($result->get_error_message()));
			}
		}
		$offset += count($site_ids);
	} while (count($site_ids) === 100);
}
register_activation_hook(CORSIVO_FORMS_FILE, 'corsivo_forms_activate');

function corsivo_forms_initialize_site($site) {
	if (!is_multisite() || !is_object($site)) {
		return;
	}
	$network_plugins = (array) get_site_option('active_sitewide_plugins', []);
	if (!isset($network_plugins[plugin_basename(CORSIVO_FORMS_FILE)])) {
		return;
	}
	$site_id = (int) ($site->blog_id ?? 0);
	if (!$site_id) {
		return;
	}
	switch_to_blog($site_id);
	try {
		$result = corsivo_forms_activate_site();
	} finally {
		restore_current_blog();
	}
	if (is_wp_error($result)) {
		wp_die(esc_html($result->get_error_message()));
	}
}
add_action('wp_initialize_site', 'corsivo_forms_initialize_site', 10, 1);

function corsivo_forms_missing_define_blocks_notice() {
	if (function_exists('define_block_type') || !current_user_can('activate_plugins')) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>Corsivo Forms:</strong> Define Blocks non è attivo. I moduli non possono essere registrati.</p></div>';
}
add_action('admin_notices', 'corsivo_forms_missing_define_blocks_notice');

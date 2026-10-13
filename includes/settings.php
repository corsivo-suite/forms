<?php

if (!defined('ABSPATH')) {
	exit;
}

add_action('admin_init', 'corsivo_forms_register_settings');
add_action('admin_menu', 'corsivo_forms_register_settings_page');
add_action('admin_enqueue_scripts', 'corsivo_forms_enqueue_activity_assets');
add_action('rest_api_init', 'corsivo_forms_register_activity_rest_route');
add_filter('plugin_action_links_' . plugin_basename(CORSIVO_FORMS_FILE), 'corsivo_forms_plugin_action_links');

function corsivo_forms_settings_defaults() {
	return [
		'log_enabled'               => true,
		'log_retention_days'        => 90,
		'submission_retention_days' => 0,
		'delete_data_on_uninstall'  => false,
		'corsivo_mail_enabled'       => true,
		'corsivo_privacy_enabled'    => true,
		'allowed_recipients'         => '',
	];
}

function corsivo_forms_normalize_settings($settings) {
	$defaults = corsivo_forms_settings_defaults();
	$settings = is_array($settings) ? array_merge($defaults, $settings) : $defaults;

	return [
		'log_enabled'               => corsivo_forms_sanitize_boolean_setting($settings['log_enabled']),
		'log_retention_days'        => max(1, min(3650, (int) $settings['log_retention_days'])),
		'submission_retention_days' => max(0, min(3650, (int) $settings['submission_retention_days'])),
		'delete_data_on_uninstall'  => corsivo_forms_sanitize_boolean_setting($settings['delete_data_on_uninstall']),
		'corsivo_mail_enabled'       => corsivo_forms_sanitize_boolean_setting($settings['corsivo_mail_enabled']),
		'corsivo_privacy_enabled'    => corsivo_forms_sanitize_boolean_setting($settings['corsivo_privacy_enabled']),
		'allowed_recipients'         => implode(', ', array_unique(array_filter(array_map('sanitize_email', preg_split('/[\s,;]+/', is_scalar($settings['allowed_recipients']) ? (string) $settings['allowed_recipients'] : '') ?: []), 'is_email'))),
	];
}

function corsivo_forms_sanitize_boolean_setting($value) {
	return in_array($value, [true, 1, '1', 'true', 'on'], true);
}

function corsivo_forms_get_settings() {
	return corsivo_forms_normalize_settings(get_option('corsivo_forms_settings', []));
}

function corsivo_forms_get_setting($key, $fallback = null) {
	$settings = corsivo_forms_get_settings();

	return array_key_exists($key, $settings) ? $settings[$key] : $fallback;
}

function corsivo_forms_sanitize_settings($input) {
	$input   = is_array($input) ? $input : [];
	$current = corsivo_forms_get_settings();

	foreach (array_keys(corsivo_forms_settings_defaults()) as $key) {
		if (array_key_exists($key, $input)) {
			$current[$key] = $input[$key];
		}
	}

	return corsivo_forms_normalize_settings($current);
}

function corsivo_forms_register_settings() {
	register_setting('corsivo_forms_settings', 'corsivo_forms_settings', [
		'type'              => 'array',
		'default'           => corsivo_forms_settings_defaults(),
		'sanitize_callback' => 'corsivo_forms_sanitize_settings',
	]);
}

function corsivo_forms_install_settings() {
	add_option('corsivo_forms_settings', corsivo_forms_settings_defaults(), '', true);
}

function corsivo_forms_register_settings_page() {
	add_submenu_page(
		'tools.php',
		'Corsivo Forms',
		'Corsivo Forms',
		'manage_options',
		'corsivo-forms-settings',
		'corsivo_forms_render_settings_page'
	);
}

function corsivo_forms_plugin_action_links($links) {
	array_unshift($links, '<a href="' . esc_url(corsivo_forms_settings_page_url('settings')) . '">' . esc_html__('Impostazioni', 'corsivo-forms') . '</a>');
	return $links;
}

function corsivo_forms_settings_page_url($tab = 'activity', array $args = []) {
	return add_query_arg(
		array_merge([
			'page' => 'corsivo-forms-settings',
			'tab'  => $tab,
		], $args),
		admin_url('tools.php')
	);
}

function corsivo_forms_render_settings_page() {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html('Non hai i permessi per visualizzare questa pagina.'), '', ['response' => 403]);
	}

	$tabs = [
		'activity'     => 'Attività',
		'settings'     => 'Impostazioni',
		'integrations' => 'Integrazioni',
	];
	$tab = isset($_GET['tab']) && is_scalar($_GET['tab'])
		? sanitize_key(wp_unslash((string) $_GET['tab']))
		: 'activity';
	if (!isset($tabs[$tab])) {
		$tab = 'activity';
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html('Corsivo Forms') . '</h1>';
	settings_errors('corsivo_forms_settings');

	if (isset($_GET['activity_cleared'])) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html('Registro attività ripulito. I claim tecnici recenti sono stati conservati.') . '</p></div>';
	}
	if (isset($_GET['activity_clear_error'])) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html('Impossibile svuotare il registro attività.') . '</p></div>';
	}

	echo '<nav class="nav-tab-wrapper">';
	foreach ($tabs as $tab_key => $tab_label) {
		$class = $tab === $tab_key ? 'nav-tab nav-tab-active' : 'nav-tab';
		echo '<a class="' . esc_attr($class) . '" href="' . esc_url(corsivo_forms_settings_page_url($tab_key)) . '">' . esc_html($tab_label) . '</a>';
	}
	echo '</nav>';

	if ($tab === 'settings') {
		corsivo_forms_render_general_settings();
	} elseif ($tab === 'integrations') {
		corsivo_forms_render_integration_settings();
	} else {
		corsivo_forms_render_activity_tab();
	}

	echo '</div>';
}

function corsivo_forms_render_general_settings() {
	$settings = corsivo_forms_get_settings();

	echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
	settings_fields('corsivo_forms_settings');
	echo '<table class="form-table" role="presentation"><tbody>';
	echo '<tr><th scope="row"><label for="corsivo-forms-recipients">' . esc_html('Destinatari autorizzati') . '</label></th><td>';
	echo '<textarea id="corsivo-forms-recipients" class="large-text" name="corsivo_forms_settings[allowed_recipients]" rows="3">' . esc_textarea($settings['allowed_recipients']) . '</textarea>';
	echo '<p class="description">' . esc_html('Indirizzi ai quali i moduli possono inviare notifiche, separati da virgola. L’indirizzo amministrativo del sito è sempre consentito.') . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html('Registro attività') . '</th><td>';
	echo '<input type="hidden" name="corsivo_forms_settings[log_enabled]" value="0">';
	echo '<label><input type="checkbox" name="corsivo_forms_settings[log_enabled]" value="1"' . checked($settings['log_enabled'], true, false) . '> ' . esc_html('Registra le azioni del plugin') . '</label>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="corsivo-forms-log-retention">' . esc_html('Conservazione attività') . '</label></th><td>';
	echo '<input id="corsivo-forms-log-retention" class="small-text" type="number" min="1" max="3650" name="corsivo_forms_settings[log_retention_days]" value="' . esc_attr((string) $settings['log_retention_days']) . '"> ' . esc_html('giorni');
	echo '<p class="description">' . esc_html('Con il registro disattivato, gli eventi amministrativi di disattivazione e pulizia e i record tecnici di idempotenza restano per un giorno.') . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="corsivo-forms-submission-retention">' . esc_html('Conservazione richieste') . '</label></th><td>';
	echo '<input id="corsivo-forms-submission-retention" class="small-text" type="number" min="0" max="3650" name="corsivo_forms_settings[submission_retention_days]" value="' . esc_attr((string) $settings['submission_retention_days']) . '"> ' . esc_html('giorni');
	echo '<p class="description">' . esc_html('Imposta 0 per conservare le richieste senza scadenza automatica.') . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html('Disinstallazione') . '</th><td>';
	echo '<input type="hidden" name="corsivo_forms_settings[delete_data_on_uninstall]" value="0">';
	echo '<label><input type="checkbox" name="corsivo_forms_settings[delete_data_on_uninstall]" value="1"' . checked($settings['delete_data_on_uninstall'], true, false) . '> ' . esc_html('Elimina richieste, attività e impostazioni quando il plugin viene disinstallato') . '</label>';
	echo '</td></tr>';

	echo '</tbody></table>';
	submit_button('Salva impostazioni');
	echo '</form>';
}

function corsivo_forms_integration_definitions() {
	return [
		'corsivo_mail_enabled' => [
			'label'       => 'Corsivo Mail',
			'available'   => function_exists('corsivo_mail_deliver'),
			'description' => 'Usa i template e il trasporto di Corsivo Mail per le notifiche.',
		],
		'corsivo_privacy_enabled' => [
			'label'       => 'Corsivo Privacy',
			'available'   => function_exists('corsivo_privacy_get_pages_state'),
			'description' => 'Usa la pagina privacy gestita da Corsivo Privacy nel campo consenso.',
		],
	];
}

function corsivo_forms_render_integration_settings() {
	$settings     = corsivo_forms_get_settings();
	$integrations = corsivo_forms_integration_definitions();

	echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
	settings_fields('corsivo_forms_settings');
	echo '<table class="widefat striped" style="margin-top:20px"><thead><tr>';
	echo '<th>' . esc_html('Integrazione') . '</th><th>' . esc_html('Stato') . '</th><th>' . esc_html('Attivazione') . '</th>';
	echo '</tr></thead><tbody>';

	foreach ($integrations as $option_name => $integration) {
		$status = $integration['available'] ? 'Disponibile' : 'Plugin non disponibile';
		echo '<tr><td><strong>' . esc_html($integration['label']) . '</strong><p class="description">' . esc_html($integration['description']) . '</p></td>';
		echo '<td>' . esc_html($status) . '</td><td>';
		echo '<input type="hidden" name="corsivo_forms_settings[' . esc_attr($option_name) . ']" value="0">';
		echo '<label><input type="checkbox" name="corsivo_forms_settings[' . esc_attr($option_name) . ']" value="1"' . checked($settings[$option_name], true, false) . '> ' . esc_html('Abilitata') . '</label>';
		echo '</td></tr>';
	}

	echo '</tbody></table>';
	submit_button('Salva integrazioni');
	echo '</form>';
}

function corsivo_forms_activity_assets_available() {
	$build_path = CORSIVO_FORMS_DIR . 'admin-app/build/';

	return is_readable($build_path . 'index.asset.php')
		&& is_readable($build_path . 'index.js')
		&& is_readable($build_path . 'index.css')
		&& is_readable($build_path . 'index-rtl.css')
		&& is_readable($build_path . 'style-index.css')
		&& is_readable($build_path . 'style-index-rtl.css');
}

function corsivo_forms_activity_option($value) {
	return [
		'value' => $value,
		'label' => ucwords(str_replace('_', ' ', $value)),
	];
}

function corsivo_forms_activity_admin_config() {
	return [
		'endpoint' => rest_url('corsivo/v1/forms/activity'),
		'nonce'    => wp_create_nonce('wp_rest'),
		'events'   => array_map('corsivo_forms_activity_option', corsivo_forms_activity_distinct_values('event')),
		'statuses' => array_map('corsivo_forms_activity_option', corsivo_forms_activity_distinct_values('status')),
		'clear'    => [
			'action' => admin_url('admin-post.php'),
			'nonce'  => wp_create_nonce('corsivo_forms_clear_activity'),
		],
	];
}

function corsivo_forms_enqueue_activity_assets($hook) {
	if ($hook !== 'tools_page_corsivo-forms-settings'
		|| sanitize_key(isset($_GET['tab']) && is_scalar($_GET['tab']) ? wp_unslash((string) $_GET['tab']) : 'activity') !== 'activity'
		|| !current_user_can('manage_options')
		|| !corsivo_forms_activity_assets_available()) {
		return;
	}

	$asset = require CORSIVO_FORMS_DIR . 'admin-app/build/index.asset.php';
	if (!is_array($asset) || !isset($asset['dependencies'], $asset['version'])) {
		return;
	}

	wp_enqueue_script(
		'corsivo-forms-activity',
		CORSIVO_FORMS_URL . 'admin-app/build/index.js',
		(array) $asset['dependencies'],
		(string) $asset['version'],
		true
	);
	wp_enqueue_style('wp-components');
	wp_enqueue_style(
		'corsivo-forms-activity-ui',
		CORSIVO_FORMS_URL . 'admin-app/build/index.css',
		['wp-components'],
		(string) $asset['version']
	);
	wp_style_add_data('corsivo-forms-activity-ui', 'rtl', 'replace');
	wp_enqueue_style(
		'corsivo-forms-activity-app',
		CORSIVO_FORMS_URL . 'admin-app/build/style-index.css',
		['corsivo-forms-activity-ui'],
		(string) $asset['version']
	);
	wp_style_add_data('corsivo-forms-activity-app', 'rtl', 'replace');
	wp_add_inline_script(
		'corsivo-forms-activity',
		'window.corsivoFormsActivityConfig = ' . wp_json_encode(
			corsivo_forms_activity_admin_config(),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		) . ';',
		'before'
	);
}

function corsivo_forms_activity_rest_permission() {
	return current_user_can('manage_options');
}

function corsivo_forms_register_activity_rest_route() {
	register_rest_route('corsivo/v1/forms', '/activity', [
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => 'corsivo_forms_activity_rest_permission',
		'callback'            => 'corsivo_forms_activity_rest_collection',
		'args'                => [
			'event' => [
				'sanitize_callback' => 'sanitize_key',
			],
			'status' => [
				'sanitize_callback' => 'sanitize_key',
			],
			'search' => [
				'sanitize_callback' => 'sanitize_text_field',
			],
			'page' => [
				'default'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'default'           => 20,
				'sanitize_callback' => 'absint',
			],
		],
	]);
}

function corsivo_forms_activity_rest_item($row) {
	$form_label = (string) ($row['form_title'] ?? '');
	if ($form_label === '' && !empty($row['form_id'])) {
		$form_label = (string) $row['form_id'];
	}
	$detail = (string) ($row['message'] ?? '');
	if ($detail === '' && !empty($row['error_code'])) {
		$detail = (string) $row['error_code'];
	}

	return [
		'id'                 => (string) ($row['id'] ?? ''),
		'date'               => !empty($row['created_at_gmt']) ? get_date_from_gmt($row['created_at_gmt'], 'd/m/Y H:i:s') : '—',
		'event'              => (string) (($row['event'] ?? '') ?: '—'),
		'status'             => (string) (($row['status'] ?? '') ?: '—'),
		'form'               => $form_label ?: '—',
		'storageStatus'      => (string) (($row['storage_status'] ?? '') ?: '—'),
		'notificationStatus' => (string) (($row['notification_status'] ?? '') ?: '—'),
		'detail'             => $detail ?: '—',
		'requestId'          => (string) (($row['request_id'] ?? '') ?: '—'),
	];
}

function corsivo_forms_activity_rest_collection(WP_REST_Request $request) {
	$args = [
		'event'    => sanitize_key((string) $request->get_param('event')),
		'status'   => sanitize_key((string) $request->get_param('status')),
		'search'   => sanitize_text_field((string) $request->get_param('search')),
		'page'     => max(1, absint($request->get_param('page'))),
		'per_page' => max(1, min(100, absint($request->get_param('per_page')))),
	];
	$total = corsivo_forms_activity_count($args);
	$items = array_map('corsivo_forms_activity_rest_item', corsivo_forms_activity_query($args));
	$response = rest_ensure_response($items);
	$response->header('X-WP-Total', (string) $total);
	$response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $args['per_page'])));

	return $response;
}

function corsivo_forms_render_activity_tab() {
	if (!function_exists('corsivo_forms_activity_query') || !function_exists('corsivo_forms_activity_count')) {
		echo '<div class="notice notice-error"><p>' . esc_html('Il registro attività non è disponibile.') . '</p></div>';
		return;
	}
	if (!corsivo_forms_get_setting('log_enabled', true)) {
		echo '<div class="notice notice-info"><p>' . esc_html('Il registro è disattivato. Restano visibili per un giorno gli eventi amministrativi di disattivazione e pulizia e i record tecnici necessari all’idempotenza delle richieste.') . '</p></div>';
	}
	if (!corsivo_forms_activity_assets_available()) {
		echo '<div class="notice notice-error"><p>' . esc_html('Build del registro attività mancante o incompleta. Reinstalla un package di release valido.') . '</p></div>';
		return;
	}

	echo '<div id="corsivo-forms-activity-app"></div>';
}

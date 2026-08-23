<?php

if (!defined('ABSPATH')) {
	exit;
}

add_action('init', 'corsivo_forms_register_submission_cpt');
function corsivo_forms_register_submission_cpt() {
	$capabilities = [
		'edit_post'              => 'manage_options',
		'read_post'              => 'manage_options',
		'delete_post'            => 'manage_options',
		'edit_posts'             => 'manage_options',
		'edit_others_posts'      => 'manage_options',
		'publish_posts'          => 'manage_options',
		'read_private_posts'     => 'manage_options',
		'delete_posts'           => 'manage_options',
		'delete_private_posts'   => 'manage_options',
		'delete_published_posts' => 'manage_options',
		'delete_others_posts'    => 'manage_options',
		'edit_private_posts'     => 'manage_options',
		'edit_published_posts'   => 'manage_options',
		'create_posts'           => 'do_not_allow',
	];

	register_post_type('corsivo_submission', [
		'labels' => [
			'name'          => 'Richieste',
			'singular_name' => 'Richiesta',
			'menu_name'     => 'Forms',
			'all_items'     => 'Richieste',
			'edit_item'     => 'Dettaglio richiesta',
			'search_items'  => 'Cerca richieste',
			'not_found'     => 'Nessuna richiesta ricevuta.',
		],
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => 'tools.php',
		'show_in_rest'        => false,
		'supports'            => [],
		'map_meta_cap'        => false,
		'capabilities'        => $capabilities,
		'exclude_from_search' => true,
	]);
}

function corsivo_forms_store_submission(array $submission) {
	$submitted_at_gmt = (string) ($submission['submitted_at_gmt'] ?? current_time('mysql', true));
	$title_date       = get_date_from_gmt($submitted_at_gmt, 'd/m/Y H:i:s');
	$post_id          = wp_insert_post([
		'post_type'   => 'corsivo_submission',
		'post_status' => 'draft',
		'post_title'  => $submission['form_title'] . ' — ' . $title_date,
	], true);

	if (is_wp_error($post_id)) {
		return $post_id;
	}
	if (array_key_exists('prepared_uploads', $submission)) {
		$stored_uploads = corsivo_forms_store_submission_uploads($post_id, (array) $submission['prepared_uploads']);
		if (is_wp_error($stored_uploads)) {
			$discarded = corsivo_forms_discard_partial_submission($post_id);
			return new WP_Error(
				$discarded ? 'submission_upload_storage_failed' : 'submission_storage_cleanup_failed',
				__('La richiesta non può essere archiviata.', 'corsivo-forms'),
				['upload_error' => $stored_uploads->get_error_code()]
			);
		}
	}


	$consents = (array) ($submission['consents'] ?? []);
	$meta     = [
		'_corsivo_forms_rows'                => $submission['rows'],
		'_corsivo_forms_form_title'          => $submission['form_title'],
		'_corsivo_forms_form_id'             => $submission['form_id'],
		'_corsivo_forms_request_id'          => $submission['request_id'],
		'_corsivo_forms_page_id'             => $submission['page_id'],
		'_corsivo_forms_page_title'          => $submission['page_title'],
		'_corsivo_forms_page_url'            => $submission['page_url'],
		'_corsivo_forms_sender_email'        => $submission['sender_email'],
		'_corsivo_forms_submitted_at_gmt'    => $submitted_at_gmt,
		'_corsivo_forms_language'            => $submission['language'],
		'_corsivo_forms_consents'            => $consents,
		'_corsivo_forms_notification_status' => 'pending',
	];

	foreach ($meta as $key => $value) {
		update_post_meta($post_id, $key, wp_slash($value));
		if (!corsivo_forms_meta_value_matches(get_post_meta($post_id, $key, true), $value)) {
			$discarded = corsivo_forms_discard_partial_submission($post_id);
			return new WP_Error($discarded ? 'submission_storage_failed' : 'submission_storage_cleanup_failed', 'La richiesta non può essere archiviata.', ['meta_key' => $key]);
		}
	}

	$email_addresses = array_values(array_unique(array_filter(array_map(
		static fn($email) => strtolower(sanitize_email((string) $email)),
		(array) ($submission['email_addresses'] ?? [$submission['sender_email'] ?? ''])
	))));
	foreach ($email_addresses as $email_address) {
		add_post_meta($post_id, '_corsivo_forms_email_index', $email_address, false);
	}
	$stored_email_addresses = array_map('strtolower', get_post_meta($post_id, '_corsivo_forms_email_index', false));
	sort($email_addresses);
	sort($stored_email_addresses);
	if ($stored_email_addresses !== $email_addresses) {
		$discarded = corsivo_forms_discard_partial_submission($post_id);
		return new WP_Error($discarded ? 'submission_storage_failed' : 'submission_storage_cleanup_failed', 'La richiesta non può essere archiviata.', ['meta_key' => '_corsivo_forms_email_index']);
	}
	$published_id = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
	if (is_wp_error($published_id) || get_post_status($post_id) !== 'publish') {
		$discarded = corsivo_forms_discard_partial_submission($post_id);
		return new WP_Error($discarded ? 'submission_storage_failed' : 'submission_storage_cleanup_failed', 'La richiesta non può essere archiviata.', ['post_status' => 'publish']);
	}

	return (int) $post_id;
}

function corsivo_forms_discard_partial_submission($post_id) {
	global $wpdb;

	$had_flag = array_key_exists('corsivo_forms_compensating_delete', $GLOBALS);
	$previous_flag = $GLOBALS['corsivo_forms_compensating_delete'] ?? false;
	$GLOBALS['corsivo_forms_compensating_delete'] = true;
	$had_upload_error = array_key_exists('corsivo_forms_upload_deletion_error', $GLOBALS);
	$previous_upload_error = $GLOBALS['corsivo_forms_upload_deletion_error'] ?? null;
	unset($GLOBALS['corsivo_forms_upload_deletion_error']);

	try {
		$deleted = wp_delete_post((int) $post_id, true);
		if (($GLOBALS['corsivo_forms_upload_deletion_error'] ?? null) instanceof WP_Error) {
			return false;
		}
		$post_exists = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", (int) $post_id));
		$meta_exists = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = %d LIMIT 1", (int) $post_id));
		if (!$deleted || $post_exists || $meta_exists) {
			$wpdb->delete($wpdb->postmeta, ['post_id' => (int) $post_id], ['%d']);
			$wpdb->delete($wpdb->posts, ['ID' => (int) $post_id], ['%d']);
			clean_post_cache((int) $post_id);
		}
	} finally {
		if ($had_flag) {
			$GLOBALS['corsivo_forms_compensating_delete'] = $previous_flag;
		} else {
			unset($GLOBALS['corsivo_forms_compensating_delete']);
		}
		if ($had_upload_error) {
			$GLOBALS['corsivo_forms_upload_deletion_error'] = $previous_upload_error;
		} else {
			unset($GLOBALS['corsivo_forms_upload_deletion_error']);
		}
	}
	$post_exists = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", (int) $post_id));
	$meta_exists = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = %d LIMIT 1", (int) $post_id));
	return !$post_exists && !$meta_exists;
}

function corsivo_forms_meta_value_matches($stored, $expected) {
	if (is_array($stored) || is_array($expected) || is_object($stored) || is_object($expected)) {
		return wp_json_encode($stored) === wp_json_encode($expected);
	}
	return (string) $stored === (string) $expected;
}

function corsivo_forms_update_notification_status($submission_id, array $notification) {
	if (!$submission_id) {
		return;
	}

	$status = sanitize_key((string) ($notification['status'] ?? 'unknown'));
	update_post_meta($submission_id, '_corsivo_forms_notification_status', $status);
	update_post_meta($submission_id, '_corsivo_forms_notification_summary', [
		'total'      => (int) ($notification['total'] ?? 0),
		'accepted'   => (int) ($notification['accepted'] ?? 0),
		'failed'     => (int) ($notification['failed'] ?? 0),
		'transports' => array_map('intval', (array) ($notification['transports'] ?? [])),
	]);

}

add_filter('manage_corsivo_submission_posts_columns', 'corsivo_forms_submission_columns');
function corsivo_forms_submission_columns($columns) {
	return [
		'cb'                   => $columns['cb'],
		'title'                => 'Richiesta',
		'corsivo_forms_sender' => 'Mittente',
		'corsivo_forms_page'   => 'Pagina',
		'corsivo_forms_mail'   => 'Notifica',
		'date'                 => $columns['date'],
	];
}

add_filter('posts_search', 'corsivo_forms_submission_search', 10, 2);
function corsivo_forms_submission_search($search, WP_Query $query) {
	if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'corsivo_submission' || !current_user_can('manage_options')) {
		return $search;
	}

	$term = trim((string) $query->get('s'));
	if ($term === '') {
		return $search;
	}

	global $wpdb;
	$like = '%' . $wpdb->esc_like(corsivo_forms_limit_text($term, 160)) . '%';
	return $wpdb->prepare(
		" AND (
			{$wpdb->posts}.post_title LIKE %s
			OR EXISTS (
				SELECT 1
				FROM {$wpdb->postmeta} corsivo_forms_search_meta
				WHERE corsivo_forms_search_meta.post_id = {$wpdb->posts}.ID
					AND corsivo_forms_search_meta.meta_key IN ('_corsivo_forms_sender_email', '_corsivo_forms_form_title', '_corsivo_forms_page_title', '_corsivo_forms_rows')
					AND corsivo_forms_search_meta.meta_value LIKE %s
			)
		)",
		$like,
		$like
	);
}

add_action('manage_corsivo_submission_posts_custom_column', 'corsivo_forms_submission_column_content', 10, 2);
function corsivo_forms_submission_column_content($column, $post_id) {
	if ($column === 'corsivo_forms_sender') {
		$email = sanitize_email((string) get_post_meta($post_id, '_corsivo_forms_sender_email', true));
		echo $email ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '—';
	}

	if ($column === 'corsivo_forms_page') {
		$page_id    = (int) get_post_meta($post_id, '_corsivo_forms_page_id', true);
		$page_title = (string) get_post_meta($post_id, '_corsivo_forms_page_title', true);
		$page_url   = (string) get_post_meta($post_id, '_corsivo_forms_page_url', true);
		if ($page_id && get_post($page_id)) {
			$page_title = get_the_title($page_id);
			$page_url   = get_permalink($page_id);
		}
		echo $page_url ? '<a href="' . esc_url($page_url) . '">' . esc_html($page_title ?: $page_url) . '</a>' : '—';
	}

	if ($column === 'corsivo_forms_mail') {
		$status = (string) get_post_meta($post_id, '_corsivo_forms_notification_status', true);
		echo esc_html(corsivo_forms_notification_status_label($status));
	}
}

function corsivo_forms_notification_status_label($status) {
	$labels = [
		'pending'  => 'In elaborazione',
		'accepted' => 'Accettata',
		'partial'  => 'Parziale',
		'failed'   => 'Fallita',
		'unknown'  => 'Esito non noto',
	];
	return $labels[$status] ?? 'Esito non noto';
}

add_action('add_meta_boxes_corsivo_submission', 'corsivo_forms_submission_meta_boxes');
function corsivo_forms_submission_meta_boxes() {
	remove_meta_box('submitdiv', 'corsivo_submission', 'side');
	add_meta_box('corsivo-form-submission-data', 'Dati inviati', 'corsivo_forms_render_submission_meta_box', 'corsivo_submission', 'normal', 'high');
}

function corsivo_forms_render_submission_meta_box($post) {
	$rows             = (array) get_post_meta($post->ID, '_corsivo_forms_rows', true);
	$page_id          = (int) get_post_meta($post->ID, '_corsivo_forms_page_id', true);
	$page_title       = (string) get_post_meta($post->ID, '_corsivo_forms_page_title', true);
	$page_url         = (string) get_post_meta($post->ID, '_corsivo_forms_page_url', true);
	$consents         = get_post_meta($post->ID, '_corsivo_forms_consents', true);
	$consents         = is_array($consents) ? $consents : [];
	$submitted_at_gmt = (string) get_post_meta($post->ID, '_corsivo_forms_submitted_at_gmt', true);
	$status           = (string) get_post_meta($post->ID, '_corsivo_forms_notification_status', true);
	$uploads          = corsivo_forms_submission_uploads($post->ID);

	echo '<table class="widefat striped">';
	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$value = ($row['type'] ?? '') === 'textarea'
			? nl2br(esc_html($row['display'] ?? ''))
			: esc_html($row['display'] ?? '');
		echo '<tr><th style="width:200px;text-align:left;">' . esc_html($row['label'] ?? '') . '</th><td>' . $value . '</td></tr>';
	}
	if (is_wp_error($uploads)) {
		echo '<tr><th style="text-align:left;">Allegati</th><td>' . esc_html__('I file non sono disponibili.', 'corsivo-forms') . '</td></tr>';
	} elseif ($uploads) {
		$upload_links = [];
		foreach ($uploads as $upload) {
			$download_url = corsivo_forms_submission_upload_download_url($post->ID, $upload['id']);
			if ($download_url === '') {
				continue;
			}
			$upload_links[] = '<a href="' . esc_url($download_url) . '">' . esc_html($upload['original_name']) . '</a> <small>(' . esc_html(size_format($upload['size'])) . ')</small>';
		}
		if ($upload_links) {
			echo '<tr><th style="text-align:left;">Allegati</th><td><ul><li>' . implode('</li><li>', $upload_links) . '</li></ul></td></tr>';
		}
	}


	if ($page_id && get_post($page_id)) {
		$page_title = get_the_title($page_id);
		$page_url   = get_permalink($page_id);
	}
	if ($page_url) {
		echo '<tr><th style="text-align:left;">Pagina</th><td><a href="' . esc_url($page_url) . '">' . esc_html($page_title ?: $page_url) . '</a></td></tr>';
	}
	if ($submitted_at_gmt) {
		echo '<tr><th style="text-align:left;">Ricevuta</th><td>' . esc_html(get_date_from_gmt($submitted_at_gmt, 'd/m/Y H:i:s')) . '</td></tr>';
	}
	echo '<tr><th style="text-align:left;">Notifica</th><td>' . esc_html(corsivo_forms_notification_status_label($status)) . '</td></tr>';

	foreach ($consents as $consent) {
		if (!is_array($consent)) {
			continue;
		}
		$policy = !empty($consent['policy_url'])
			? ' — <a href="' . esc_url($consent['policy_url']) . '" target="_blank" rel="noopener noreferrer">Informativa</a>'
			: '';
		echo '<tr><th style="text-align:left;">Consenso</th><td>' . esc_html($consent['label'] ?? '') . $policy . '<br><small>' . esc_html($consent['accepted_at_gmt'] ?? '') . ' · ' . esc_html($consent['evidence_hash'] ?? '') . '</small></td></tr>';
	}
	echo '</table>';
}

add_filter('use_block_editor_for_post_type', 'corsivo_forms_disable_submission_block_editor', 10, 2);
function corsivo_forms_disable_submission_block_editor($use_block_editor, $post_type) {
	return $post_type === 'corsivo_submission' ? false : $use_block_editor;
}

add_filter('post_row_actions', 'corsivo_forms_submission_row_actions', 10, 2);
function corsivo_forms_submission_row_actions($actions, $post) {
	if ($post->post_type !== 'corsivo_submission') {
		return $actions;
	}
	unset($actions['inline hide-if-no-js'], $actions['view']);
	if (isset($actions['edit'])) {
		$actions['edit'] = '<a href="' . esc_url(get_edit_post_link($post->ID)) . '">Apri</a>';
	}
	return $actions;
}

add_filter('bulk_actions-edit-corsivo_submission', 'corsivo_forms_submission_bulk_actions');
function corsivo_forms_submission_bulk_actions($actions) {
	unset($actions['edit']);
	return $actions;
}

add_action('wp_trash_post', 'corsivo_forms_log_submission_trash', 10, 2);
function corsivo_forms_log_submission_trash($post_id, $previous_status = '') {
	if (!empty($GLOBALS['corsivo_forms_cleanup_running']) || !empty($GLOBALS['corsivo_forms_compensating_delete']) || !empty($GLOBALS['corsivo_forms_suppress_activity_log'])) {
		return;
	}
	$post = get_post($post_id);
	if ($post && $post->post_type === 'corsivo_submission' && function_exists('corsivo_forms_activity_record_event')) {
		corsivo_forms_activity_record_event('deleted', 'success', [
			'submission_id' => $post_id,
			'form_id'       => get_post_meta($post_id, '_corsivo_forms_form_id', true),
			'form_title'    => get_post_meta($post_id, '_corsivo_forms_form_title', true),
			'page_id'       => (int) get_post_meta($post_id, '_corsivo_forms_page_id', true),
			'actor_id'      => get_current_user_id(),
		]);
	}
}

add_action('before_delete_post', 'corsivo_forms_log_submission_deletion', 10, 2);
function corsivo_forms_log_submission_deletion($post_id, $post) {
	if (!empty($GLOBALS['corsivo_forms_cleanup_running']) || !empty($GLOBALS['corsivo_forms_compensating_delete']) || !empty($GLOBALS['corsivo_forms_suppress_activity_log'])) {
		return;
	}
	if ($post instanceof WP_Post && $post->post_type === 'corsivo_submission' && function_exists('corsivo_forms_activity_record_event')) {
		corsivo_forms_activity_record_event('deleted_permanently', 'success', [
			'submission_id' => $post_id,
			'form_id'       => get_post_meta($post_id, '_corsivo_forms_form_id', true),
			'form_title'    => get_post_meta($post_id, '_corsivo_forms_form_title', true),
			'page_id'       => (int) get_post_meta($post_id, '_corsivo_forms_page_id', true),
			'actor_id'      => get_current_user_id(),
		]);
	}
}

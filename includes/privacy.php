<?php

if (!defined('ABSPATH')) {
	exit;
}

const CORSIVO_FORMS_PRIVACY_BATCH_SIZE = 100;
const CORSIVO_FORMS_CLEANUP_BATCH_SIZE = 250;

add_filter('wp_privacy_personal_data_exporters', 'corsivo_forms_register_privacy_exporter');
function corsivo_forms_register_privacy_exporter($exporters) {
	$exporters['corsivo-forms'] = [
		'exporter_friendly_name' => __('Richieste Corsivo Forms', 'corsivo-forms'),
		'callback'               => 'corsivo_forms_privacy_exporter',
	];

	return $exporters;
}

add_filter('wp_privacy_personal_data_erasers', 'corsivo_forms_register_privacy_eraser');
function corsivo_forms_register_privacy_eraser($erasers) {
	$erasers['corsivo-forms'] = [
		'eraser_friendly_name' => __('Richieste Corsivo Forms', 'corsivo-forms'),
		'callback'             => 'corsivo_forms_privacy_eraser',
	];

	return $erasers;
}

function corsivo_forms_privacy_submission_ids($email_address, $limit, $offset = 0) {
	global $wpdb;

	return array_map('intval', $wpdb->get_col($wpdb->prepare(
		"SELECT submissions.ID
		FROM {$wpdb->posts} submissions
		WHERE submissions.post_type = %s
			AND submissions.post_status = 'publish'
			AND EXISTS (
				SELECT 1
				FROM {$wpdb->postmeta} email_index
				WHERE email_index.post_id = submissions.ID
					AND email_index.meta_key = '_corsivo_forms_email_index'
					AND email_index.meta_value = %s
			)
		ORDER BY submissions.ID ASC
		LIMIT %d OFFSET %d",
		'corsivo_submission',
		strtolower((string) $email_address),
		max(1, (int) $limit),
		max(0, (int) $offset)
	)));
}

function corsivo_forms_privacy_submission_ids_after($email_address, $limit, $after_id = 0) {
	global $wpdb;

	return array_map('intval', $wpdb->get_col($wpdb->prepare(
		"SELECT submissions.ID
		FROM {$wpdb->posts} submissions
		WHERE submissions.post_type = %s
			AND submissions.ID > %d
			AND EXISTS (
				SELECT 1
				FROM {$wpdb->postmeta} sender
				WHERE sender.post_id = submissions.ID
					AND sender.meta_key = '_corsivo_forms_email_index'
					AND sender.meta_value = %s
			)
		ORDER BY submissions.ID ASC
		LIMIT %d",
		'corsivo_submission',
		max(0, (int) $after_id),
		strtolower((string) $email_address),
		max(1, (int) $limit)
	)));
}

function corsivo_forms_privacy_eraser_state_key($email_address) {
	return 'corsivo_forms_eraser_' . hash_hmac('sha256', strtolower((string) $email_address), wp_salt('nonce'));
}

function corsivo_forms_privacy_exporter($email_address, $page = 1) {
	$email_address = sanitize_email($email_address);
	$page          = max(1, (int) $page);

	if ($email_address === '') {
		return ['data' => [], 'done' => true];
	}

	$offset = ($page - 1) * CORSIVO_FORMS_PRIVACY_BATCH_SIZE;
	$ids    = corsivo_forms_privacy_submission_ids($email_address, CORSIVO_FORMS_PRIVACY_BATCH_SIZE + 1, $offset);
	$done   = count($ids) <= CORSIVO_FORMS_PRIVACY_BATCH_SIZE;
	$ids    = array_slice($ids, 0, CORSIVO_FORMS_PRIVACY_BATCH_SIZE);
	$data   = [];

	foreach ($ids as $submission_id) {
		$submission = get_post($submission_id);
		if (!$submission) {
			continue;
		}

		$form_title = trim((string) get_post_meta($submission_id, '_corsivo_forms_form_title', true));
		$submitted_at_gmt = (string) get_post_meta($submission_id, '_corsivo_forms_submitted_at_gmt', true);

		$item_data = [
			[
				'name'  => __('Modulo', 'corsivo-forms'),
				'value' => $form_title !== '' ? $form_title : __('Modulo', 'corsivo-forms'),
			],
			[
				'name'  => __('Data invio (GMT)', 'corsivo-forms'),
				'value' => $submitted_at_gmt . ' UTC',
			],
		];

		foreach ((array) get_post_meta($submission_id, '_corsivo_forms_rows', true) as $row) {
			if (!is_array($row) || !array_key_exists('label', $row) || !array_key_exists('display', $row)) {
				continue;
			}
			$label = trim(wp_strip_all_tags((string) $row['label']));
			$value = $row['display'];

			if ($label === '') {
				$label = __('Campo', 'corsivo-forms');
			}
			if (is_bool($value)) {
				$value = $value ? __('Sì', 'corsivo-forms') : __('No', 'corsivo-forms');
			} elseif (!is_scalar($value)) {
				$value = wp_json_encode($value);
			}

			$item_data[] = [
				'name'  => $label,
				'value' => wp_strip_all_tags((string) $value),
			];
		}
		$upload_export = corsivo_forms_privacy_export_uploads($submission_id);
		if (is_array($upload_export)) {
			$item_data = array_merge($item_data, $upload_export);
		} elseif (is_wp_error($upload_export)) {
			$item_data[] = ['name' => __('File caricati', 'corsivo-forms'), 'value' => __('Metadati non disponibili.', 'corsivo-forms')];
		}


		$page_title = (string) get_post_meta($submission_id, '_corsivo_forms_page_title', true);
		$page_url   = (string) get_post_meta($submission_id, '_corsivo_forms_page_url', true);
		$language   = (string) get_post_meta($submission_id, '_corsivo_forms_language', true);
		$request_id = (string) get_post_meta($submission_id, '_corsivo_forms_request_id', true);
		if ($page_title !== '' || $page_url !== '') {
			$item_data[] = [
				'name'  => __('Pagina di origine', 'corsivo-forms'),
				'value' => trim($page_title . ($page_url !== '' ? ' — ' . $page_url : '')),
			];
		}
		if ($language !== '') {
			$item_data[] = ['name' => __('Lingua', 'corsivo-forms'), 'value' => $language];
		}
		if ($request_id !== '') {
			$item_data[] = ['name' => __('Identificativo richiesta', 'corsivo-forms'), 'value' => $request_id];
		}
		$consents = get_post_meta($submission_id, '_corsivo_forms_consents', true);
		$consents = is_array($consents) ? $consents : [];
		foreach ($consents as $consent) {
			if (!is_array($consent)) {
				continue;
			}
			$item_data[] = [
				'name'  => __('Evidenza del consenso', 'corsivo-forms'),
				'value' => implode("\n", array_filter([
					(string) ($consent['label'] ?? ''),
					(string) ($consent['policy_url'] ?? ''),
					(string) ($consent['policy_updated_at_gmt'] ?? ''),
					(string) ($consent['policy_hash'] ?? ''),
					(string) ($consent['evidence_hash'] ?? ''),
					(string) ($consent['accepted_at_gmt'] ?? ''),
				], static fn($value) => $value !== '')),
			];
		}
		$data[] = [
			'group_id'    => 'corsivo-forms-submissions',
			'group_label' => __('Richieste inviate tramite Corsivo Forms', 'corsivo-forms'),
			'item_id'     => 'corsivo-forms-submission-' . $submission_id,
			'data'        => $item_data,
		];
	}

	return ['data' => $data, 'done' => $done];
}

function corsivo_forms_privacy_eraser($email_address, $page = 1) {
	$email_address = sanitize_email($email_address);
	$page          = max(1, (int) $page);

	if ($email_address === '') {
		return [
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	$state_key = corsivo_forms_privacy_eraser_state_key($email_address);
	$state     = $page > 1 ? get_transient($state_key) : false;
	$after_id  = is_array($state) && (int) ($state['next_page'] ?? 0) === $page
		? max(0, (int) ($state['after_id'] ?? 0))
		: 0;
	$ids       = corsivo_forms_privacy_submission_ids_after($email_address, CORSIVO_FORMS_PRIVACY_BATCH_SIZE + 1, $after_id);
	$has_more  = count($ids) > CORSIVO_FORMS_PRIVACY_BATCH_SIZE;
	$ids       = array_slice($ids, 0, CORSIVO_FORMS_PRIVACY_BATCH_SIZE);
	$deleted_ids     = [];
	$items_retained  = false;
	$had_suppression = array_key_exists('corsivo_forms_suppress_activity_log', $GLOBALS);
	$was_suppressed  = $GLOBALS['corsivo_forms_suppress_activity_log'] ?? false;
	$GLOBALS['corsivo_forms_suppress_activity_log'] = true;

	try {
		foreach ($ids as $submission_id) {
			if (wp_delete_post($submission_id, true)) {
				$deleted_ids[] = $submission_id;
			} else {
				$items_retained = true;
			}
		}
	} finally {
		if ($had_suppression) {
			$GLOBALS['corsivo_forms_suppress_activity_log'] = $was_suppressed;
		} else {
			unset($GLOBALS['corsivo_forms_suppress_activity_log']);
		}
	}

	if (function_exists('corsivo_forms_activity_record_event')) {
		foreach ($deleted_ids as $submission_id) {
			corsivo_forms_activity_record_event('erased', 'success', [
				'submission_id' => $submission_id,
				'actor_id'      => get_current_user_id(),
			]);
		}
	}

	$messages = $items_retained
		? [__('Non è stato possibile eliminare una o più richieste.', 'corsivo-forms')]
		: [];

	if ($has_more && $ids) {
		set_transient($state_key, [
			'after_id'  => (int) end($ids),
			'next_page' => $page + 1,
		], DAY_IN_SECONDS);
	} else {
		delete_transient($state_key);
	}

	return [
		'items_removed'  => $deleted_ids !== [],
		'items_retained' => $items_retained,
		'messages'       => $messages,
		'done'           => !$has_more,
	];
}

function corsivo_forms_privacy_get_setting($key, $default = null) {
	if (function_exists('corsivo_forms_get_setting')) {
		$value = corsivo_forms_get_setting($key);
		return $value === null ? $default : $value;
	}

	$settings = (array) get_option('corsivo_forms_settings', []);
	return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

add_action('admin_init', 'corsivo_forms_add_privacy_policy_content');
function corsivo_forms_add_privacy_policy_content() {
	if (!function_exists('wp_add_privacy_policy_content')) {
		return;
	}

	$submission_retention_days = max(0, (int) corsivo_forms_privacy_get_setting('submission_retention_days', 0));
	$activity_retention_days   = corsivo_forms_privacy_get_setting('log_enabled', true)
		? max(1, (int) corsivo_forms_privacy_get_setting('log_retention_days', 90))
		: 1;

	$submission_retention = $submission_retention_days > 0
		? sprintf(
			_n(
				'Le richieste archiviate vengono eliminate automaticamente dopo %s giorno.',
				'Le richieste archiviate vengono eliminate automaticamente dopo %s giorni.',
				$submission_retention_days,
				'corsivo-forms'
			),
			number_format_i18n($submission_retention_days)
		)
		: __('Le richieste archiviate non hanno una scadenza automatica e restano conservate finché non vengono eliminate manualmente o in seguito a una richiesta dell’interessato.', 'corsivo-forms');

	$activity_retention = $activity_retention_days > 0
		? sprintf(
			_n(
				'Gli eventi tecnici privi dei valori inseriti nei moduli vengono conservati per %s giorno.',
				'Gli eventi tecnici privi dei valori inseriti nei moduli vengono conservati per %s giorni.',
				$activity_retention_days,
				'corsivo-forms'
			),
			number_format_i18n($activity_retention_days)
		)
		: __('Gli eventi tecnici privi dei valori inseriti nei moduli non hanno una scadenza automatica.', 'corsivo-forms');

	$content = '<p>' . esc_html__('Quando una persona invia un modulo, il sito tratta i dati inseriti per gestire la richiesta e inviare le notifiche configurate.', 'corsivo-forms') . '</p>';
	$content .= '<p>' . esc_html__('Se il modulo è configurato per l’archiviazione, la richiesta viene salvata nel sito insieme al modulo di origine e alla data di invio.', 'corsivo-forms') . ' ' . esc_html($submission_retention) . '</p>';
	$content .= '<p>' . esc_html__('Gli eventuali file caricati vengono conservati in uno spazio privato per la stessa durata della richiesta e sono accessibili solo agli amministratori autorizzati.', 'corsivo-forms') . '</p>';
	$content .= '<p>' . esc_html($activity_retention) . ' ' . esc_html__('I dati archiviati possono essere esportati o eliminati tramite gli strumenti privacy di WordPress.', 'corsivo-forms') . '</p>';

	wp_add_privacy_policy_content(__('Corsivo Forms', 'corsivo-forms'), wp_kses_post($content));
}

add_action('init', 'corsivo_forms_schedule_daily_cleanup');
function corsivo_forms_schedule_daily_cleanup() {
	if (!wp_next_scheduled('corsivo_forms_daily_cleanup')) {
		wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'corsivo_forms_daily_cleanup');
	}
}

add_action('corsivo_forms_daily_cleanup', 'corsivo_forms_daily_cleanup');
add_action('corsivo_forms_cleanup_batch', 'corsivo_forms_cleanup_submissions');
function corsivo_forms_daily_cleanup() {
	if (function_exists('corsivo_forms_activity_purge')) {
		corsivo_forms_activity_purge();
	}
	corsivo_forms_cleanup_partial_submissions();
	delete_transient('corsivo_forms_cleanup_cursor');
	corsivo_forms_cleanup_submissions();
}

function corsivo_forms_cleanup_partial_submissions() {
	global $wpdb;

	$ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
		"SELECT ID
		FROM {$wpdb->posts}
		WHERE post_type = %s
			AND post_status IN ('draft', 'auto-draft')
			AND post_date_gmt <= %s
		ORDER BY ID ASC
		LIMIT %d",
		'corsivo_submission',
		gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
		CORSIVO_FORMS_CLEANUP_BATCH_SIZE
	)));

	$deleted = 0;
	foreach ($ids as $submission_id) {
		if (corsivo_forms_discard_partial_submission($submission_id)) {
			$deleted++;
		}
	}
	return $deleted;
}

function corsivo_forms_expired_submission_ids($after_id, $cutoff) {
	global $wpdb;

	return array_map('intval', $wpdb->get_col($wpdb->prepare(
		"SELECT submissions.ID
		FROM {$wpdb->posts} submissions
		INNER JOIN {$wpdb->postmeta} submitted_at
			ON submitted_at.post_id = submissions.ID
			AND submitted_at.meta_key = %s
		WHERE submissions.post_type = %s
			AND submissions.post_status = 'publish'
			AND submissions.ID > %d
			AND submitted_at.meta_value <> ''
			AND submitted_at.meta_value <> '0000-00-00 00:00:00'
			AND submitted_at.meta_value <= %s
		ORDER BY submissions.ID ASC
		LIMIT %d",
		'_corsivo_forms_submitted_at_gmt',
		'corsivo_submission',
		max(0, (int) $after_id),
		(string) $cutoff,
		CORSIVO_FORMS_CLEANUP_BATCH_SIZE
	)));
}

function corsivo_forms_cleanup_submissions() {
	$retention_days = max(0, (int) corsivo_forms_privacy_get_setting('submission_retention_days', 0));
	if ($retention_days === 0) {
		delete_transient('corsivo_forms_cleanup_cursor');
		return 0;
	}

	$after_id = max(0, (int) get_transient('corsivo_forms_cleanup_cursor'));
	$cutoff   = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));
	$ids      = corsivo_forms_expired_submission_ids($after_id, $cutoff);

	$had_suppression = array_key_exists('corsivo_forms_suppress_activity_log', $GLOBALS);
	$was_suppressed  = $GLOBALS['corsivo_forms_suppress_activity_log'] ?? false;
	$GLOBALS['corsivo_forms_suppress_activity_log'] = true;
	$deleted    = 0;
	$failed_ids = [];

	try {
		foreach ($ids as $submission_id) {
			if (wp_delete_post((int) $submission_id, true)) {
				$deleted++;
			} else {
				$failed_ids[] = $submission_id;
			}
		}
	} finally {
		if ($had_suppression) {
			$GLOBALS['corsivo_forms_suppress_activity_log'] = $was_suppressed;
		} else {
			unset($GLOBALS['corsivo_forms_suppress_activity_log']);
		}
	}

	if (count($ids) === CORSIVO_FORMS_CLEANUP_BATCH_SIZE) {
		set_transient('corsivo_forms_cleanup_cursor', (int) end($ids), 2 * DAY_IN_SECONDS);
		if (!wp_next_scheduled('corsivo_forms_cleanup_batch')) {
			wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'corsivo_forms_cleanup_batch');
		}
	} else {
		delete_transient('corsivo_forms_cleanup_cursor');
	}
	if (($deleted > 0 || $failed_ids) && function_exists('corsivo_forms_activity_record_event')) {
		$status = $failed_ids ? ($deleted > 0 ? 'partial' : 'failed') : 'success';
		corsivo_forms_activity_record_event('retention_cleanup', $status, [
			'submission_id' => (int) ($failed_ids[0] ?? 0),
			'error_code'    => $failed_ids ? 'submission_delete_failed' : '',
			'message'       => $failed_ids
				? sprintf(__('Eliminate: %1$d. Non eliminate: %2$d.', 'corsivo-forms'), $deleted, count($failed_ids))
				: sprintf(_n('%d richiesta eliminata.', '%d richieste eliminate.', $deleted, 'corsivo-forms'), $deleted),
		]);
	}
	return $deleted;
}

function corsivo_forms_clear_daily_cleanup_schedule($network_wide = false) {
	if (!$network_wide || !is_multisite()) {
		wp_clear_scheduled_hook('corsivo_forms_daily_cleanup');
		wp_clear_scheduled_hook('corsivo_forms_cleanup_batch');
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
			wp_clear_scheduled_hook('corsivo_forms_daily_cleanup');
			wp_clear_scheduled_hook('corsivo_forms_cleanup_batch');
			restore_current_blog();
		}

		$offset += count($site_ids);
	} while (count($site_ids) === 100);
}

if (defined('CORSIVO_FORMS_FILE')) {
	register_deactivation_hook(CORSIVO_FORMS_FILE, 'corsivo_forms_clear_daily_cleanup_schedule');
}

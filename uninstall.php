<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}
require_once __DIR__ . '/includes/uploads.php';


function corsivo_forms_uninstall_site() {
	global $wpdb;

	wp_clear_scheduled_hook('corsivo_forms_daily_cleanup');
	wp_clear_scheduled_hook('corsivo_forms_cleanup_batch');

	$settings = (array) get_option('corsivo_forms_settings', []);
	$delete_data = in_array($settings['delete_data_on_uninstall'] ?? false, [true, 1, '1'], true);
	if (!$delete_data) {
		return;
	}

	$had_suppression = array_key_exists('corsivo_forms_suppress_activity_log', $GLOBALS);
	$was_suppressed = $GLOBALS['corsivo_forms_suppress_activity_log'] ?? false;
	$GLOBALS['corsivo_forms_suppress_activity_log'] = true;

	try {
		do {
			$submission_ids = array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC LIMIT 250",
						'corsivo_submission'
					)
				)
			);

			$deleted = 0;
			foreach ($submission_ids as $submission_id) {
				if (wp_delete_post($submission_id, true)) {
					$deleted++;
				}
			}
		} while ($submission_ids !== [] && $deleted > 0);
		$upload_cleanup = corsivo_forms_uninstall_upload_storage();
		if (is_wp_error($upload_cleanup)) {
			wp_die(esc_html($upload_cleanup->get_error_message()));
		}
		$forced_submission_ids = array_map('intval', $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'corsivo_submission'"
		));

		$wpdb->query(
			"DELETE comment_meta
			FROM {$wpdb->commentmeta} comment_meta
			INNER JOIN {$wpdb->comments} comments ON comments.comment_ID = comment_meta.comment_id
			INNER JOIN {$wpdb->posts} submissions ON submissions.ID = comments.comment_post_ID
			WHERE submissions.post_type = 'corsivo_submission'"
		);
		$wpdb->query(
			"DELETE comments
			FROM {$wpdb->comments} comments
			INNER JOIN {$wpdb->posts} submissions ON submissions.ID = comments.comment_post_ID
			WHERE submissions.post_type = 'corsivo_submission'"
		);
		$wpdb->query(
			"DELETE relationships
			FROM {$wpdb->term_relationships} relationships
			INNER JOIN {$wpdb->posts} submissions ON submissions.ID = relationships.object_id
			WHERE submissions.post_type = 'corsivo_submission'"
		);
		$wpdb->query(
			"DELETE submission_meta
			FROM {$wpdb->postmeta} submission_meta
			INNER JOIN {$wpdb->posts} submissions ON submissions.ID = submission_meta.post_id
			WHERE submissions.post_type = 'corsivo_submission'"
		);
		$wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'corsivo_submission'");
		foreach ($forced_submission_ids as $forced_submission_id) {
			clean_post_cache($forced_submission_id);
		}
	} finally {
		if ($had_suppression) {
			$GLOBALS['corsivo_forms_suppress_activity_log'] = $was_suppressed;
		} else {
			unset($GLOBALS['corsivo_forms_suppress_activity_log']);
		}
	}

	$meta_like = $wpdb->esc_like('_corsivo_forms_') . '%';
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta_like));

	$transient_like = $wpdb->esc_like('_transient_corsivo_forms_') . '%';
	$timeout_like = $wpdb->esc_like('_transient_timeout_corsivo_forms_') . '%';
	$lock_like = $wpdb->esc_like('corsivo_forms_rate_lock_') . '%';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$transient_like,
			$timeout_like,
			$lock_like
		)
	);

	$activity_table = $wpdb->prefix . 'corsivo_forms_activity';
	if (preg_match('/^[A-Za-z0-9_]+$/D', $activity_table)) {
		$wpdb->query("DROP TABLE `{$activity_table}`");
	}

	delete_option('corsivo_forms_activity_schema_intent');
	delete_option('corsivo_forms_activity_schema_verified');
	delete_option('corsivo_forms_activity_schema_failure');
	delete_option('corsivo_forms_settings');
}

if (!is_multisite()) {
	corsivo_forms_uninstall_site();
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
		corsivo_forms_uninstall_site();
		restore_current_blog();
	}

	$offset += count($site_ids);
} while (count($site_ids) === 100);

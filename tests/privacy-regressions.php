<?php

if (PHP_SAPI !== 'cli') {
	exit(1);
}

define('ABSPATH', dirname(__DIR__, 4) . '/');
function add_filter(...$arguments) {}
function add_action(...$arguments) {}
require dirname(__DIR__) . '/includes/privacy.php';

if (!class_exists('SQLite3')) {
	fwrite(STDERR, "The privacy query regressions require the SQLite3 PHP extension.\n");
	exit(1);
}

$database = new SQLite3(':memory:');
$database->enableExceptions(true);
$database->exec('CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT)');
$database->exec('CREATE TABLE postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT)');

$wpdb = new class($database) {
	public string $posts = 'posts';
	public string $postmeta = 'postmeta';

	public function __construct(private SQLite3 $database) {}

	public function prepare($query, ...$values) {
		return preg_replace_callback('/%[sd]/', static function ($match) use (&$values) {
			$value = array_shift($values);
			return $match[0] === '%d' ? (string) (int) $value : "'" . SQLite3::escapeString((string) $value) . "'";
		}, $query);
	}

	public function get_col($query) {
		$result = $this->database->query($query);
		$values = [];
		while ($row = $result->fetchArray(SQLITE3_NUM)) {
			$values[] = $row[0];
		}
		return $values;
	}
};

$fixtures = [
	[1, 'corsivo_submission', 'publish', 'person@example.test', '2026-01-01 00:00:00'],
	[2, 'corsivo_submission', 'trash', 'person@example.test', '2026-01-02 00:00:00'],
	[3, 'corsivo_submission', 'trash', 'person@example.test', '2026-10-21 00:00:00'],
	[4, 'corsivo_submission', 'draft', 'person@example.test', '2026-01-01 00:00:00'],
	[5, 'corsivo_submission', 'trash', 'other@example.test', '2026-01-01 00:00:00'],
	[6, 'post', 'trash', 'person@example.test', '2026-01-01 00:00:00'],
	[7, 'corsivo_submission', 'trash', 'person@example.test', ''],
];
foreach ($fixtures as [$id, $type, $status, $email, $submitted_at]) {
	$database->exec($wpdb->prepare('INSERT INTO posts VALUES (%d, %s, %s)', $id, $type, $status));
	$database->exec($wpdb->prepare('INSERT INTO postmeta VALUES (%d, %s, %s)', $id, '_corsivo_forms_email_index', $email));
	$database->exec($wpdb->prepare('INSERT INTO postmeta VALUES (%d, %s, %s)', $id, '_corsivo_forms_submitted_at_gmt', $submitted_at));
}

$checks = 0;
function privacy_check($actual, $expected, $message) {
	global $checks;
	if ($actual !== $expected) {
		throw new RuntimeException($message);
	}
	$checks++;
}

privacy_check(corsivo_forms_privacy_submission_ids('PERSON@example.test', 100), [1, 2, 3, 7], 'Export includes retained trash, filters the email and excludes incomplete drafts and other post types.');
privacy_check(corsivo_forms_privacy_submission_ids('person@example.test', 2, 2), [3, 7], 'Export pagination also counts submissions in the trash.');
privacy_check(corsivo_forms_privacy_submission_ids('missing@example.test', 100), [], 'Export does not disclose another email address\'s submissions.');
privacy_check(corsivo_forms_privacy_submission_ids_after('person@example.test', 100), [1, 2, 3, 4, 7], 'Erasure includes trash and incomplete drafts while isolating the email address and post type.');
privacy_check(corsivo_forms_privacy_submission_ids_after('person@example.test', 2, 2), [3, 4], 'Erasure cursor advances across trashed submissions without skipping retained drafts.');
privacy_check(corsivo_forms_expired_submission_ids(0, '2026-10-01 00:00:00'), [1, 2, 5], 'Retention selects old trash, preserves recent trash, and excludes drafts and missing canonical dates.');
privacy_check(corsivo_forms_expired_submission_ids(2, '2026-10-01 00:00:00'), [5], 'Retention cursor advances across trashed submissions.');

$database->close();
echo 'PASS - ' . $checks . ' Forms privacy query regressions' . PHP_EOL;

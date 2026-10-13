<?php

if (!defined('ABSPATH')) {
	exit;
}

add_filter('corsivo_mail_core_emails', 'corsivo_forms_register_mail_provider');
function corsivo_forms_register_mail_provider($providers) {
	if (function_exists('corsivo_forms_get_setting') && !corsivo_forms_get_setting('corsivo_mail_enabled')) {
		return $providers;
	}

	$provider = [
		'label'           => 'Forms — Notifica modulo inviato',
		'merge_tags'      => ['form_title', 'page_title', 'page_url', 'submitted_at', 'sender_name', 'sender_email', 'notification_subject', 'fields_html'],
		'default_title'   => '{{notification_subject}}',
		'default_content' => corsivo_forms_mail_paragraphs([
			'Hai ricevuto un nuovo messaggio dal modulo <strong>{{form_title}}</strong> nella pagina <a href="{{page_url}}">{{page_title}}</a>.',
			'{{fields_html}}',
			'Inviato il {{submitted_at}}.',
		]),
	];

	$providers['corsivo_forms_form'] = $provider;
	return $providers;
}

function corsivo_forms_mail_paragraphs(array $texts) {
	$out = '';
	foreach ($texts as $text) {
		$out .= "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	return trim($out);
}

function corsivo_forms_fields_html(array $rows) {
	$lines = [];
	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$value = ($row['type'] ?? '') === 'textarea'
			? nl2br(esc_html($row['display'] ?? ''))
			: esc_html($row['display'] ?? '');
		$lines[] = '<strong>' . esc_html($row['label'] ?? '') . ':</strong> ' . $value;
	}
	return implode('<br>', $lines);
}

function corsivo_forms_notification_context(array $submission, $subject) {
	$context = [
		'_html_keys'          => ['fields_html'],
		'form_title'          => (string) $submission['form_title'],
		'page_title'          => (string) $submission['page_title'],
		'page_url'            => esc_url_raw($submission['page_url']),
		'submitted_at'        => (string) $submission['submitted_at'],
		'sender_name'         => html_entity_decode(corsivo_forms_mail_merge_text($submission['sender_name']), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
		'sender_email'        => (string) $submission['sender_email'],
		'notification_subject' => corsivo_forms_mail_merge_subject($subject),
		'fields_html'         => corsivo_forms_fields_html($submission['rows']),
	];

	foreach ($submission['rows'] as $row) {
		if (!is_array($row)) {
			continue;
		}
		$field_key   = 'field_' . $row['name'];
		$field_value = (string) ($row['display'] ?? '');
		$context[$field_key] = html_entity_decode(corsivo_forms_mail_merge_text($field_value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$field_url = esc_url_raw($field_value, ['http', 'https', 'mailto', 'tel']);
		if ($field_url !== '') {
			$context[$field_key . '_url'] = $field_url;
		}
	}

	return $context;
}

function corsivo_forms_mail_merge_subject($subject) {
	$subject = html_entity_decode(sanitize_text_field((string) $subject), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$protocol_probe = preg_replace('/[\x00-\x20]+/u', '', $subject);
	if (preg_match('/^(?:javascript|data|vbscript):/i', (string) $protocol_probe)) {
		$subject = wp_kses_bad_protocol($subject, ['http', 'https', 'mailto', 'tel']);
	}
	$subject = preg_replace('/&(?:#(?:x[0-9a-f]+|[0-9]+)|quot|apos|lt|gt|equals|grave|newline|tab);?/i', '', $subject);
	return str_replace(['"', "'", '<', '>', '`', '='], '', (string) $subject);
}

function corsivo_forms_mail_merge_text($value) {
	$value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$protocol_probe = preg_replace('/[\x00-\x20]+/u', '', $value);
	if (preg_match('/^(?:javascript|data|vbscript):/i', (string) $protocol_probe)) {
		$value = wp_kses_bad_protocol($value, ['http', 'https', 'mailto', 'tel']);
	}
	return esc_html($value);
}

function corsivo_forms_notification_recipients(array $submission) {
	$allowed = preg_split('/[\s,;]+/', (string) corsivo_forms_get_setting('allowed_recipients', '')) ?: [];
	$allowed[] = (string) get_option('admin_email');
	$allowed = array_map('strtolower', array_map('sanitize_email', (array) apply_filters('corsivo_forms_allowed_recipients', $allowed)));
	$configured = trim((string) ($submission['recipients'] ?? ''));
	if ($configured === '') {
		$admin_email = sanitize_email((string) get_option('admin_email'));
		return is_email($admin_email)
			? [$admin_email]
			: new WP_Error('missing_recipient', 'Nessun destinatario email valido è configurato.');
	}

	$addresses = array_map('trim', explode(',', $configured));
	if (count($addresses) > 10) {
		return new WP_Error('too_many_recipients', 'Sono consentiti al massimo dieci destinatari.');
	}

	$recipients = [];
	foreach ($addresses as $address) {
		$address = sanitize_email(trim($address));
		if ($address === '' || !is_email($address)) {
			return new WP_Error('invalid_recipients', 'I destinatari configurati non sono validi.');
		}
		$key     = strtolower($address);
		if (!isset($recipients[$key])) {
			$recipients[$key] = $address;
		}
	}
	if (array_diff(array_keys($recipients), $allowed)) {
		return new WP_Error('recipient_not_allowed', 'Il destinatario deve essere autorizzato nelle impostazioni di Corsivo Forms.');
	}

	return $recipients
		? array_values($recipients)
		: new WP_Error('invalid_recipients', 'I destinatari configurati non sono validi.');
}

function corsivo_forms_select_notification_settings(array $settings, array $fields, array $values) {
	$fields_by_name = array_column($fields, null, 'name');
	$active_by_name = array_fill_keys(array_keys($fields_by_name), true);
	foreach ($settings['notification_rules'] ?? [] as $rule) {
		if (!corsivo_forms_conditions_match($rule['conditions'], $rule['condition_mode'], $fields_by_name, $values, $active_by_name)) {
			continue;
		}
		$settings['recipients'] = $rule['recipients'];
		if ($rule['subject'] !== '') {
			$settings['subject'] = $rule['subject'];
		}
		break;
	}
	return $settings;
}

function corsivo_forms_notification_subject(array $submission) {
	$replacements = [];
	foreach ($submission['rows'] as $row) {
		if (is_array($row) && isset($row['name'])) {
			$replacements['{' . $row['name'] . '}'] = (string) ($row['display'] ?? '');
		}
	}
	$subject = sanitize_text_field(strtr((string) $submission['subject'], $replacements));
	return corsivo_forms_limit_text($subject ?: 'Nuovo messaggio dal sito', 250);
}

function corsivo_forms_notification_headers(array $submission) {
	if (empty($submission['sender_email']) || !is_email($submission['sender_email'])) {
		return [];
	}

	$name   = str_replace(['"', '<', '>'], '', sanitize_text_field((string) $submission['sender_name']));
	$sender = $name !== '' ? $name . ' <' . $submission['sender_email'] . '>' : $submission['sender_email'];
	return ['Reply-To: ' . $sender];
}

function corsivo_forms_available_mail_provider() {
	if (!function_exists('corsivo_mail_deliver')) {
		return '';
	}

	if (!function_exists('corsivo_mail_core_email_status')) {
		return '';
	}

	try {
		$status = corsivo_mail_core_email_status('corsivo_forms_form');
	} catch (Throwable $error) {
		return '';
	}

	return !empty($status['available']) ? 'corsivo_forms_form' : '';
}

function corsivo_forms_send_notification(array $submission) {
	$recipients = corsivo_forms_notification_recipients($submission);
	if (is_wp_error($recipients)) {
		return [
			'status'     => 'failed',
			'total'      => 0,
			'accepted'   => 0,
			'failed'     => 0,
			'transports' => [],
			'error_code' => $recipients->get_error_code(),
		];
	}

	$subject       = corsivo_forms_notification_subject($submission);
	$context       = corsivo_forms_notification_context($submission, $subject);
	$headers       = corsivo_forms_notification_headers($submission);
	$provider_id   = function_exists('corsivo_forms_get_setting') && !corsivo_forms_get_setting('corsivo_mail_enabled')
		? ''
		: corsivo_forms_available_mail_provider();
	$fallback_body = '<p>Hai ricevuto un nuovo messaggio dal modulo <strong>' . esc_html($submission['form_title']) . '</strong>'
		. ($submission['page_url'] ? ' nella pagina <a href="' . esc_url($submission['page_url']) . '">' . esc_html($submission['page_title']) . '</a>' : '')
		. '.</p><p>' . $context['fields_html'] . '</p><p>Inviato il ' . esc_html($submission['submitted_at']) . '.</p>';

	$accepted  = 0;
	$failed    = 0;
	$transports = [];
	$error_code = '';

	foreach ($recipients as $recipient) {
		$sent                 = false;
		$transport            = 'wp_mail';
		$recipient_error_code = '';

		if ($provider_id !== '') {
			try {
				$sent = (bool) corsivo_mail_deliver($provider_id, $recipient, $context, $headers);
			} catch (Throwable $error) {
				$recipient_error_code = 'corsivo_mail_exception';
			}
			if ($sent) {
				$transport = 'corsivo_mail';
			}
		}

		if (!$sent) {
			try {
				$sent = wp_mail(
					$recipient,
					$subject,
					$fallback_body,
					array_merge(['Content-Type: text/html; charset=UTF-8'], $headers)
				);
			} catch (Throwable $error) {
				$sent                 = false;
				$recipient_error_code = 'wp_mail_exception';
			}
		}

		$transports[$transport] = ($transports[$transport] ?? 0) + 1;
		if ($sent) {
			$accepted++;
		} else {
			$failed++;
			if ($recipient_error_code !== '') {
				$error_code = $recipient_error_code;
			} elseif ($error_code === '') {
				$error_code = 'notification_rejected';
			}
		}
	}

	$status = $accepted === count($recipients) ? 'accepted' : ($accepted > 0 ? 'partial' : 'failed');
	return [
		'status'     => $status,
		'total'      => count($recipients),
		'accepted'   => $accepted,
		'failed'     => $failed,
		'transports' => $transports,
		'error_code' => $error_code,
	];
}

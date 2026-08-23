<?php

if (!defined('ABSPATH')) {
	exit;
}

function corsivo_forms_normalize_form_settings(array $settings) {
	$defaults = corsivo_forms_schema_defaults(corsivo_forms_form_schema());
	$settings = array_merge($defaults, $settings);

	return [
		'form_title'     => corsivo_forms_limit_text(sanitize_text_field((string) $settings['form_title']), 190),
		'recipients'     => corsivo_forms_limit_text(sanitize_text_field((string) $settings['recipients']), 1000),
		'subject'        => corsivo_forms_limit_text(sanitize_text_field((string) $settings['subject']), 250),
		'success_message' => corsivo_forms_limit_text(sanitize_textarea_field((string) $settings['success_message']), 1000),
		'store'          => corsivo_forms_sanitize_boolean_setting($settings['store']),
	];
}

function corsivo_forms_form_definition(array $form, $post_id = 0, array $render_source = []) {
	$page_id  = max(0, (int) $post_id);
	$page     = $page_id ? get_post($page_id) : null;
	$language = corsivo_forms_current_language($page_id);
	$field_result = corsivo_forms_collect_fields_result($form);
	$fields   = corsivo_forms_fields_with_policy_evidence($field_result['fields'], $language);
	$settings = corsivo_forms_normalize_form_settings((array) ($form['attrs']['values'] ?? []));

	$definition = [
		'version'    => 1,
		'issued_at'  => time(),
		'form_id'    => '',
		'settings'   => $settings,
		'fields'     => $fields,
		'has_submit' => corsivo_forms_form_has_submit($form),
		'schema_truncated' => !empty($field_result['truncated']),
		'too_many_fields'  => count($field_result['fields']) > 50,
		'page_id'    => $page_id,
		'page_title' => $page ? sanitize_text_field(get_the_title($page)) : '',
		'page_url'   => $page ? esc_url_raw(get_permalink($page)) : '',
		'language'   => $language,
		'is_public'  => corsivo_forms_is_public_render_context($page),
		'source_mode' => 'rendered',
	];
	$definition['form_id'] = corsivo_forms_definition_form_id($settings, $fields, $definition);

	$definition = corsivo_forms_finalize_form_definition($definition, $form, $page);
	$post_contains_form = $page && corsivo_forms_post_contains_form($page, $definition['form_id']);
	$template_chain = array_filter((array) ($render_source['chain'] ?? []), static fn($source) => in_array($source['kind'] ?? '', ['wp_template', 'wp_template_part'], true));
	if (!empty($render_source['post_content']) && $post_contains_form) {
		$definition['source_mode'] = 'post';
	} elseif ($post_contains_form && !$template_chain) {
		$definition['source_mode'] = 'post';
	} elseif ($template_chain) {
		$definition['source_mode'] = 'template';
		$definition['source_chain'] = corsivo_forms_normalize_source_chain((array) $render_source['chain']);
	} else {
		$definition['source_mode'] = 'rendered';
	}
	return $definition;
}

function corsivo_forms_finalize_form_definition(array $definition, array $form, $page) {
	$base_definition = $definition;
	$filtered = apply_filters('corsivo_forms_form_definition', $definition, $form, $page);
	if (!is_array($filtered)) {
		$filtered = $definition;
	}

	$filtered['version']          = 1;
	$filtered['issued_at']        = isset($filtered['issued_at']) ? (int) $filtered['issued_at'] : time();
	$filtered['settings']         = corsivo_forms_normalize_form_settings((array) ($filtered['settings'] ?? []));
	$filtered['language']         = sanitize_key((string) ($filtered['language'] ?? ''));
	$filtered['fields']           = corsivo_forms_fields_with_policy_evidence(corsivo_forms_normalize_definition_fields((array) ($filtered['fields'] ?? [])), $filtered['language']);
	$filtered['has_submit']       = !empty($base_definition['has_submit']);
	$filtered['schema_truncated'] = !empty($base_definition['schema_truncated']);
	$filtered['too_many_fields']  = !empty($base_definition['too_many_fields']);
	$filtered['page_id']          = max(0, (int) ($filtered['page_id'] ?? 0));
	$filtered['page_title']       = sanitize_text_field((string) ($filtered['page_title'] ?? ''));
	$filtered['page_url']         = esc_url_raw((string) ($filtered['page_url'] ?? ''));
	$filtered['is_public']        = !empty($filtered['is_public']);
	$filtered['form_id']          = corsivo_forms_definition_form_id($filtered['settings'], $filtered['fields'], $filtered);
	return $filtered;
}

function corsivo_forms_fields_with_policy_evidence(array $fields, $language = '') {
	$has_privacy = false;
	foreach ($fields as $field) {
		if (($field['type'] ?? '') === 'privacy') {
			$has_privacy = true;
			break;
		}
	}
	if (!$has_privacy) {
		return $fields;
	}

	$policy_url = corsivo_forms_privacy_policy_url($language);
	$policy_id  = $policy_url ? url_to_postid($policy_url) : 0;
	$policy     = $policy_id ? get_post($policy_id) : null;

	foreach ($fields as &$field) {
		if (($field['type'] ?? '') !== 'privacy') {
			continue;
		}
		$field['policy_url']            = $policy_url;
		$field['policy_id']             = $policy ? (int) $policy->ID : 0;
		$field['policy_updated_at_gmt'] = $policy ? (string) $policy->post_modified_gmt : '';
		$field['policy_hash']           = $policy ? hash('sha256', (string) $policy->post_content) : '';
	}
	unset($field);

	return $fields;
}

function corsivo_forms_definition_form_id(array $settings, array $fields, array $definition = []) {
	$identity = wp_json_encode([
		'settings'         => $settings,
		'fields'           => $fields,
		'has_submit'       => !empty($definition['has_submit']),
		'schema_truncated' => !empty($definition['schema_truncated']),
		'too_many_fields'  => !empty($definition['too_many_fields']),
		'is_public'        => !empty($definition['is_public']),
	]);
	return substr(hash('sha256', (string) $identity), 0, 32);
}

function corsivo_forms_form_configuration_error(array $definition) {
	$fields = (array) ($definition['fields'] ?? []);
	$file_fields = array_values(array_filter($fields, static fn($field) => ($field['type'] ?? '') === 'file'));
	if (empty($definition['is_public'])) {
		return new WP_Error('form_not_public', __('Questo modulo non è disponibile.', 'corsivo-forms'), ['status' => 403]);
	}
	if (!empty($definition['schema_truncated'])) {
		return new WP_Error('form_schema_too_complex', __('Il modulo non è disponibile: la struttura è troppo complessa.', 'corsivo-forms'), ['status' => 409]);
	}
	if (!$fields) {
		return new WP_Error('empty_form', __('Il modulo non è disponibile: aggiungi almeno un campo.', 'corsivo-forms'), ['status' => 409]);
	}
	if (!empty($definition['too_many_fields']) || count($fields) > 50) {
		return new WP_Error('too_many_fields', __('Il modulo non è disponibile: sono consentiti al massimo 50 campi.', 'corsivo-forms'), ['status' => 409]);
	}
	if (empty($definition['has_submit'])) {
		return new WP_Error('missing_submit', __('Il modulo non è disponibile: aggiungi un pulsante di invio.', 'corsivo-forms'), ['status' => 409]);
	}
	if ($file_fields && empty($definition['settings']['store'])) {
		return new WP_Error('file_storage_required', __('Il modulo non è disponibile: i file richiedono l’archiviazione delle richieste.', 'corsivo-forms'), ['status' => 409]);
	}
	if ($file_fields && !function_exists('corsivo_forms_upload_type_registry')) {
		return new WP_Error('upload_registry_unavailable', __('Il modulo non è disponibile: il servizio allegati non è configurato.', 'corsivo-forms'), ['status' => 503]);
	}
	if ($file_fields && !function_exists('corsivo_forms_ensure_upload_storage')) {
		return new WP_Error('upload_storage_unavailable', __('Il modulo non è disponibile: il servizio allegati non è disponibile.', 'corsivo-forms'), ['status' => 503]);
	}
	if ($file_fields) {
		$storage = corsivo_forms_ensure_upload_storage();
		if (is_wp_error($storage) || $storage !== true) {
			return new WP_Error('upload_storage_unavailable', __('Il modulo non è disponibile: il servizio allegati non è disponibile.', 'corsivo-forms'), ['status' => 503]);
		}
	}
	if (function_exists('corsivo_forms_notification_recipients')) {
		$recipients = corsivo_forms_notification_recipients(['recipients' => $definition['settings']['recipients'] ?? '']);
		if (is_wp_error($recipients)) {
			return new WP_Error('invalid_recipients', __('Il modulo non è disponibile: configura almeno un destinatario email valido.', 'corsivo-forms'), ['status' => 409]);
		}
	}
	foreach ($fields as $field) {
		if (($field['type'] ?? '') === 'file' && empty($field['values']['allowed_extensions'])) {
			return new WP_Error(
				'file_extensions_missing',
				__('Il modulo non è disponibile: configura almeno un formato per gli allegati.', 'corsivo-forms'),
				['status' => 409]
			);
		}
		if (($field['type'] ?? '') === 'select' && !empty($field['values']['required']) && !corsivo_forms_select_options((array) $field['values'])) {
			return new WP_Error('empty_required_select', __('Il modulo non è disponibile: una scelta obbligatoria non contiene opzioni.', 'corsivo-forms'), ['status' => 409]);
		}
		if (($field['type'] ?? '') === 'privacy' && empty($field['policy_url'])) {
			return new WP_Error(
				'privacy_policy_missing',
				__('Il modulo non è disponibile: pubblica e configura una informativa privacy.', 'corsivo-forms'),
				['status' => 409]
			);
		}
	}
	return null;
}

function corsivo_forms_post_contains_form(WP_Post $post, $form_id) {
	foreach (corsivo_forms_collect_forms($post->post_content) as $form) {
		$field_result = corsivo_forms_collect_fields_result($form);
		$language     = corsivo_forms_current_language($post->ID);
		$fields       = corsivo_forms_fields_with_policy_evidence($field_result['fields'], $language);
		$settings     = corsivo_forms_normalize_form_settings((array) ($form['attrs']['values'] ?? []));
		$candidate    = [
			'version'          => 1,
			'issued_at'        => time(),
			'form_id'          => '',
			'settings'         => $settings,
			'fields'           => $fields,
			'has_submit'       => corsivo_forms_form_has_submit($form),
			'schema_truncated' => !empty($field_result['truncated']),
			'too_many_fields'  => count($field_result['fields']) > 50,
			'page_id'          => (int) $post->ID,
			'page_title'       => sanitize_text_field(get_the_title($post)),
			'page_url'         => esc_url_raw(get_permalink($post)),
			'language'         => $language,
			'is_public'        => corsivo_forms_is_public_render_context($post),
			'source_mode'      => 'rendered',
		];
		$candidate['form_id'] = corsivo_forms_definition_form_id($candidate['settings'], $candidate['fields'], $candidate);
		$candidate = corsivo_forms_finalize_form_definition($candidate, $form, $post);
		if (hash_equals((string) $form_id, $candidate['form_id'])) {
			return true;
		}
	}
	return false;
}

function corsivo_forms_normalize_source_chain(array $chain) {
	$normalized = [];
	foreach (array_slice($chain, 0, 20) as $source) {
		if (!is_array($source)) {
			continue;
		}
		$kind = sanitize_key((string) ($source['kind'] ?? ''));
		$id   = $kind === 'wp_block' ? (string) absint($source['id'] ?? 0) : sanitize_text_field((string) ($source['id'] ?? ''));
		$hash = strtolower((string) ($source['hash'] ?? ''));
		if (!in_array($kind, ['wp_template', 'wp_template_part', 'wp_block'], true) || $id === '' || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
			continue;
		}
		$normalized[] = ['kind' => $kind, 'id' => corsivo_forms_limit_text($id, 191), 'hash' => $hash];
	}
	return $normalized;
}

function corsivo_forms_template_source_is_current(array $definition) {
	$chain = corsivo_forms_normalize_source_chain((array) ($definition['source_chain'] ?? []));
	if (!$chain) {
		return false;
	}

	$contents = [];
	foreach ($chain as $source) {
		$content = '';
		if ($source['kind'] === 'wp_block') {
			$post = get_post((int) $source['id']);
			if (!$post || $post->post_type !== 'wp_block' || $post->post_status !== 'publish' || $post->post_password !== '') {
				return false;
			}
			$content = (string) $post->post_content;
		} else {
			$template = get_block_template($source['id'], $source['kind']);
			if (!$template) {
				return false;
			}
			$content = (string) $template->content;
		}
		if (!hash_equals($source['hash'], hash('sha256', $content))) {
			return false;
		}
		$contents[] = $content;
	}

	foreach ($contents as $content) {
		foreach (corsivo_forms_collect_forms($content) as $form) {
			if (hash_equals((string) $definition['form_id'], corsivo_forms_contextual_form_id($form, $definition))) {
				return true;
			}
		}
	}
	return false;
}

function corsivo_forms_contextual_form_id(array $form, array $context) {
	$field_result = corsivo_forms_collect_fields_result($form);
	$fields       = corsivo_forms_fields_with_policy_evidence($field_result['fields'], $context['language'] ?? '');
	$settings     = corsivo_forms_normalize_form_settings((array) ($form['attrs']['values'] ?? []));
	$page         = !empty($context['page_id']) ? get_post((int) $context['page_id']) : null;
	$candidate    = [
		'version'          => 1,
		'issued_at'        => time(),
		'form_id'          => '',
		'settings'         => $settings,
		'fields'           => $fields,
		'has_submit'       => corsivo_forms_form_has_submit($form),
		'schema_truncated' => !empty($field_result['truncated']),
		'too_many_fields'  => count($field_result['fields']) > 50,
		'page_id'          => (int) ($context['page_id'] ?? 0),
		'page_title'       => (string) ($context['page_title'] ?? ''),
		'page_url'         => (string) ($context['page_url'] ?? ''),
		'language'         => (string) ($context['language'] ?? ''),
		'is_public'        => !empty($context['is_public']),
		'source_mode'      => 'template',
	];
	$candidate['form_id'] = corsivo_forms_definition_form_id($candidate['settings'], $candidate['fields'], $candidate);
	$candidate = corsivo_forms_finalize_form_definition($candidate, $form, $page);
	return (string) $candidate['form_id'];
}

function corsivo_forms_current_language($page_id = 0) {
	$details = $page_id ? apply_filters('wpml_post_language_details', null, (int) $page_id) : null;
	$language = is_array($details) ? (string) ($details['language_code'] ?? '') : '';
	if ($language === '') {
		$language = apply_filters('wpml_current_language', null);
	}
	if (!is_string($language) || $language === '') {
		$language = get_locale();
	}
	return sanitize_key((string) apply_filters('corsivo_forms_submission_language', $language, (int) $page_id));
}

function corsivo_forms_is_public_render_context($page = null) {
	if ($page instanceof WP_Post) {
		$public = !is_preview()
			&& $page->post_status === 'publish'
			&& is_post_publicly_viewable($page)
			&& !post_password_required($page);
		return (bool) apply_filters('corsivo_forms_is_public_render_context', $public, $page);
	}

	$public = !is_admin()
		&& !is_preview()
		&& !wp_doing_ajax()
		&& !(defined('REST_REQUEST') && REST_REQUEST);
	return (bool) apply_filters('corsivo_forms_is_public_render_context', $public, null);
}

function corsivo_forms_create_form_token(array $definition) {
	if (!function_exists('openssl_encrypt')) {
		return '';
	}

	$json = wp_json_encode($definition);
	if (!is_string($json) || strlen($json) > 32768) {
		return '';
	}

	try {
		$iv = random_bytes(12);
	} catch (Throwable $error) {
		return '';
	}

	$tag        = '';
	$ciphertext = openssl_encrypt(
		$json,
		'aes-256-gcm',
		corsivo_forms_form_token_key(),
		OPENSSL_RAW_DATA,
		$iv,
		$tag,
		corsivo_forms_form_token_context(),
		16
	);

	if (!is_string($ciphertext) || strlen($tag) !== 16) {
		return '';
	}

	return 'v1.' . corsivo_forms_base64url_encode($iv . $tag . $ciphertext);
}

function corsivo_forms_read_form_token($token) {
	$invalid_message = __('Il modulo non è valido: ricarica la pagina e riprova.', 'corsivo-forms');
	if (!is_string($token) || !str_starts_with($token, 'v1.') || !function_exists('openssl_decrypt')) {
		return new WP_Error('invalid_form_token', $invalid_message, ['status' => 409]);
	}

	$binary = corsivo_forms_base64url_decode(substr($token, 3));
	if (!is_string($binary) || strlen($binary) < 29 || strlen($binary) > 49152) {
		return new WP_Error('invalid_form_token', $invalid_message, ['status' => 409]);
	}

	$iv         = substr($binary, 0, 12);
	$tag        = substr($binary, 12, 16);
	$ciphertext = substr($binary, 28);
	$json       = openssl_decrypt(
		$ciphertext,
		'aes-256-gcm',
		corsivo_forms_form_token_key(),
		OPENSSL_RAW_DATA,
		$iv,
		$tag,
		corsivo_forms_form_token_context()
	);

	if (!is_string($json)) {
		return new WP_Error('invalid_form_token', $invalid_message, ['status' => 409]);
	}

	$definition = json_decode($json, true);
	if (!corsivo_forms_is_valid_form_definition($definition)) {
		return new WP_Error('invalid_form_token', $invalid_message, ['status' => 409]);
	}

	$source_mode = (string) ($definition['source_mode'] ?? '');
	if (!in_array($source_mode, ['post', 'template', 'rendered'], true)) {
		return new WP_Error('invalid_form_token', $invalid_message, ['status' => 409]);
	}
	$default_ttl = $source_mode === 'rendered' ? DAY_IN_SECONDS : 90 * DAY_IN_SECONDS;
	$ttl         = max(HOUR_IN_SECONDS, (int) apply_filters('corsivo_forms_form_token_ttl', $default_ttl, $definition));
	$issued_at = (int) $definition['issued_at'];
	if ($issued_at > time() + 300 || $issued_at < time() - $ttl) {
		return new WP_Error('expired_form_token', __('Il modulo è scaduto: ricarica la pagina e riprova.', 'corsivo-forms'), ['status' => 409]);
	}

	if (empty($definition['is_public'])) {
		return new WP_Error('form_not_public', __('Questo modulo non è disponibile.', 'corsivo-forms'), ['status' => 403]);
	}

	$page_id = (int) $definition['page_id'];
	$page    = null;
	if ($page_id) {
		$page = get_post($page_id);
		if (!$page || $page->post_status !== 'publish' || !is_post_publicly_viewable($page) || post_password_required($page)) {
			return new WP_Error('form_not_public', __('Questo modulo non è disponibile.', 'corsivo-forms'), ['status' => 403]);
		}
		if (!corsivo_forms_post_is_allowed($page)) {
			return new WP_Error('form_not_allowed', __('Questo modulo non è disponibile.', 'corsivo-forms'), ['status' => 403]);
		}
		$current_language = corsivo_forms_current_language($page->ID);
		if (sanitize_key((string) ($definition['language'] ?? '')) !== $current_language) {
			return new WP_Error('form_language_changed', __('La lingua del modulo è cambiata: ricarica la pagina e riprova.', 'corsivo-forms'), ['status' => 409]);
		}
		$definition['language'] = $current_language;
	}

	$definition['settings'] = corsivo_forms_normalize_form_settings($definition['settings']);
	$definition['fields']   = corsivo_forms_normalize_definition_fields($definition['fields']);
	$definition['source_mode'] = $source_mode;
	$definition['source_chain'] = corsivo_forms_normalize_source_chain((array) ($definition['source_chain'] ?? []));
	$configuration_error = corsivo_forms_form_configuration_error($definition);
	if (is_wp_error($configuration_error)) {
		return $configuration_error;
	}

	if ($source_mode === 'post' && (!$page || !corsivo_forms_post_contains_form($page, $definition['form_id']))) {
		return new WP_Error('form_changed', __('Il modulo è stato aggiornato: ricarica la pagina e riprova.', 'corsivo-forms'), ['status' => 409]);
	}
	if ($source_mode === 'template' && !corsivo_forms_template_source_is_current($definition)) {
		return new WP_Error('form_changed', __('Il modulo è stato aggiornato: ricarica la pagina e riprova.', 'corsivo-forms'), ['status' => 409]);
	}
	if ($source_mode === 'rendered' && !corsivo_forms_policy_evidence_is_current($definition['fields'], $definition['language'])) {
		return new WP_Error('privacy_policy_changed', __('L’informativa privacy è stata aggiornata: ricarica la pagina e riprova.', 'corsivo-forms'), ['status' => 409]);
	}
	if ($page) {
		$definition['page_title'] = sanitize_text_field(get_the_title($page));
		$definition['page_url']   = esc_url_raw(get_permalink($page));
	}

	return $definition;
}

function corsivo_forms_post_is_allowed(WP_Post $post) {
	return (bool) apply_filters('corsivo_forms_allow_post', true, $post);
}

function corsivo_forms_policy_evidence_is_current(array $fields, $language = '') {
	$privacy_fields = array_values(array_filter($fields, static fn($field) => is_array($field) && ($field['type'] ?? '') === 'privacy'));
	if (!$privacy_fields) {
		return true;
	}

	$policy_url = corsivo_forms_privacy_policy_url($language);
	$policy_id  = $policy_url ? url_to_postid($policy_url) : 0;
	$policy     = $policy_id ? get_post($policy_id) : null;
	if (!$policy || $policy->post_status !== 'publish' || !is_post_publicly_viewable($policy)) {
		return false;
	}
	$policy_hash = hash('sha256', (string) $policy->post_content);
	foreach ($privacy_fields as $field) {
		if (
			(string) ($field['policy_url'] ?? '') !== $policy_url
			|| (int) ($field['policy_id'] ?? 0) !== (int) $policy->ID
			|| (string) ($field['policy_updated_at_gmt'] ?? '') !== (string) $policy->post_modified_gmt
			|| (string) ($field['policy_hash'] ?? '') !== $policy_hash
		) {
			return false;
		}
	}
	return true;
}

function corsivo_forms_is_valid_form_definition($definition) {
	if (!is_array($definition) || (int) ($definition['version'] ?? 0) !== 1) {
		return false;
	}

	if (!isset($definition['issued_at'], $definition['form_id'], $definition['settings'], $definition['fields'])) {
		return false;
	}

	if (!is_array($definition['settings']) || !is_array($definition['fields']) || count($definition['fields']) > 50) {
		return false;
	}

	return (bool) preg_match('/^[a-f0-9]{32}$/', (string) $definition['form_id']);
}

function corsivo_forms_normalize_definition_fields(array $fields) {
	$normalized = [];
	$used       = [];
	$types      = corsivo_forms_field_types();

	foreach (array_slice($fields, 0, 50) as $field) {
		if (!is_array($field)) {
			continue;
		}

		$type = sanitize_key((string) ($field['type'] ?? ''));
		if (!isset($types[$type])) {
			continue;
		}

		$values = corsivo_forms_normalize_field_values($type, (array) ($field['values'] ?? []));
		$name   = corsivo_forms_field_name($values, $type, $used);
		if (isset($field['name']) && sanitize_key((string) $field['name']) !== $name) {
			continue;
		}

		$normalized_field = [
			'type'   => $type,
			'name'   => $name,
			'values' => $values,
		];

		if ($type === 'privacy') {
			$normalized_field['policy_url']            = esc_url_raw((string) ($field['policy_url'] ?? ''));
			$normalized_field['policy_id']             = max(0, (int) ($field['policy_id'] ?? 0));
			$normalized_field['policy_updated_at_gmt'] = sanitize_text_field((string) ($field['policy_updated_at_gmt'] ?? ''));
			$normalized_field['policy_hash']           = preg_match('/^[a-f0-9]{64}$/', (string) ($field['policy_hash'] ?? ''))
				? (string) $field['policy_hash']
				: '';
		}

		$normalized[] = $normalized_field;
	}

	return $normalized;
}

function corsivo_forms_form_token_key() {
	return hash('sha256', wp_salt('auth') . '|' . corsivo_forms_form_token_context(), true);
}

function corsivo_forms_form_token_context() {
	return 'corsivo-forms:v1|network:' . get_current_network_id() . '|blog:' . get_current_blog_id();
}

function corsivo_forms_base64url_encode($value) {
	return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
}

function corsivo_forms_base64url_decode($value) {
	if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
		return false;
	}

	$padding = strlen($value) % 4;
	if ($padding) {
		$value .= str_repeat('=', 4 - $padding);
	}

	return base64_decode(strtr($value, '-_', '+/'), true);
}

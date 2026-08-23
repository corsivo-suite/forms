<?php

if (!defined('ABSPATH')) {
	exit;
}

function corsivo_forms_field_types() {
	return [
		'text'     => ['title' => 'Campo testo', 'icon' => 'editor-textcolor', 'keywords' => ['testo', 'input', 'nome']],
		'email'    => ['title' => 'Campo email', 'icon' => 'email-alt', 'keywords' => ['email', 'posta', 'indirizzo']],
		'tel'      => ['title' => 'Campo telefono', 'icon' => 'phone', 'keywords' => ['telefono', 'cellulare']],
		'textarea' => ['title' => 'Area di testo', 'icon' => 'text', 'keywords' => ['messaggio', 'testo lungo']],
		'select'   => ['title' => 'Campo a scelta', 'icon' => 'list-view', 'keywords' => ['select', 'menu', 'opzioni']],
		'file'     => ['title' => 'Caricamento file', 'icon' => 'media-document', 'keywords' => ['file', 'allegato', 'upload']],
		'checkbox' => ['title' => 'Casella di spunta', 'icon' => 'yes-alt', 'keywords' => ['checkbox', 'spunta', 'consenso']],
		'privacy'  => ['title' => 'Consenso privacy', 'icon' => 'shield', 'keywords' => ['privacy', 'gdpr', 'informativa']],
	];
}

function corsivo_forms_field_schema($type) {
	if ($type === 'privacy') {
		return [
			['scope' => 'content', 'fields' => [
				'label' => [
					'type'    => 'textarea',
					'label'   => 'Testo del consenso',
					'default' => 'Ho letto l\'informativa sulla privacy e acconsento al trattamento dei miei dati per rispondere alla richiesta.',
				],
			]],
			['scope' => 'inspector', 'panel' => 'Campo', 'fields' => [
				'link_label' => [
					'type'        => 'text',
					'label'       => 'Testo del link all\'informativa',
					'default'     => 'Leggi l\'informativa',
					'description' => 'Il link punta alla privacy policy pubblicata da Privacy, o a quella impostata in WordPress.',
				],
			]],
		];
	}

	$label_defaults = [
		'text'     => 'Nome',
		'email'    => 'Email',
		'tel'      => 'Telefono',
		'textarea' => 'Messaggio',
		'select'   => 'Motivo',
		'file'     => 'Allegato',
		'checkbox' => 'Casella di spunta',
	];

	$content = [
		'label' => ['type' => 'text', 'label' => 'Etichetta', 'default' => $label_defaults[$type] ?? 'Campo'],
	];
	$inspector = [
		'required' => ['type' => 'checkbox', 'label' => 'Obbligatorio', 'default' => false],
	];

	if (in_array($type, ['text', 'email', 'tel', 'textarea'], true)) {
		$inspector['placeholder'] = ['type' => 'text', 'label' => 'Placeholder', 'default' => ''];
	}

	if ($type === 'select') {
		$content['options'] = [
			'type'       => 'repeater',
			'label'      => 'Opzioni',
			'previewKey' => 'option',
			'default'    => [],
			'fields'     => [
				'option' => ['type' => 'text', 'label' => 'Opzione'],
			],
		];
		$inspector['placeholder'] = ['type' => 'text', 'label' => 'Voce iniziale vuota', 'default' => 'Scegli…'];
	}

	if ($type === 'file') {
		$extension_options = [];
		if (function_exists('corsivo_forms_upload_type_registry')) {
			foreach (corsivo_forms_upload_type_registry() as $extension => $mime_types) {
				$extension = strtolower(sanitize_key((string) $extension));
				if ($extension === '' || !is_array($mime_types) || !$mime_types) {
					continue;
				}
				$extension_options[] = [
					'value' => $extension,
					'label' => strtoupper($extension),
				];
			}
		}
		$inspector['multiple'] = [
			'type'    => 'checkbox',
			'label'   => 'Consenti più file',
			'default' => false,
		];
		$inspector['allowed_extensions'] = [
			'type'     => 'multi-select',
			'label'    => 'Formati consentiti',
			'options'  => $extension_options,
			'default'  => ['pdf', 'jpg', 'jpeg', 'png'],
			'sortable' => false,
		];
		$inspector['max_files'] = [
			'type'    => 'range',
			'label'   => 'Numero massimo di file',
			'min'     => 1,
			'max'     => 10,
			'default' => 1,
			'show'    => 'multiple',
		];
		$inspector['max_file_size_mb'] = [
			'type'    => 'range',
			'label'   => 'Dimensione massima per file (MB)',
			'min'     => 1,
			'max'     => 20,
			'default' => 5,
		];
	}

	return [
		['scope' => 'content', 'fields' => $content],
		['scope' => 'inspector', 'panel' => 'Campo', 'fields' => $inspector],
		['scope' => 'inspector-advanced', 'fields' => [
			'field_key' => [
				'type'        => 'text',
				'label'       => 'Chiave campo',
				'default'     => '',
				'description' => 'Identifica il campo nelle notifiche ({{field_chiave}}, oppure {{field_chiave_url}} nei link) e nell\'oggetto ({chiave}).'
					. ($type === 'text' ? ' Usa sender_name per il nome mittente del Reply-To.' : '')
					. ' Se vuota, deriva dall\'etichetta.',
			],
		]],
	];
}

function corsivo_forms_schema_defaults(array $schema) {
	$defaults = [];
	foreach ($schema as $section) {
		foreach ($section['fields'] as $key => $field) {
			if (($field['type'] ?? '') === 'innerblocks') {
				continue;
			}
			$defaults[$key] = $field['default'] ?? '';
		}
	}
	return $defaults;
}

function corsivo_forms_field_defaults($type) {
	return corsivo_forms_schema_defaults(corsivo_forms_field_schema($type));
}

function corsivo_forms_limit_text($value, $length) {
	$value = (string) $value;
	return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function corsivo_forms_text_length($value) {
	$value = (string) $value;
	return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function corsivo_forms_normalize_field_values($type, array $values) {
	$defaults = corsivo_forms_field_defaults($type);
	$values   = array_merge($defaults, $values);
	$label    = $type === 'privacy'
		? sanitize_textarea_field((string) ($values['label'] ?? ''))
		: sanitize_text_field((string) ($values['label'] ?? ''));

	if (trim($label) === '') {
		$label = (string) ($defaults['label'] ?? $type);
	}

	$normalized = [
		'label'     => corsivo_forms_limit_text($label, $type === 'privacy' ? 1000 : 250),
		'field_key' => corsivo_forms_limit_text(sanitize_key((string) ($values['field_key'] ?? '')), 40),
	];

	if ($type !== 'privacy') {
		$normalized['required'] = corsivo_forms_sanitize_boolean_setting($values['required']);
	}

	if (in_array($type, ['text', 'email', 'tel', 'textarea', 'select'], true)) {
		$normalized['placeholder'] = corsivo_forms_limit_text(sanitize_text_field((string) ($values['placeholder'] ?? '')), 250);
	}

	if ($type === 'select') {
		$options = [];
		foreach (array_slice((array) ($values['options'] ?? []), 0, 100) as $row) {
			$option = is_array($row) ? trim(sanitize_text_field((string) ($row['option'] ?? ''))) : '';
			$option = corsivo_forms_limit_text($option, 250);
			if ($option !== '' && !in_array($option, $options, true)) {
				$options[] = $option;
			}
		}
		$normalized['options'] = array_map(static fn($option) => ['option' => $option], $options);
	}

	if ($type === 'file') {
		$registry = function_exists('corsivo_forms_upload_type_registry')
			? corsivo_forms_upload_type_registry()
			: [];
		$allowed_extensions = [];
		foreach ((array) ($values['allowed_extensions'] ?? ['pdf', 'jpg', 'jpeg', 'png']) as $extension) {
			if (is_array($extension)) {
				$extension = $extension['value'] ?? '';
			}
			$extension = strtolower(sanitize_key((string) $extension));
			if ($extension !== '' && isset($registry[$extension]) && !in_array($extension, $allowed_extensions, true)) {
				$allowed_extensions[] = $extension;
			}
		}
		$multiple = corsivo_forms_sanitize_boolean_setting($values['multiple'] ?? false);
		$normalized['multiple']           = $multiple;
		$normalized['allowed_extensions'] = $allowed_extensions;
		$normalized['max_files']          = $multiple ? min(10, max(1, (int) ($values['max_files'] ?? 1))) : 1;
		$normalized['max_file_size_mb']   = min(20, max(1, (int) ($values['max_file_size_mb'] ?? 5)));
	}

	if ($type === 'privacy') {
		$normalized['link_label'] = corsivo_forms_limit_text(sanitize_text_field((string) ($values['link_label'] ?? '')), 250);
	}

	return $normalized;
}

function corsivo_forms_field_name(array $values, $type, array &$used) {
	$base = trim((string) ($values['field_key'] ?? ''));
	if ($base === '' && $type !== 'privacy') {
		$base = sanitize_title((string) ($values['label'] ?? ''));
	}
	$base = str_replace('-', '_', sanitize_key(corsivo_forms_limit_text($base, 40)));
	$base = trim($base, '_') ?: $type;

	if (in_array($base, ['token', 'request_id', 'form_id', 'corsivo_forms_ts', 'corsivo_forms_hp'], true)) {
		$base = 'field_' . $base;
	}

	$name   = $base;
	$suffix = 2;
	while (isset($used[$name])) {
		$name = $base . '_' . $suffix++;
	}
	$used[$name] = true;

	return $name;
}

function corsivo_forms_select_options(array $values) {
	$options = [];
	foreach ((array) ($values['options'] ?? []) as $row) {
		$option = trim((string) ($row['option'] ?? ''));
		if ($option !== '') {
			$options[] = $option;
		}
	}
	return $options;
}

function corsivo_forms_privacy_policy_url($language = '') {
	$language = sanitize_key((string) $language);
	if ($language === '') {
		$current_language = apply_filters('wpml_current_language', null);
		$language = is_string($current_language) ? sanitize_key($current_language) : '';
	}
	$integration_enabled = !function_exists('corsivo_forms_get_setting') || corsivo_forms_get_setting('corsivo_privacy_enabled');
	if ($integration_enabled && function_exists('corsivo_privacy_get_pages_state')) {
		$privacy = corsivo_privacy_get_pages_state()['privacy'] ?? null;
		$page_id = !empty($privacy['id']) ? (int) $privacy['id'] : 0;
		if ($page_id && $language !== '') {
			$page_id = (int) apply_filters('wpml_object_id', $page_id, 'page', true, $language);
		}
		$page = $page_id ? get_post($page_id) : null;
		if ($page && $page->post_status === 'publish' && is_post_publicly_viewable($page)) {
			return esc_url_raw(get_permalink($page));
		}
	}

	$page_id = (int) get_option('wp_page_for_privacy_policy');
	if ($page_id && $language !== '') {
		$page_id = (int) apply_filters('wpml_object_id', $page_id, 'page', true, $language);
	}
	$page = $page_id ? get_post($page_id) : null;
	return $page && $page->post_status === 'publish' && is_post_publicly_viewable($page)
		? esc_url_raw(get_permalink($page))
		: '';
}

function corsivo_forms_render_field_input($type, $name, array $values) {
	$label       = trim((string) ($values['label'] ?? ''));
	$required    = $type === 'privacy' || !empty($values['required']);
	$placeholder = trim((string) ($values['placeholder'] ?? ''));
	$input_id    = 'corsivo-form-' . $name . '-' . wp_unique_id();

	$required_attr = $required ? ' required aria-required="true"' : '';
	$required_mark = $required ? '<span class="corsivo-form-field__required" aria-hidden="true"> *</span>' : '';

	if ($type === 'checkbox') {
		return '<div class="corsivo-form-field corsivo-form-field--checkbox" data-corsivo-form-name="' . esc_attr($name) . '">'
			. '<label class="corsivo-form-field__check">'
			. '<input id="' . esc_attr($input_id) . '" type="checkbox" name="' . esc_attr($name) . '" value="1"' . $required_attr . '>'
			. '<span>' . esc_html($label) . $required_mark . '</span>'
			. '</label>'
			. '</div>';
	}

	if ($type === 'privacy') {
		$policy_url    = corsivo_forms_privacy_policy_url();
		$link_label    = trim((string) ($values['link_label'] ?? '')) ?: __('Informativa privacy', 'corsivo-forms');
		$description_id = $input_id . '-policy';
		$described_by  = $policy_url && $link_label !== '' ? ' aria-describedby="' . esc_attr($description_id) . '"' : '';
		$link           = $policy_url && $link_label !== ''
			? '<a class="corsivo-form-field__policy" id="' . esc_attr($description_id) . '" href="' . esc_url($policy_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link_label) . '</a>'
			: '';

		return '<div class="corsivo-form-field corsivo-form-field--privacy" data-corsivo-form-name="' . esc_attr($name) . '">'
			. '<label class="corsivo-form-field__check" for="' . esc_attr($input_id) . '">'
			. '<input id="' . esc_attr($input_id) . '" type="checkbox" name="' . esc_attr($name) . '" value="1"' . $described_by . $required_attr . '>'
			. '<span>' . esc_html($label) . $required_mark . '</span>'
			. '</label>'
			. $link
			. '</div>';
	}

	$label_html = '<label class="corsivo-form-field__label" for="' . esc_attr($input_id) . '">' . esc_html($label) . $required_mark . '</label>';

	if ($type === 'file') {
		$extensions     = (array) ($values['allowed_extensions'] ?? []);
		$max_files      = (int) ($values['max_files'] ?? 1);
		$max_size       = (int) ($values['max_file_size_mb'] ?? 1);
		$multiple       = !empty($values['multiple']);
		$description_id = $input_id . '-limits';
		$accept         = implode(',', array_map(static fn($extension) => '.' . $extension, $extensions));
		$limit_text     = $multiple
			? sprintf(__('Massimo %1$d file da %2$d MB ciascuno.', 'corsivo-forms'), $max_files, $max_size)
			: sprintf(__('Un file, massimo %d MB.', 'corsivo-forms'), $max_size);
		$description    = sprintf(
			__('Formati consentiti: %1$s. %2$s', 'corsivo-forms'),
			implode(', ', array_map('strtoupper', $extensions)),
			$limit_text
		);
		$control = '<input class="corsivo-form-field__input" id="' . esc_attr($input_id) . '" type="file" name="' . esc_attr($name) . ($multiple ? '[]' : '') . '"'
			. ($accept !== '' ? ' accept="' . esc_attr($accept) . '"' : '')
			. ($multiple ? ' multiple' : '')
			. ' aria-describedby="' . esc_attr($description_id) . '"'
			. $required_attr . '>'
			. '<p class="corsivo-form-field__description" id="' . esc_attr($description_id) . '">' . esc_html($description) . '</p>';
	} elseif ($type === 'textarea') {
		$control = '<textarea class="corsivo-form-field__input" id="' . esc_attr($input_id) . '" name="' . esc_attr($name) . '" rows="6" maxlength="5000"'
			. ($placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '')
			. $required_attr . '></textarea>';
	} elseif ($type === 'select') {
		$choices = '<option value="">' . esc_html($placeholder !== '' ? $placeholder : '—') . '</option>';
		foreach (corsivo_forms_select_options($values) as $option) {
			$choices .= '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
		}
		$control = '<select class="corsivo-form-field__input" id="' . esc_attr($input_id) . '" name="' . esc_attr($name) . '"' . $required_attr . '>' . $choices . '</select>';
	} else {
		$autocomplete = ['email' => 'email', 'tel' => 'tel'][$type] ?? '';
		$control = '<input class="corsivo-form-field__input" id="' . esc_attr($input_id) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" maxlength="500"'
			. ($placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '')
			. ($autocomplete !== '' ? ' autocomplete="' . $autocomplete . '"' : '')
			. $required_attr . '>';
	}

	return '<div class="corsivo-form-field corsivo-form-field--' . esc_attr($type) . '" data-corsivo-form-name="' . esc_attr($name) . '">' . $label_html . $control . '</div>';
}

function corsivo_forms_validate_field(array $field, $raw) {
	$type     = $field['type'];
	$values   = $field['values'];
	$required = $type === 'privacy' || !empty($values['required']);

	if ($type === 'file') {
		return [
			'value' => [],
			'error' => $required ? __('Questo campo è obbligatorio.', 'corsivo-forms') : null,
		];
	}

	if ($type === 'checkbox' || $type === 'privacy') {
		$accepted = is_scalar($raw) && in_array($raw, [1, '1', true, 'on'], true);
		if ($required && !$accepted) {
			$message = $type === 'privacy'
				? __('Devi accettare l\'informativa sulla privacy.', 'corsivo-forms')
				: __('Questo campo è obbligatorio.', 'corsivo-forms');
			return ['value' => false, 'error' => $message];
		}
		return ['value' => $accepted, 'error' => null];
	}

	$value = is_scalar($raw) ? trim((string) $raw) : '';
	$value = $type === 'textarea' ? sanitize_textarea_field($value) : sanitize_text_field($value);

	if ($value === '') {
		return $required
			? ['value' => '', 'error' => __('Questo campo è obbligatorio.', 'corsivo-forms')]
			: ['value' => '', 'error' => null];
	}

	if ($type === 'email' && !is_email($value)) {
		return ['value' => '', 'error' => __('Inserisci un indirizzo email valido.', 'corsivo-forms')];
	}

	if ($type === 'select' && !in_array($value, corsivo_forms_select_options($values), true)) {
		return ['value' => '', 'error' => __('Scegli una delle opzioni disponibili.', 'corsivo-forms')];
	}

	$max_length = $type === 'textarea' ? 5000 : 500;
	if (corsivo_forms_text_length($value) > $max_length) {
		return ['value' => '', 'error' => sprintf(__('Massimo %d caratteri.', 'corsivo-forms'), $max_length)];
	}

	return ['value' => $value, 'error' => null];
}

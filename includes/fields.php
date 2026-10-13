<?php

if (!defined('ABSPATH')) {
	exit;
}

function corsivo_forms_field_types() {
	return [
		'text'     => ['title' => 'Campo testo', 'icon' => 'editor-textcolor', 'keywords' => ['testo', 'input', 'nome']],
		'email'    => ['title' => 'Campo email', 'icon' => 'email-alt', 'keywords' => ['email', 'posta', 'indirizzo']],
		'tel'      => ['title' => 'Campo telefono', 'icon' => 'phone', 'keywords' => ['telefono', 'cellulare']],
		'number'   => ['title' => 'Campo numero', 'icon' => 'editor-ol', 'keywords' => ['numero', 'quantità', 'importo']],
		'date'     => ['title' => 'Campo data', 'icon' => 'calendar-alt', 'keywords' => ['data', 'giorno', 'calendario']],
		'textarea' => ['title' => 'Area di testo', 'icon' => 'text', 'keywords' => ['messaggio', 'testo lungo']],
		'select'   => ['title' => 'Campo a scelta', 'icon' => 'list-view', 'keywords' => ['select', 'menu', 'opzioni']],
		'radio'    => ['title' => 'Scelta singola', 'icon' => 'marker', 'keywords' => ['radio', 'scelta', 'opzioni']],
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
		'number'   => 'Quantità',
		'date'     => 'Data',
		'textarea' => 'Messaggio',
		'select'   => 'Motivo',
		'radio'    => 'Scelta',
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

	if (in_array($type, ['select', 'radio'], true)) {
		$content['options'] = [
			'type'       => 'repeater',
			'label'      => 'Opzioni',
			'previewKey' => 'option',
			'default'    => [],
			'fields'     => [
				'option' => ['type' => 'text', 'label' => 'Opzione'],
			],
		];
		if ($type === 'select') {
			$inspector['placeholder'] = ['type' => 'text', 'label' => 'Voce iniziale vuota', 'default' => 'Scegli…'];
		}
	}

	if (in_array($type, ['number', 'date'], true)) {
		$date_description = $type === 'date' ? 'Formato AAAA-MM-GG. Lascia vuoto per non impostare un limite.' : 'Lascia vuoto per non impostare un limite.';
		$inspector['min'] = ['type' => 'text', 'label' => $type === 'date' ? 'Data minima' : 'Valore minimo', 'default' => '', 'description' => $date_description];
		$inspector['max'] = ['type' => 'text', 'label' => $type === 'date' ? 'Data massima' : 'Valore massimo', 'default' => '', 'description' => $date_description];
		if ($type === 'number') {
			$inspector['step'] = ['type' => 'text', 'label' => 'Incremento', 'default' => '', 'description' => '1 per interi, ad esempio 0.5 per decimali, any per qualunque decimale. Se vuoto, 1.'];
		}
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
		['scope' => 'inspector', 'panel' => 'Visibilità', 'fields' => corsivo_forms_condition_schema()],
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

function corsivo_forms_condition_schema($notification = false) {
	return [
		'condition_mode' => ['type' => 'select', 'label' => $notification ? 'Applica quando' : 'Mostra quando', 'default' => 'all', 'options' => [
			['value' => 'all', 'label' => 'Tutte le condizioni sono vere'],
			['value' => 'any', 'label' => 'Almeno una condizione è vera'],
		]],
		'conditions' => [
			'type' => 'repeater', 'label' => 'Condizioni', 'previewKey' => 'field', 'default' => [], 'max' => 10,
			'description' => ($notification ? 'Aggiungi almeno una condizione. ' : 'Senza condizioni il campo resta visibile. ') . 'Usa la chiave esatta del campo sorgente: impostala nel pannello Avanzate. File e privacy non possono essere sorgenti.',
			'fields' => [
				'field' => ['type' => 'text', 'label' => 'Chiave del campo sorgente', 'default' => ''],
				'operator' => ['type' => 'select', 'label' => 'Confronto', 'default' => 'equal', 'options' => [
					['value' => 'equal', 'label' => 'È uguale a'], ['value' => 'not_equal', 'label' => 'È diverso da'],
					['value' => 'contains', 'label' => 'Contiene'], ['value' => 'not_contains', 'label' => 'Non contiene'],
					['value' => 'is_empty', 'label' => 'È vuoto'], ['value' => 'is_not_empty', 'label' => 'Non è vuoto'],
					['value' => 'greater', 'label' => 'È maggiore di (numero/data)'], ['value' => 'greater_or_equal', 'label' => 'È maggiore o uguale (numero/data)'],
					['value' => 'less', 'label' => 'È minore di (numero/data)'], ['value' => 'less_or_equal', 'label' => 'È minore o uguale (numero/data)'],
				]],
				'value' => ['type' => 'text', 'label' => 'Valore', 'default' => '', 'description' => 'Per le scelte usa l’etichetta esatta; per le spunte 1 o 0; per le date AAAA-MM-GG. Non serve per il confronto vuoto.'],
			],
		],
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

	if (in_array($type, ['select', 'radio'], true)) {
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

	if (in_array($type, ['number', 'date'], true)) {
		foreach ($type === 'number' ? ['min', 'max', 'step'] : ['min', 'max'] as $bound) {
			$value = $values[$bound] ?? '';
			$value = is_scalar($value) && !is_bool($value) ? trim((string) $value) : '__invalid__';
			if ($value !== '') {
				$normalized[$bound] = corsivo_forms_limit_text($value, 100);
			}
		}
	}

	$has_conditions = array_key_exists('conditions', $values) && $values['conditions'] !== [];
	if ($type !== 'privacy' && ($has_conditions || array_key_exists('_conditions_error', $values))) {
		$conditions = corsivo_forms_normalize_conditions(array_key_exists('conditions', $values) ? $values['conditions'] : [], array_key_exists('condition_mode', $values) ? $values['condition_mode'] : 'all');
		if (array_key_exists('_conditions_error', $values) || is_wp_error($conditions)) {
			$normalized['_conditions_error'] = 'invalid_conditions';
		} elseif ($conditions['conditions']) {
			$normalized['condition_mode'] = $conditions['condition_mode'];
			$normalized['conditions'] = $conditions['conditions'];
		}
	}
	if ($type === 'privacy' && ($has_conditions || array_key_exists('_conditions_error', $values))) {
		$normalized['_conditions_error'] = 'conditional_privacy';
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

	if ($type === 'radio') {
		$options = '';
		foreach (corsivo_forms_select_options($values) as $index => $option) {
			$options .= '<label class="corsivo-form-field__check" for="' . esc_attr($input_id . '-' . $index) . '">'
				. '<input id="' . esc_attr($input_id . '-' . $index) . '" type="radio" name="' . esc_attr($name) . '" value="' . htmlspecialchars($option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true) . '"' . $required_attr . '>'
				. '<span>' . esc_html($option) . '</span></label>';
		}
		return '<fieldset class="corsivo-form-field corsivo-form-field--radio" data-corsivo-form-name="' . esc_attr($name) . '">'
			. '<legend class="corsivo-form-field__label">' . esc_html($label) . $required_mark . '</legend>'
			. '<div class="corsivo-form-field__choices">' . $options . '</div></fieldset>';
	}

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
			$choices .= '<option value="' . htmlspecialchars($option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true) . '">' . esc_html($option) . '</option>';
		}
		$control = '<select class="corsivo-form-field__input" id="' . esc_attr($input_id) . '" name="' . esc_attr($name) . '"' . $required_attr . '>' . $choices . '</select>';
	} elseif (in_array($type, ['number', 'date'], true)) {
		$limits = '';
		foreach ($type === 'number' ? ['min', 'max', 'step'] : ['min', 'max'] as $bound) {
			if (isset($values[$bound])) {
				$limits .= ' ' . $bound . '="' . esc_attr($values[$bound]) . '"';
			}
		}
		$control = '<input class="corsivo-form-field__input" id="' . esc_attr($input_id) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '"' . $limits . $required_attr . '>';
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

	if (in_array($type, ['number', 'date', 'radio'], true) && $raw !== null && (!is_scalar($raw) || is_bool($raw))) {
		return ['value' => '', 'error' => __('Inserisci un valore valido.', 'corsivo-forms')];
	}

	$value = is_scalar($raw) ? trim((string) $raw) : '';
	if (!in_array($type, ['number', 'date'], true)) {
		$value = $type === 'textarea' ? sanitize_textarea_field($value) : sanitize_text_field($value);
	}

	if ($value === '') {
		return $required
			? ['value' => '', 'error' => __('Questo campo è obbligatorio.', 'corsivo-forms')]
			: ['value' => '', 'error' => null];
	}

	if ($type === 'email' && !is_email($value)) {
		return ['value' => '', 'error' => __('Inserisci un indirizzo email valido.', 'corsivo-forms')];
	}

	if (in_array($type, ['select', 'radio'], true) && !in_array($value, corsivo_forms_select_options($values), true)) {
		return ['value' => '', 'error' => __('Scegli una delle opzioni disponibili.', 'corsivo-forms')];
	}

	if ($type === 'number') {
		$number = corsivo_forms_parse_number($value);
		if ($number === null) {
			return ['value' => '', 'error' => __('Inserisci un numero valido.', 'corsivo-forms')];
		}
		if ((isset($values['min']) && $number < (float) $values['min']) || (isset($values['max']) && $number > (float) $values['max'])) {
			return ['value' => '', 'error' => __('Il numero è fuori dall’intervallo consentito.', 'corsivo-forms')];
		}
		$step = $values['step'] ?? '1';
		if ($step !== 'any') {
			$increment = corsivo_forms_parse_number($step);
			$quotient = $increment && $increment > 0 ? ($number - (float) ($values['min'] ?? 0)) / $increment : INF;
			if (!is_finite($quotient) || abs($quotient - round($quotient)) > 1e-7) {
				return ['value' => '', 'error' => __('Il numero non rispetta l’incremento consentito.', 'corsivo-forms')];
			}
		}
	}

	if ($type === 'date') {
		if (!corsivo_forms_is_valid_date($value)) {
			return ['value' => '', 'error' => __('Inserisci una data valida nel formato AAAA-MM-GG.', 'corsivo-forms')];
		}
		if ((isset($values['min']) && strcmp($value, $values['min']) < 0) || (isset($values['max']) && strcmp($value, $values['max']) > 0)) {
			return ['value' => '', 'error' => __('La data è fuori dall’intervallo consentito.', 'corsivo-forms')];
		}
	}

	$max_length = $type === 'textarea' ? 5000 : 500;
	if (corsivo_forms_text_length($value) > $max_length) {
		return ['value' => '', 'error' => sprintf(__('Massimo %d caratteri.', 'corsivo-forms'), $max_length)];
	}

	return ['value' => $value, 'error' => null];
}

function corsivo_forms_parse_number($value) {
	if (!is_scalar($value) || is_bool($value) || !preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', trim((string) $value))) {
		return null;
	}
	$number = (float) $value;
	return is_finite($number) ? $number : null;
}

function corsivo_forms_is_valid_date($value) {
	if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) {
		return false;
	}
	return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

function corsivo_forms_field_configuration_error(array $field) {
	$type = $field['type'] ?? '';
	$values = (array) ($field['values'] ?? []);
	if (($type === 'radio' || ($type === 'select' && !empty($values['required']))) && !corsivo_forms_select_options($values)) {
		return new WP_Error($type === 'select' ? 'empty_required_select' : 'empty_radio_options', __('Il modulo non è disponibile: configura le opzioni del campo a scelta.', 'corsivo-forms'), ['status' => 409]);
	}
	if (!in_array($type, ['number', 'date'], true)) {
		return null;
	}
	foreach (['min', 'max'] as $bound) {
		if (isset($values[$bound]) && ($type === 'number' ? corsivo_forms_parse_number($values[$bound]) === null : !corsivo_forms_is_valid_date($values[$bound]))) {
			return new WP_Error('invalid_field_limits', __('Il modulo non è disponibile: controlla i limiti dei campi numero e data.', 'corsivo-forms'), ['status' => 409]);
		}
	}
	if (isset($values['min'], $values['max']) && ($type === 'number' ? (float) $values['min'] > (float) $values['max'] : strcmp($values['min'], $values['max']) > 0)) {
		return new WP_Error('invalid_field_limits', __('Il modulo non è disponibile: il limite minimo supera il massimo.', 'corsivo-forms'), ['status' => 409]);
	}
	if ($type === 'number' && isset($values['step']) && $values['step'] !== 'any' && (corsivo_forms_parse_number($values['step']) === null || (float) $values['step'] <= 0)) {
		return new WP_Error('invalid_field_step', __('Il modulo non è disponibile: l’incremento deve essere positivo oppure any.', 'corsivo-forms'), ['status' => 409]);
	}
	return null;
}

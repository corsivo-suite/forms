<?php

if (!defined('ABSPATH')) {
	exit;
}

function corsivo_forms_form_schema() {
	$field_prefix = 'corsivo/form-';
	$submit_block = 'corsivo/form-submit';
	$field_blocks = array_map(
		static fn($type) => $field_prefix . $type,
		array_keys(corsivo_forms_field_types())
	);

	return [
		['scope' => 'content', 'fields' => [
			'form_fields' => [
				'type'          => 'innerblocks',
				'label'         => 'Campi del modulo',
				'allowedBlocks' => array_merge($field_blocks, [
					$submit_block,
					'core/paragraph',
					'core/heading',
					'core/columns',
					'core/group',
					'core/separator',
					'core/spacer',
					'core/list',
					'core/image',
				]),
				'template'      => [
					[$field_prefix . 'text', ['values' => ['label' => 'Nome', 'required' => true]]],
					[$field_prefix . 'email', ['values' => ['label' => 'Email', 'required' => true]]],
					[$field_prefix . 'textarea', ['values' => ['label' => 'Messaggio', 'required' => true]]],
					[$field_prefix . 'privacy', []],
					[$submit_block, []],
				],
			],
		]],
		['scope' => 'inspector', 'panel' => 'Notifica email', 'initialOpen' => true, 'fields' => [
			'form_title' => [
				'type'        => 'text',
				'label'       => 'Nome del modulo',
				'default'     => 'Contatti',
				'description' => 'Identifica il modulo nelle notifiche e nell\'archivio richieste.',
			],
			'recipients' => [
				'type'        => 'text',
				'label'       => 'Destinatari',
				'default'     => '',
				'placeholder' => get_option('admin_email'),
				'description' => 'Uno o più indirizzi separati da virgola. Se vuoto, l\'email dell\'amministratore.',
			],
			'subject' => [
				'type'        => 'text',
				'label'       => 'Oggetto',
				'default'     => 'Nuovo messaggio dal sito',
				'description' => 'Puoi inserire i valori dei campi con {chiave}, es. {nome}.',
			],
		]],
		['scope' => 'inspector', 'panel' => 'Dopo l\'invio', 'fields' => [
			'success_message' => [
				'type'    => 'textarea',
				'label'   => 'Messaggio di conferma',
				'default' => 'Grazie! Il tuo messaggio è stato inviato.',
			],
			'store' => [
				'type'    => 'checkbox',
				'label'   => 'Archivia le richieste in bacheca',
				'default' => true,
			],
		]],
	];
}

add_action('init', 'corsivo_forms_register_blocks', 20);
add_filter('register_block_type_args', 'corsivo_forms_block_context_args', 10, 2);
function corsivo_forms_block_context_args($args, $block_type) {
	if ($block_type === 'corsivo/form') {
		$args['uses_context'] = array_values(array_unique(array_merge((array) ($args['uses_context'] ?? []), ['postId', 'postType'])));
	}
	return $args;
}

function corsivo_forms_register_blocks() {
	if (!function_exists('define_block_type')) {
		return;
	}

	define_block_type('corsivo/form', [
		'title'        => 'Modulo',
		'icon'         => 'feedback',
		'category'     => 'corsivo-forms',
		'keywords'     => ['form', 'modulo', 'contatti', 'contact form'],
		'wrapperClass' => 'corsivo-form-form-wrapper',
		'schema'       => corsivo_forms_form_schema(),
		'render'       => 'corsivo_forms_render_form',
	]);

	foreach (corsivo_forms_field_types() as $type => $config) {
		define_block_type('corsivo/form-' . $type, [
			'title'            => $config['title'],
			'icon'             => $config['icon'],
			'category'         => 'corsivo-forms',
			'keywords'         => $config['keywords'],
			'ancestor'         => ['corsivo/form'],
			'schema'           => corsivo_forms_field_schema($type),
			'frontend_preview' => false,
			'render'           => static fn($attributes, $content, $block = null) => corsivo_forms_render_field_block($type, $attributes),
		]);
	}

	define_block_type('corsivo/form-submit', [
		'title'            => 'Pulsante di invio',
		'icon'             => 'button',
		'category'         => 'corsivo-forms',
		'keywords'         => ['invia', 'submit', 'pulsante'],
		'ancestor'         => ['corsivo/form'],
		'schema'           => [
			['scope' => 'content', 'fields' => [
				'label' => ['type' => 'text', 'label' => 'Testo del pulsante', 'default' => 'Invia'],
			]],
		],
		'frontend_preview' => false,
		'render'           => 'corsivo_forms_render_submit',
	]);

}

add_filter('render_block_data', 'corsivo_forms_begin_render_scope', 10, 3);
function corsivo_forms_begin_render_scope($parsed_block, $source_block, $parent_block) {
	$name = $parsed_block['blockName'] ?? '';
	if ($name === 'core/post-content') {
		$sources   = &corsivo_forms_render_sources();
		$sources[] = ['kind' => 'post_content'];
	}
	if ($name === 'core/template-part') {
		$slug  = sanitize_key((string) ($parsed_block['attrs']['slug'] ?? ''));
		$theme = sanitize_key((string) ($parsed_block['attrs']['theme'] ?? get_stylesheet()));
		$template = $slug ? get_block_template($theme . '//' . $slug, 'wp_template_part') : null;
		$sources   = &corsivo_forms_render_sources();
		$sources[] = $template
			? ['kind' => 'wp_template_part', 'id' => (string) $template->id, 'hash' => hash('sha256', (string) $template->content)]
			: ['kind' => 'unresolved'];
	}
	if ($name === 'core/block') {
		$ref      = (int) ($parsed_block['attrs']['ref'] ?? 0);
		$reusable = $ref ? get_post($ref) : null;
		$sources  = &corsivo_forms_render_sources();
		$sources[] = $reusable && $reusable->post_type === 'wp_block' && $reusable->post_status === 'publish'
			? ['kind' => 'wp_block', 'id' => $ref, 'hash' => hash('sha256', (string) $reusable->post_content)]
			: ['kind' => 'unresolved'];
	}

	if ($name !== 'corsivo/form') {
		return $parsed_block;
	}

	$scopes   = &corsivo_forms_render_scopes();
	$scopes[] = [
		'fields' => corsivo_forms_collect_fields($parsed_block),
		'index'  => 0,
	];
	return $parsed_block;
}

add_filter('render_block', 'corsivo_forms_end_render_scope', PHP_INT_MAX, 2);
function corsivo_forms_end_render_scope($content, $block) {
	$name = $block['blockName'] ?? '';
	if ($name === 'corsivo/form') {
		$scopes = &corsivo_forms_render_scopes();
		array_pop($scopes);
	}
	if (in_array($name, ['core/post-content', 'core/template-part', 'core/block'], true)) {
		$sources = &corsivo_forms_render_sources();
		array_pop($sources);
	}
	return $content;
}

function &corsivo_forms_render_scopes() {
	static $scopes = [];
	return $scopes;
}

function &corsivo_forms_render_sources() {
	static $sources = [];
	return $sources;
}

function corsivo_forms_current_render_source() {
	global $_wp_current_template_content, $_wp_current_template_id;

	$sources = corsivo_forms_render_sources();
	$post_content = false;
	$chain = [];
	if (!empty($_wp_current_template_id)) {
		$current_template = get_block_template((string) $_wp_current_template_id, 'wp_template');
		$current_content  = $current_template ? (string) $current_template->content : (string) ($_wp_current_template_content ?? '');
		$chain[] = [
			'kind' => 'wp_template',
			'id'   => (string) $_wp_current_template_id,
			'hash' => hash('sha256', $current_content),
		];
	}
	foreach ($sources as $source) {
		if (($source['kind'] ?? '') === 'post_content') {
			$post_content = true;
			continue;
		}
		if (in_array($source['kind'] ?? '', ['wp_template_part', 'wp_block'], true)) {
			$chain[] = $source;
		}
	}
	return [
		'post_content' => $post_content,
		'chain'        => array_slice($chain, 0, 20),
	];
}

function corsivo_forms_render_field_block($type, $attributes) {
	$values = corsivo_forms_normalize_field_values($type, (array) ($attributes['values'] ?? []));
	$scopes = &corsivo_forms_render_scopes();
	$name   = '';

	if ($scopes) {
		$scope_index = array_key_last($scopes);
		$field_index = $scopes[$scope_index]['index'];
		$field       = $scopes[$scope_index]['fields'][$field_index] ?? null;
		if (is_array($field) && ($field['type'] ?? '') === $type) {
			$name = (string) $field['name'];
			$scopes[$scope_index]['index']++;
		}
	}

	if ($name === '') {
		$used = [];
		$name = corsivo_forms_field_name($values, $type, $used);
	}

	return corsivo_forms_render_field_input($type, $name, $values);
}

function corsivo_forms_render_submit($attributes) {
	$values = $attributes['values'] ?? [];
	$label  = trim((string) ($values['label'] ?? '')) ?: 'Invia';

	return '<p class="corsivo-form-form__actions"><button type="button" class="corsivo-form-form__submit">' . esc_html($label) . '</button></p>';
}

function corsivo_forms_render_form($attributes, $content, $wp_block = null) {
	if (!$wp_block instanceof WP_Block) {
		return $content;
	}

	$post_id    = corsivo_forms_render_post_id($wp_block);
	$definition = corsivo_forms_form_definition($wp_block->parsed_block, $post_id, corsivo_forms_current_render_source());
	$error      = corsivo_forms_form_configuration_error($definition);
	$token      = is_wp_error($error) ? '' : corsivo_forms_create_form_token($definition);
	if (!is_wp_error($error) && $token === '') {
		$error = new WP_Error(
			'form_token_unavailable',
			__('Il modulo non è disponibile: riduci la configurazione o abilita OpenSSL.', 'corsivo-forms'),
			['status' => 503]
		);
	}
	wp_enqueue_style('corsivo-forms');
	wp_enqueue_script('corsivo-forms');

	if (is_wp_error($error)) {
		$payload  = wp_json_encode(['unavailable' => true]);
		$content  = str_replace(
			'type="button" class="corsivo-form-form__submit"',
			'type="button" class="corsivo-form-form__submit" disabled aria-disabled="true"',
			$content
		);
		$feedback = '<p class="corsivo-form-form__feedback is-error" role="alert" aria-live="polite" tabindex="-1">' . esc_html($error->get_error_message()) . '</p>';
	} else {
		$payload = wp_json_encode(['token' => $token, 'formId' => $definition['form_id']]);
		$feedback = '<p class="corsivo-form-form__feedback" role="status" aria-live="polite" tabindex="-1"></p>';
	}

	return '<form class="corsivo-form-form" method="post" enctype="multipart/form-data" novalidate aria-label="' . esc_attr($definition['settings']['form_title']) . '" data-corsivo-form-form="' . esc_attr($payload) . '">'
		. $content
		. '<p class="corsivo-form-form__hp" aria-hidden="true"><label>' . esc_html__('Non compilare questo campo', 'corsivo-forms') . '<input type="text" name="corsivo_forms_hp" tabindex="-1" autocomplete="off"></label></p>'
		. $feedback
		. '<p class="corsivo-form-form__js-required is-error" role="alert">' . esc_html__('Per inviare il modulo è necessario JavaScript.', 'corsivo-forms') . '</p>'
		. '</form>';
}

function corsivo_forms_render_post_id(WP_Block $wp_block) {
	$context_id = (int) ($wp_block->context['postId'] ?? 0);
	if ($context_id && get_post($context_id)) {
		return $context_id;
	}

	if (in_the_loop()) {
		$loop_id = (int) get_the_ID();
		if ($loop_id && get_post($loop_id)) {
			return $loop_id;
		}
	}

	$queried = get_queried_object();
	return $queried instanceof WP_Post ? (int) $queried->ID : 0;
}

function corsivo_forms_collect_forms($post_content) {
	$forms = [];
	$stack = [];
	$nodes = 0;
	corsivo_forms_walk_for_forms(parse_blocks((string) $post_content), $forms, $stack, 0, $nodes);
	return $forms;
}

function corsivo_forms_walk_for_forms(array $blocks, array &$forms, array &$stack = [], $depth = 0, &$nodes = 0) {
	if ($depth > 20) {
		return;
	}

	foreach ($blocks as $block) {
		if (++$nodes > 1000 || !is_array($block)) {
			return;
		}

		$name = $block['blockName'] ?? '';

		if ($name === 'core/block') {
			$ref = (int) ($block['attrs']['ref'] ?? 0);
			if (!$ref || isset($stack[$ref])) {
				continue;
			}
			$stack[$ref] = true;
			$reusable = corsivo_forms_resolve_reusable($block);
			if ($reusable) {
				corsivo_forms_walk_for_forms($reusable, $forms, $stack, $depth + 1, $nodes);
			}
			unset($stack[$ref]);
			continue;
		}

		if ($name === 'corsivo/form') {
			$forms[] = $block;
			continue;
		}

		if (!empty($block['innerBlocks'])) {
			corsivo_forms_walk_for_forms($block['innerBlocks'], $forms, $stack, $depth + 1, $nodes);
		}
	}
}

function corsivo_forms_collect_fields(array $form_block) {
	$result = corsivo_forms_collect_fields_result($form_block);
	return $result['fields'];
}

function corsivo_forms_collect_fields_result(array $form_block) {
	$fields = [];
	$used   = [];
	$stack  = [];
	$nodes  = 0;
	$truncated = false;
	corsivo_forms_walk_for_fields($form_block['innerBlocks'] ?? [], $fields, $used, $stack, 0, $nodes, $truncated);
	return ['fields' => $fields, 'truncated' => $truncated];
}

function corsivo_forms_walk_for_fields(array $blocks, array &$fields, array &$used, array &$stack = [], $depth = 0, &$nodes = 0, &$truncated = false) {
	if ($depth > 20) {
		$truncated = true;
		return;
	}

	$types = corsivo_forms_field_types();

	foreach ($blocks as $block) {
		if (++$nodes > 1000 || !is_array($block)) {
			$truncated = true;
			return;
		}

		$name = $block['blockName'] ?? '';

		if ($name === 'core/block') {
			$ref = (int) ($block['attrs']['ref'] ?? 0);
			if (!$ref || isset($stack[$ref])) {
				continue;
			}
			$stack[$ref] = true;
			$reusable = corsivo_forms_resolve_reusable($block);
			if ($reusable) {
				corsivo_forms_walk_for_fields($reusable, $fields, $used, $stack, $depth + 1, $nodes, $truncated);
			}
			unset($stack[$ref]);
			continue;
		}

		$type = corsivo_forms_block_field_type((string) $name);
		if ($type !== '' && isset($types[$type])) {
				$values   = corsivo_forms_normalize_field_values($type, (array) ($block['attrs']['values'] ?? []));
				$fields[] = [
					'type'   => $type,
					'name'   => corsivo_forms_field_name($values, $type, $used),
					'values' => $values,
				];
				continue;
		}

		if (!empty($block['innerBlocks'])) {
			corsivo_forms_walk_for_fields($block['innerBlocks'], $fields, $used, $stack, $depth + 1, $nodes, $truncated);
		}
	}
}

function corsivo_forms_form_has_submit(array $form_block) {
	$stack = [];
	$nodes = 0;
	return corsivo_forms_walk_for_submit($form_block['innerBlocks'] ?? [], $stack, 0, $nodes);
}

function corsivo_forms_walk_for_submit(array $blocks, array &$stack, $depth, &$nodes) {
	if ($depth > 20) {
		return false;
	}

	foreach ($blocks as $block) {
		if (++$nodes > 1000 || !is_array($block)) {
			return false;
		}
		$name = $block['blockName'] ?? '';
		if ($name === 'corsivo/form-submit') {
			return true;
		}
		if ($name === 'core/block') {
			$ref = (int) ($block['attrs']['ref'] ?? 0);
			if (!$ref || isset($stack[$ref])) {
				continue;
			}
			$stack[$ref] = true;
			$reusable = corsivo_forms_resolve_reusable($block);
			$found    = $reusable ? corsivo_forms_walk_for_submit($reusable, $stack, $depth + 1, $nodes) : false;
			unset($stack[$ref]);
			if ($found) {
				return true;
			}
			continue;
		}
		if (!empty($block['innerBlocks']) && corsivo_forms_walk_for_submit($block['innerBlocks'], $stack, $depth + 1, $nodes)) {
			return true;
		}
	}
	return false;
}

function corsivo_forms_block_field_type($block_name) {
	if (str_starts_with($block_name, 'corsivo/form-')) {
		return substr($block_name, strlen('corsivo/form-'));
	}
	return '';
}

function corsivo_forms_resolve_reusable(array $block) {
	static $cache = [];

	$ref = (int) ($block['attrs']['ref'] ?? 0);
	if (!$ref) {
		return null;
	}
	$cache_key = get_current_blog_id() . ':' . $ref;
	if (array_key_exists($cache_key, $cache)) {
		return $cache[$cache_key];
	}
	$reusable = get_post($ref);
	if (!$reusable || $reusable->post_type !== 'wp_block' || $reusable->post_status !== 'publish' || $reusable->post_password !== '') {
		$cache[$cache_key] = null;
		return $cache[$cache_key];
	}
	$cache[$cache_key] = parse_blocks($reusable->post_content);
	return $cache[$cache_key];
}

add_action('wp_enqueue_scripts', 'corsivo_forms_register_assets');
function corsivo_forms_register_assets() {
	$css = CORSIVO_FORMS_DIR . 'assets/corsivo-forms.css';
	$js  = CORSIVO_FORMS_DIR . 'assets/corsivo-forms.js';

	wp_register_style('corsivo-forms', CORSIVO_FORMS_URL . 'assets/corsivo-forms.css', [], file_exists($css) ? (string) filemtime($css) : CORSIVO_FORMS_VERSION);
	wp_register_script('corsivo-forms', CORSIVO_FORMS_URL . 'assets/corsivo-forms.js', [], file_exists($js) ? (string) filemtime($js) : CORSIVO_FORMS_VERSION, true);

	wp_add_inline_script('corsivo-forms', 'window.corsivoFormsSettings = ' . wp_json_encode([
		'endpoint' => esc_url_raw(rest_url('corsivo/v1/forms/submit')),
		'messages' => [
			'unavailable' => __('Il modulo non è disponibile: ricarica la pagina.', 'corsivo-forms'),
			'sending'     => __('Invio in corso…', 'corsivo-forms'),
			'failed'      => __('Invio non riuscito, riprova.', 'corsivo-forms'),
		],
	]) . ';', 'before');
}

<?php

if (!defined('ABSPATH')) {
	exit;
}

add_filter('block_categories_all', 'corsivo_forms_register_block_category');
function corsivo_forms_register_block_category($categories) {
	$categories[] = ['slug' => 'corsivo-forms', 'title' => 'Moduli'];
	return $categories;
}

add_action('enqueue_block_editor_assets', 'corsivo_forms_extend_block_settings', 20);
function corsivo_forms_extend_block_settings() {
	if (!wp_script_is('defb-editor', 'enqueued') && !wp_script_is('defb-editor', 'registered')) {
		return;
	}

	$script = <<<'JS'
( function ( wp ) {
	wp.hooks.addFilter( 'defb.blockSettings', 'corsivo-forms/block-settings', function ( settings, name, blockData ) {
		if ( blockData.ancestor ) {
			settings.ancestor = blockData.ancestor;
		}
		return settings;
	} );
} )( window.wp );
JS;

	wp_add_inline_script('defb-editor', $script, 'before');
}

add_filter('defb_preview_style', 'corsivo_forms_preview_style');
function corsivo_forms_preview_style($sources) {
	$sources = is_string($sources) ? [$sources] : (array) $sources;
	$sources[] = CORSIVO_FORMS_DIR . 'assets/corsivo-forms.css';
	return $sources;
}

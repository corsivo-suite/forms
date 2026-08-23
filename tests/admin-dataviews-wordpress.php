<?php

$administratorIds = get_users([
	'role'   => 'administrator',
	'number' => 1,
	'fields' => 'ID',
]);
if (!$administratorIds) {
	throw new RuntimeException('Nessun amministratore disponibile per lo smoke test.');
}

wp_set_current_user((int) $administratorIds[0]);
$request = new WP_REST_Request('GET', '/corsivo/v1/forms/activity');
$request->set_param('per_page', 10);
$response = rest_do_request($request);
if ($response->get_status() !== 200 || !is_array($response->get_data())) {
	throw new RuntimeException('La collection attività non restituisce una risposta valida.');
}
if (!isset($response->get_headers()['X-WP-Total'], $response->get_headers()['X-WP-TotalPages'])) {
	throw new RuntimeException('La collection attività non espone la paginazione WordPress.');
}

$_GET['tab'] = 'activity';
corsivo_forms_enqueue_activity_assets('tools_page_corsivo-forms-settings');
if (!wp_script_is('corsivo-forms-activity', 'enqueued')
	|| !wp_style_is('corsivo-forms-activity-ui', 'enqueued')
	|| !wp_style_is('corsivo-forms-activity-app', 'enqueued')) {
	throw new RuntimeException('Gli asset della tab Attività non sono accodati completamente.');
}

echo 'Corsivo Forms DataViews WordPress smoke passed.' . PHP_EOL;

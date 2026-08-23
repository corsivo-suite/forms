<?php

$plugin_directory = dirname(__DIR__);
$settings_file = $plugin_directory . '/includes/settings.php';
$bootstrap_file = $plugin_directory . '/corsivo-forms.php';
$settings_source = file_get_contents($settings_file);
$bootstrap_source = file_get_contents($bootstrap_file);

if (!is_string($settings_source) || !is_string($bootstrap_source)) {
	fwrite(STDERR, 'Impossibile leggere il runtime delle impostazioni.' . PHP_EOL);
	exit(1);
}

$failures = [];
$register_pattern = '/function\s+corsivo_forms_register_settings\s*\([^)]*\)\s*\{.*?(?=\nfunction\s+|\z)/s';
$register_source = preg_match($register_pattern, $settings_source, $matches) ? $matches[0] : '';

if ($register_source === '') {
	$failures[] = 'corsivo_forms_register_settings non trovata.';
} elseif (str_contains($register_source, 'corsivo_forms_install_settings(')) {
	$failures[] = 'La registrazione admin installa ancora le impostazioni.';
}

$activation_pattern = '/function\s+corsivo_forms_activate_site\s*\([^)]*\)\s*\{.*?(?=\nfunction\s+|\z)/s';
$activation_source = preg_match($activation_pattern, $bootstrap_source, $matches) ? $matches[0] : '';

if ($activation_source === '' || !str_contains($activation_source, 'corsivo_forms_install_settings();')) {
	$failures[] = 'L’attivazione non installa le impostazioni.';
}

$site_pattern = '/function\s+corsivo_forms_initialize_site\s*\([^)]*\)\s*\{.*?(?=\nfunction\s+|\z)/s';
$site_source = preg_match($site_pattern, $bootstrap_source, $matches) ? $matches[0] : '';
if ($site_source === ''
	|| !str_contains($site_source, "get_site_option('active_sitewide_plugins'")
	|| !str_contains($site_source, 'corsivo_forms_activate_site();')
	|| !str_contains($bootstrap_source, "add_action('wp_initialize_site', 'corsivo_forms_initialize_site'")) {
	$failures[] = 'I nuovi siti della rete non ricevono lo storage e le impostazioni Forms.';
}

if ($failures) {
	fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
	exit(1);
}

echo 'Corsivo Forms settings lifecycle static checks passed.' . PHP_EOL;

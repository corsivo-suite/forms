<?php

if (!defined('ABSPATH')) {
	exit;
}

function corsivo_forms_condition_error($code = 'invalid_conditions') {
	return new WP_Error($code, __('Il modulo non è disponibile: controlla le condizioni dei campi e delle notifiche.', 'corsivo-forms'), ['status' => 409]);
}

function corsivo_forms_normalize_conditions($conditions, $condition_mode = 'all') {
	$operators = ['equal', 'not_equal', 'contains', 'not_contains', 'is_empty', 'is_not_empty', 'greater', 'greater_or_equal', 'less', 'less_or_equal'];
	if (!is_array($conditions) || !array_is_list($conditions) || count($conditions) > 10 || !is_string($condition_mode) || !in_array($condition_mode, ['all', 'any'], true)) {
		return corsivo_forms_condition_error();
	}
	$normalized = [];
	foreach ($conditions as $condition) {
		if (!is_array($condition) || !is_string($condition['field'] ?? null) || !is_string($condition['operator'] ?? null)) {
			return corsivo_forms_condition_error();
		}
		$field = $condition['field'];
		$operator = $condition['operator'];
		$value = $condition['value'] ?? '';
		if (!preg_match('/^[a-z0-9_]{1,50}$/', $field) || !in_array($operator, $operators, true) || !is_scalar($value) || (is_float($value) && !is_finite($value))) {
			return corsivo_forms_condition_error();
		}
		$value = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);
		if (corsivo_forms_text_length($value) > 5000) {
			return corsivo_forms_condition_error();
		}
		$normalized[] = ['field' => $field, 'operator' => $operator, 'value' => in_array($operator, ['is_empty', 'is_not_empty'], true) ? '' : $value];
	}
	return ['condition_mode' => $condition_mode, 'conditions' => $normalized];
}

function corsivo_forms_condition_operand(array $field, $raw, $comparison = false, $operator = '') {
	if ($raw !== null && !is_scalar($raw)) {
		return ['valid' => false, 'value' => ''];
	}
	if (in_array($field['type'], ['file', 'privacy'], true)) {
		return ['valid' => false, 'value' => ''];
	}
	$field['values']['required'] = false;
	if ($comparison) {
		unset($field['values']['min'], $field['values']['max']);
		$field['values']['step'] = 'any';
		if ($field['type'] === 'checkbox' && !in_array($raw, ['', '0', '1'], true)) {
			return ['valid' => false, 'value' => ''];
		}
		if (in_array($operator, ['contains', 'not_contains'], true)) {
			$field['type'] = $field['type'] === 'textarea' ? 'textarea' : 'text';
		}
	}
	$result = corsivo_forms_validate_field($field, $raw);
	return ['valid' => $result['error'] === null, 'value' => $result['value']];
}

function corsivo_forms_validate_condition_references(array $conditions, array $fields_by_name, $target = '') {
	foreach ($conditions as $condition) {
		$source = $fields_by_name[$condition['field']] ?? null;
		if (!$source || $condition['field'] === $target || in_array($source['type'], ['file', 'privacy'], true)) {
			return corsivo_forms_condition_error('invalid_condition_source');
		}
		$operator = $condition['operator'];
		if (in_array($operator, ['greater', 'greater_or_equal', 'less', 'less_or_equal'], true) && !in_array($source['type'], ['number', 'date'], true)) {
			return corsivo_forms_condition_error('invalid_condition_operator');
		}
		if (in_array($operator, ['contains', 'not_contains'], true) && in_array($source['type'], ['number', 'date', 'checkbox'], true)) {
			return corsivo_forms_condition_error('invalid_condition_operator');
		}
		if (!in_array($operator, ['is_empty', 'is_not_empty'], true)) {
			$operand = corsivo_forms_condition_operand($source, $condition['value'], true, $operator);
			if (!$operand['valid'] || (in_array($operator, ['greater', 'greater_or_equal', 'less', 'less_or_equal'], true) && $operand['value'] === '')) {
				return corsivo_forms_condition_error('invalid_condition_value');
			}
		}
	}
	return true;
}

function corsivo_forms_validate_condition_graph(array $fields) {
	$fields_by_name = array_column($fields, null, 'name');
	$dependencies = [];
	foreach ($fields as $field) {
		if (!empty($field['values']['_conditions_error'])) {
			return corsivo_forms_condition_error();
		}
		$normalized = corsivo_forms_normalize_conditions($field['values']['conditions'] ?? [], $field['values']['condition_mode'] ?? 'all');
		if (is_wp_error($normalized)) {
			return $normalized;
		}
		if ($normalized['conditions'] && $field['type'] === 'privacy') {
			return corsivo_forms_condition_error('conditional_privacy');
		}
		$validated = corsivo_forms_validate_condition_references($normalized['conditions'], $fields_by_name, $field['name']);
		if (is_wp_error($validated)) {
			return $validated;
		}
		$dependencies[$field['name']] = array_column($normalized['conditions'], 'field');
	}
	$visited = [];
	$visiting = [];
	$visit = static function ($name) use (&$visit, &$visited, &$visiting, $dependencies) {
		if (isset($visiting[$name])) {
			return false;
		}
		if (isset($visited[$name])) {
			return true;
		}
		$visiting[$name] = true;
		foreach ($dependencies[$name] ?? [] as $dependency) {
			if (!$visit($dependency)) {
				return false;
			}
		}
		unset($visiting[$name]);
		$visited[$name] = true;
		return true;
	};
	foreach (array_keys($dependencies) as $name) {
		if (!$visit($name)) {
			return corsivo_forms_condition_error('condition_cycle');
		}
	}
	return true;
}

function corsivo_forms_conditions_match(array $conditions, $mode, array $fields_by_name, array $values, array $active_by_name) {
	$matches = [];
	foreach ($conditions as $condition) {
		$name = $condition['field'];
		$source = $fields_by_name[$name] ?? null;
		if (!$source || empty($active_by_name[$name])) {
			$matches[] = false;
			continue;
		}
		$operand = corsivo_forms_condition_operand($source, $values[$name] ?? null);
		if (!$operand['valid']) {
			$matches[] = false;
			continue;
		}
		$value = $operand['value'];
		$empty = $value === '' || $value === false || $value === null;
		$operator = $condition['operator'];
		if ($operator === 'is_empty' || $operator === 'is_not_empty') {
			$matches[] = $operator === 'is_empty' ? $empty : !$empty;
			continue;
		}
		$comparison = corsivo_forms_condition_operand($source, $condition['value'], true, $operator);
		if (!$comparison['valid']) {
			$matches[] = false;
			continue;
		}
		$expected = $comparison['value'];
		if ($source['type'] === 'number' && $value !== '' && $expected !== '') {
			$value = (float) $value;
			$expected = (float) $expected;
		}
		$matches[] = match ($operator) {
			'equal' => $value === $expected,
			'not_equal' => $value !== $expected,
			'contains' => str_contains((string) $value, (string) $expected),
			'not_contains' => !str_contains((string) $value, (string) $expected),
			'greater' => !$empty && $value > $expected,
			'greater_or_equal' => !$empty && $value >= $expected,
			'less' => !$empty && $value < $expected,
			'less_or_equal' => !$empty && $value <= $expected,
			default => false,
		};
	}
	return $mode === 'any' ? in_array(true, $matches, true) : !in_array(false, $matches, true);
}

function corsivo_forms_resolve_active_fields(array $fields, array $values) {
	if (is_wp_error(corsivo_forms_validate_condition_graph($fields))) {
		return [];
	}
	$fields_by_name = array_column($fields, null, 'name');
	$active = [];
	$resolve = static function ($name) use (&$resolve, &$active, $fields_by_name, $values) {
		if (array_key_exists($name, $active)) {
			return $active[$name];
		}
		$field = $fields_by_name[$name];
		$conditions = $field['values']['conditions'] ?? [];
		foreach ($conditions as $condition) {
			$resolve($condition['field']);
		}
		$active[$name] = !$conditions || corsivo_forms_conditions_match($conditions, $field['values']['condition_mode'] ?? 'all', $fields_by_name, $values, $active);
		return $active[$name];
	};
	foreach (array_keys($fields_by_name) as $name) {
		$resolve($name);
	}
	return array_values(array_filter($fields, static fn($field) => $active[$field['name']]));
}

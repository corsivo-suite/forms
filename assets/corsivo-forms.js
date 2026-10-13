(() => {
	const settings = window.corsivoFormsSettings || {};
	const messages = settings.messages || {};
	const confirmedRetryCodes = new Set([
		'activity_unavailable',
		'rate_limit_unavailable',
		'submission_failed',
	]);
	const initializedForms = new WeakSet();
	const numberPattern = /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/;
	const orderedOperators = new Set(['greater', 'greater_or_equal', 'less', 'less_or_equal']);
	const operators = new Set(['equal', 'not_equal', 'contains', 'not_contains', 'is_empty', 'is_not_empty', ...orderedOperators]);
	const trimText = (value) => String(value ?? '').replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '');

	const parseNumber = (value) => numberPattern.test(value) && Number.isFinite(Number(value)) ? Number(value) : null;
	const isValidDate = (value) => {
		const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
		if (!parts) {
			return false;
		}
		const year = Number(parts[1]);
		const month = Number(parts[2]);
		const day = Number(parts[3]);
		const monthDays = [31, year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
		return year > 0 && month > 0 && month <= 12 && day > 0 && day <= monthDays[month - 1];
	};

	const sanitizeText = (raw, multiline = false) => {
		let value = trimText(raw);
		if (value.includes('<')) {
			value = value.replace(/<[^>]*?(?=<|>|$)>?/g, (fragment) => fragment.includes('>') ? fragment : fragment.replace(/&(?!(?:#\d+|#x[a-f\d]+|[a-z][a-z\d]+);)/gi, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'))
				.replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, '')
				.replace(/<!--[\s\S]*?-->|<(?!\s)(?:[^>"']|"[^"]*"|'[^']*')*>/g, '')
				.replace(/<\n/g, '&lt;\n');
		}
		if (!multiline) {
			value = value.replace(/[\r\n\t ]+/g, ' ');
		}
		let hadEncodedCharacters = false;
		while (/%[a-f\d]{2}/i.test(value)) {
			hadEncodedCharacters = true;
			value = value.replace(/%[a-f\d]{2}/gi, '');
		}
		return trimText(hadEncodedCharacters ? value.replace(/ +/g, ' ') : value);
	};

	const conditionOperand = (field, raw, comparison = false, operator = '') => {
		if (comparison && ['contains', 'not_contains'].includes(operator)) {
			field = { ...field, type: field.type === 'textarea' ? 'textarea' : 'text' };
		}
		const invalid = { valid: false, value: '' };
		const values = field.values || {};
		if (['file', 'privacy'].includes(field.type) || (raw !== null && raw !== undefined && !['string', 'number', 'boolean'].includes(typeof raw))) {
			return invalid;
		}
		if (field.type === 'checkbox') {
			if (comparison && !['', '0', '1'].includes(raw)) {
				return invalid;
			}
			return { valid: true, value: [1, '1', true, 'on'].includes(raw) };
		}
		let value = trimText(raw);
		if (!['number', 'date'].includes(field.type)) {
			value = sanitizeText(value, field.type === 'textarea');
		}
		if (value === '') {
			return { valid: true, value };
		}
		if (field.type === 'email' && (value.length < 6 || !/^[a-z\d.!#$%&'*+\-/=?^_`{|}~]+@[a-z\d](?:[a-z\d-]*[a-z\d])?(?:\.[a-z\d](?:[a-z\d-]*[a-z\d])?)+$/i.test(value))) {
			return invalid;
		}
		if (['select', 'radio'].includes(field.type) && !(values.options || []).some((option) => option.option === value)) {
			return invalid;
		}
		if (field.type === 'number') {
			const number = parseNumber(value);
			if (number === null || (!comparison && ((values.min !== undefined && number < Number(values.min)) || (values.max !== undefined && number > Number(values.max))))) {
				return invalid;
			}
			const step = comparison ? 'any' : (values.step || '1');
			if (step !== 'any') {
				const increment = parseNumber(step);
				const quotient = increment > 0 ? (number - Number(values.min || 0)) / increment : Infinity;
				if (!Number.isFinite(quotient) || Math.abs(quotient - Math.round(quotient)) > 1e-7) {
					return invalid;
				}
			}
		}
		if (field.type === 'date' && (!isValidDate(value) || (!comparison && ((values.min !== undefined && value < values.min) || (values.max !== undefined && value > values.max))))) {
			return invalid;
		}
		if ([...value].length > (field.type === 'textarea' ? 5000 : 500)) {
			return invalid;
		}
		return { valid: true, value };
	};

	const conditionsMatch = (conditions, mode, fields, rawValues, active) => {
		const matches = conditions.map((condition) => {
			const source = fields.get(condition.field);
			if (!source || !active.get(condition.field)) {
				return false;
			}
			const actual = conditionOperand(source, rawValues.get(condition.field));
			if (!actual.valid) {
				return false;
			}
			const empty = actual.value === '' || actual.value === false || actual.value === null;
			if (condition.operator === 'is_empty' || condition.operator === 'is_not_empty') {
				return condition.operator === 'is_empty' ? empty : !empty;
			}
			const comparison = conditionOperand(source, condition.value, true, condition.operator);
			if (!comparison.valid) {
				return false;
			}
			const numeric = source.type === 'number' && actual.value !== '' && comparison.value !== '';
			const value = numeric ? Number(actual.value) : actual.value;
			const expected = numeric ? Number(comparison.value) : comparison.value;
			switch (condition.operator) {
				case 'equal': return value === expected;
				case 'not_equal': return value !== expected;
				case 'contains': return String(value).includes(String(expected));
				case 'not_contains': return !String(value).includes(String(expected));
				case 'greater': return !empty && value > expected;
				case 'greater_or_equal': return !empty && value >= expected;
				case 'less': return !empty && value < expected;
				case 'less_or_equal': return !empty && value <= expected;
				default: return false;
			}
		});
		return mode === 'any' ? matches.some(Boolean) : matches.every(Boolean);
	};

	const resolveVisibility = (definitions, rawValues) => {
		const fields = new Map(definitions.map((field) => [field.name, field]));
		const active = new Map();
		const visiting = new Set();
		let invalidGraph = false;
		const resolve = (name) => {
			if (active.has(name)) {
				return active.get(name);
			}
			const field = fields.get(name);
			if (!field || visiting.has(name)) {
				invalidGraph = true;
				return false;
			}
			visiting.add(name);
			const values = field.values || {};
			const conditions = values.conditions === undefined ? [] : values.conditions;
			if (values._conditions_error || !Array.isArray(conditions) || conditions.length > 10 || (values.condition_mode !== undefined && !['all', 'any'].includes(values.condition_mode))) {
				invalidGraph = true;
				return false;
			}
			conditions.forEach((condition) => {
				if (!condition || typeof condition.field !== 'string' || typeof condition.operator !== 'string') {
					invalidGraph = true;
					return;
				}
				const source = fields.get(condition.field);
				if (!source || source.name === name || ['file', 'privacy'].includes(source.type) || !operators.has(condition.operator)
					|| (orderedOperators.has(condition.operator) && !['number', 'date'].includes(source.type))
					|| (['contains', 'not_contains'].includes(condition.operator) && ['number', 'date', 'checkbox'].includes(source.type))
					|| (field.type === 'privacy' && conditions.length)) {
					invalidGraph = true;
					return;
				}
				if (!['is_empty', 'is_not_empty'].includes(condition.operator)) {
					const comparison = conditionOperand(source, condition.value, true, condition.operator);
					if (!comparison.valid || (orderedOperators.has(condition.operator) && comparison.value === '')) {
						invalidGraph = true;
						return;
					}
				}
				resolve(condition.field);
			});
			const visible = !conditions.length || conditionsMatch(conditions, values.condition_mode || 'all', fields, rawValues, active);
			visiting.delete(name);
			active.set(name, visible);
			return visible;
		};
		definitions.forEach((field) => resolve(field.name));
		if (invalidGraph) {
			definitions.forEach((field) => active.set(field.name, field.type === 'privacy'));
		}
		return active;
	};

	const initializeConditions = (form, definitions) => {
		if (!Array.isArray(definitions) || !definitions.length) {
			return () => {};
		}
		const fields = new Map([...form.querySelectorAll('.corsivo-form-field[data-corsivo-form-name]')].map((field) => [field.dataset.corsivoFormName, field]));
		const originalControls = new WeakMap();
		fields.forEach((field) => field.querySelectorAll('input,textarea,select').forEach((control) => originalControls.set(control, { required: control.required, disabled: control.disabled })));
		const recalculate = () => {
			const rawValues = new Map();
			definitions.forEach((definition) => {
				const field = fields.get(definition.name);
				const controls = field ? [...field.querySelectorAll('input,textarea,select')] : [];
				const control = definition.type === 'radio' ? controls.find((candidate) => candidate.checked) : controls[0];
				const value = definition.type === 'checkbox' ? Boolean(control && control.checked) : (control ? control.value : '');
				rawValues.set(definition.name, control && control.validity.badInput ? [] : value);
			});
			const active = resolveVisibility(definitions, rawValues);
			definitions.forEach((definition) => {
				const field = fields.get(definition.name);
				if (!field) {
					return;
				}
				const visible = definition.type === 'privacy' || active.get(definition.name);
				field.hidden = !visible;
				field.querySelectorAll('input,textarea,select').forEach((control) => {
					const original = originalControls.get(control);
					control.disabled = !visible || original.disabled;
					control.required = visible && original.required;
					if (control.required) {
						control.setAttribute('aria-required', 'true');
					} else {
						control.removeAttribute('aria-required');
					}
				});
				if (!visible) {
					clearErrors(field);
					field.classList.remove('has-error');
				}
			});
		};
		form.addEventListener('input', recalculate);
		form.addEventListener('change', recalculate);
		form.addEventListener('reset', () => queueMicrotask(recalculate));
		recalculate();
		return recalculate;
	};

	const createRequestId = () => {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		const random = window.crypto && typeof window.crypto.getRandomValues === 'function'
			? window.crypto.getRandomValues(new Uint32Array(4)).join('-')
			: `${Date.now()}-${Math.random().toString(16).slice(2)}`;
		return `request-${random}`;
	};

	const clearErrors = (form) => {
		form.querySelectorAll('.corsivo-form-field.has-error').forEach((field) => field.classList.remove('has-error'));
		form.querySelectorAll('.corsivo-form-field__error').forEach((notice) => notice.remove());
		form.querySelectorAll('[aria-invalid="true"]').forEach((control) => {
			control.removeAttribute('aria-invalid');
			const descriptions = (control.getAttribute('aria-describedby') || '')
				.split(/\s+/)
				.filter((id) => id && !id.startsWith('corsivo-form-error-'));
			if (descriptions.length) {
				control.setAttribute('aria-describedby', descriptions.join(' '));
			} else {
				control.removeAttribute('aria-describedby');
			}
		});
	};

	const showFieldError = (form, name, message, index) => {
		const fieldName = String(name).replace(/(?:\[\d*\]|\.\d+)$/, '');
		const field = form.querySelector(`.corsivo-form-field[data-corsivo-form-name="${CSS.escape(fieldName)}"]`);
		const controls = field ? [...field.querySelectorAll('[name]')] : [];
		const control = controls.find((candidate) => candidate.checked) || controls[0];
		if (!field || !control || field.hidden) {
			return null;
		}

		const errorId = `corsivo-form-error-${index}-${createRequestId()}`;
		field.classList.add('has-error');
		controls.forEach((candidate) => {
			const descriptions = (candidate.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
			descriptions.push(errorId);
			candidate.setAttribute('aria-invalid', 'true');
			candidate.setAttribute('aria-describedby', descriptions.join(' '));
		});

		const notice = document.createElement('p');
		notice.id = errorId;
		notice.className = 'corsivo-form-field__error';
		notice.textContent = message;
		field.append(notice);
		return control;
	};

	const createPayload = (form, target, requestId) => {
		const payload = new FormData(form);
		payload.set('token', target.token);
		payload.set('formId', target.formId);
		payload.set('request_id', requestId);
		return payload;
	};

	const readResponse = async (response) => {
		const text = await response.text();
		try {
			return JSON.parse(text);
		} catch {
			return {};
		}
	};

	const initializeForm = (form) => {
		if (initializedForms.has(form)) {
			return;
		}
		initializedForms.add(form);

		const feedback = form.querySelector('.corsivo-form-form__feedback');
		const submitButtons = [...form.querySelectorAll('.corsivo-form-form__submit')];
		form.querySelector('.corsivo-form-form__js-required')?.remove();
		let target = null;
		let requestId = createRequestId();
		let isSubmitting = false;

		try {
			target = JSON.parse(form.dataset.corsivoFormForm);
		} catch {
			target = null;
		}
		const recalculateConditions = initializeConditions(form, target && target.fields);
		submitButtons.forEach((button) => {
			button.type = 'submit';
			if (target && target.unavailable) {
				button.disabled = true;
				button.setAttribute('aria-disabled', 'true');
			}
		});

		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (isSubmitting) {
				return;
			}

			clearErrors(form);
			recalculateConditions();
			if (feedback) {
				feedback.className = 'corsivo-form-form__feedback';
			}

			if (!target || target.unavailable || typeof settings.endpoint !== 'string' || settings.endpoint === '') {
				if (feedback) {
					feedback.classList.add('is-error');
					feedback.setAttribute('role', 'alert');
					feedback.textContent = messages.unavailable || 'Il modulo non è disponibile: ricarica la pagina.';
					feedback.focus();
				}
				return;
			}

			if (!form.reportValidity()) {
				return;
			}

			isSubmitting = true;
			submitButtons.forEach((button) => {
				button.disabled = true;
				button.setAttribute('aria-disabled', 'true');
			});
			if (feedback) {
				feedback.setAttribute('role', 'status');
				feedback.textContent = messages.sending || 'Invio in corso…';
			}

			try {
				const payload = createPayload(form, target, requestId);
				const response = await fetch(settings.endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Accept': 'application/json',
					},
					body: payload,
				});
				const responseData = await readResponse(response);

				if (response.ok && responseData.sent) {
					form.reset();
					requestId = createRequestId();
					if (feedback) {
						feedback.classList.add('is-success');
						feedback.setAttribute('role', 'status');
						feedback.textContent = responseData.message;
					}
					return;
				}

				const errors = responseData.data && responseData.data.errors ? responseData.data.errors : null;
				let firstInvalidControl = null;
				if (errors) {
					Object.entries(errors).forEach(([name, message], index) => {
						const control = showFieldError(form, name, message, index);
						firstInvalidControl = firstInvalidControl || control;
					});
				}
				const canRetryWithNewRequest = responseData.code === 'corsivo_forms_request_unknown'
					|| responseData.code === 'request_id_conflict'
					|| confirmedRetryCodes.has(responseData.code)
					|| (response.status >= 400 && response.status < 500 && response.status !== 409);
				if (canRetryWithNewRequest) {
					requestId = createRequestId();
				}
				if (feedback) {
					feedback.classList.add('is-error');
					feedback.setAttribute('role', 'alert');
					feedback.textContent = responseData.message || messages.failed || 'Invio non riuscito, riprova.';
				}
				if (firstInvalidControl) {
					firstInvalidControl.focus();
				} else if (feedback) {
					feedback.focus();
				}
			} catch {
				if (feedback) {
					feedback.classList.add('is-error');
					feedback.setAttribute('role', 'alert');
					feedback.textContent = messages.failed || 'Invio non riuscito, riprova.';
					feedback.focus();
				}
			} finally {
				isSubmitting = false;
				submitButtons.forEach((button) => {
					button.disabled = false;
					button.removeAttribute('aria-disabled');
				});
			}
		});
	};

	const initializeForms = (root = document) => {
		root.querySelectorAll('form[data-corsivo-form-form]').forEach(initializeForm);
	};

	initializeForms();
	new MutationObserver((mutations) => {
		mutations.forEach((mutation) => {
			mutation.addedNodes.forEach((node) => {
				if (node.nodeType !== Node.ELEMENT_NODE) {
					return;
				}
				if (node.matches('form[data-corsivo-form-form]')) {
					initializeForm(node);
				}
				initializeForms(node);
			});
		});
	}).observe(document.documentElement, { childList: true, subtree: true });
})();

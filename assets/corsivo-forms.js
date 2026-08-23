(() => {
	const settings = window.corsivoFormsSettings || {};
	const messages = settings.messages || {};
	const confirmedRetryCodes = new Set([
		'activity_unavailable',
		'rate_limit_unavailable',
		'submission_failed',
	]);
	const initializedForms = new WeakSet();

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
		const control = field && field.querySelector('[name]');
		if (!field || !control) {
			return null;
		}

		const errorId = `corsivo-form-error-${index}-${createRequestId()}`;
		const descriptions = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
		descriptions.push(errorId);
		field.classList.add('has-error');
		control.setAttribute('aria-invalid', 'true');
		control.setAttribute('aria-describedby', descriptions.join(' '));

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

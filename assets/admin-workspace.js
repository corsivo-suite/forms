/* Standalone workspace navigation: keep every settings control mounted. */
(() => {
	'use strict';
	const root = document.querySelector('.corsivo-admin-workspace');
	if (!root) return;
	const panels = Array.from(root.querySelectorAll('[data-workspace-panel]'));
	const links = Array.from(root.querySelectorAll('[data-workspace-link]'));
	const panelKey = (panel) => panel.dataset.workspacePanel;
	const baselines = new WeakMap();
	let submittedForm = null;
	const fingerprint = (form) => JSON.stringify(Array.from(new FormData(form)).filter(([name]) => name !== '_wp_http_referer'));
	const remember = (form) => {
		if (form && form.method.toLowerCase() === 'post' && !baselines.has(form)) baselines.set(form, fingerprint(form));
	};
	// Capture before the user's first edit, including forms mounted by React.
	['focusin', 'pointerdown', 'keydown'].forEach((type) => root.addEventListener(type, (event) => remember(event.target.closest('form')), true));
	root.querySelectorAll('form[method="post"]').forEach(remember);
	const show = (id) => {
		if (!panels.length) return;
		const selected = panels.find((panel) => panelKey(panel) === id) || panels[0];
		panels.forEach((panel) => { panel.hidden = panel !== selected; });
		root.querySelectorAll('[data-workspace-extra]').forEach((element) => { element.hidden = element.dataset.workspaceExtra !== panelKey(selected); });
		links.forEach((link) => {
			if (link.dataset.workspaceLink === panelKey(selected)) link.setAttribute('aria-current', 'page');
			else link.removeAttribute('aria-current');
		});
		// WordPress options.php restores the active section after saving.
		root.querySelectorAll('input[name="_wp_http_referer"]').forEach((input) => {
			input.value = window.location.pathname + window.location.search + '#' + panelKey(selected);
		});
	};
	links.forEach((link) => link.addEventListener('click', (event) => {
		if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		event.preventDefault();
		const id = link.dataset.workspaceLink;
		window.history.pushState(null, '', '#' + id);
		show(id);
	}));
	const restore = () => show(window.location.hash.slice(1));
	window.addEventListener('hashchange', restore);
	window.addEventListener('popstate', restore);
	root.addEventListener('invalid', (event) => {
		const form = event.target.form;
		const firstInvalid = form && Array.from(form.elements).find((field) => field.willValidate && !field.validity.valid);
		if (firstInvalid && firstInvalid !== event.target) return;
		const panel = event.target.closest('[data-workspace-panel]');
		if (panel) {
			window.history.replaceState(null, '', '#' + panelKey(panel));
			show(panelKey(panel));
		}
		// Provider fieldsets and account details can also hide invalid fields.
		for (let node = event.target.parentElement; node && node !== root; node = node.parentElement) {
			if (node.hidden) node.hidden = false;
			if (node.tagName === 'DETAILS') node.open = true;
		}
	}, true);
	root.addEventListener('submit', (event) => {
		if (!event.defaultPrevented) submittedForm = event.target;
	});
	window.addEventListener('beforeunload', (event) => {
		const dirty = Array.from(root.querySelectorAll('form')).some((form) => form !== submittedForm && baselines.has(form) && fingerprint(form) !== baselines.get(form));
		if (dirty) {
			event.preventDefault();
			event.returnValue = '';
		}
	});
	restore();
})();

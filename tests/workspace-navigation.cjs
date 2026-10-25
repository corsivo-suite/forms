const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require(process.argv[2] || 'jsdom');
const source = fs.readFileSync(path.join(__dirname, '../assets/admin-workspace.js'), 'utf8');

function fixture(hash = '') {
	const dom = new JSDOM(`<div class="corsivo-admin-workspace">
		<nav><a href="#one" data-workspace-link="one">One</a><a href="#two" data-workspace-link="two">Two</a></nav>
		<form method="post"><input name="_wp_http_referer" value="/admin.php">
		<section id="panel-one" data-workspace-panel="one"><input name="first" value="saved"></section>
		<section id="panel-two" data-workspace-panel="two"><input name="second" type="number" min="1" value="3"></section>
		<button type="submit">Save</button></form>
		<div data-workspace-extra="two">Extra action</div>
	</div>`, { url: 'https://example.test/admin.php?page=plugin' + hash, runScripts: 'outside-only' });
	dom.window.eval(source);
	return dom;
}

test('direct hash, active link and all enabled fields survive panel navigation', () => {
	const dom = fixture('#two');
	const { document, FormData, MouseEvent } = dom.window;
	assert.equal(document.getElementById('panel-one').hidden, true);
	assert.equal(document.querySelector('[data-workspace-link="two"]').getAttribute('aria-current'), 'page');
	assert.equal(document.querySelector('[data-workspace-extra]').hidden, false);
	document.querySelector('[name=second]').value = '7';
	document.querySelector('[data-workspace-link="one"]').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
	assert.equal(document.getElementById('panel-two').hidden, true);
	assert.equal(document.querySelector('[data-workspace-extra]').hidden, true);
	assert.equal(new FormData(document.querySelector('form')).get('second'), '7');
	assert.equal(document.querySelectorAll('input:disabled').length, 0);
	dom.window.close();
});

test('history restoration and unknown hash fall back without unloading controls', () => {
	const dom = fixture('#missing');
	const { document, history, Event } = dom.window;
	assert.equal(document.getElementById('panel-one').hidden, false);
	history.replaceState(null, '', '#two');
	dom.window.dispatchEvent(new Event('popstate'));
	assert.equal(document.getElementById('panel-two').hidden, false);
	dom.window.close();
});

test('a hidden invalid control reveals its panel and keeps native validation', () => {
	const dom = fixture();
	const { document, Event } = dom.window;
	const input = document.querySelector('[name=second]');
	input.value = '0';
	const invalid = new Event('invalid', { cancelable: true });
	input.dispatchEvent(invalid);
	assert.equal(document.getElementById('panel-two').hidden, false);
	assert.equal(dom.window.location.hash, '#two');
	assert.equal(invalid.defaultPrevented, false);
	dom.window.close();
});

test('unsaved edits warn, reverting clears the warning, submitting permits saving', () => {
	const dom = fixture();
	const { document, Event } = dom.window;
	const warns = () => {
		const event = new Event('beforeunload', { cancelable: true });
		dom.window.dispatchEvent(event);
		return event.defaultPrevented;
	};
	assert.equal(warns(), false);
	const input = document.querySelector('[name=first]');
	input.value = 'changed';
	assert.equal(warns(), true);
	input.value = 'saved';
	assert.equal(warns(), false);
	input.value = 'changed';
	document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
	assert.equal(warns(), false);
	dom.window.close();
});

test('forms mounted later are tracked before their first edit', () => {
	const dom = fixture();
	const { document, Event } = dom.window;
	const form = document.createElement('form');
	form.method = 'post';
	form.innerHTML = '<input name="react-control" value="saved">';
	document.querySelector('.corsivo-admin-workspace').append(form);
	form.firstChild.dispatchEvent(new Event('focusin', { bubbles: true }));
	form.firstChild.value = 'changed';
	const event = new Event('beforeunload', { cancelable: true });
	dom.window.dispatchEvent(event);
	assert.equal(event.defaultPrevented, true);
	dom.window.close();
});

test('modifier clicks retain normal browser link behavior', () => {
	const dom = fixture();
	const event = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true });
	dom.window.document.querySelector('[data-workspace-link="two"]').dispatchEvent(event);
	assert.equal(event.defaultPrevented, false);
	assert.equal(dom.window.document.getElementById('panel-one').hidden, false);
	dom.window.close();
});

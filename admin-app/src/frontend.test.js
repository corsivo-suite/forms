import { readFileSync } from 'fs';
import { join } from 'path';
import { TextEncoder, TextDecoder } from 'util';
import fixtures from '../../tests/conditions-fixtures.json';

Object.assign( globalThis, { TextEncoder, TextDecoder } );
const { JSDOM } = jest.requireActual( 'jsdom' );

const frontendSource = readFileSync(
	join( __dirname, '../../assets/corsivo-forms.js' ),
	'utf8'
);

function createForm( definitions, values = {}, nativeTypes = false ) {
	const browser = new JSDOM( '<!doctype html><html><body></body></html>', {
		url: 'https://example.test/',
		runScripts: 'outside-only',
	} );
	const { window } = browser;
	const { document } = window;
	window.CSS = { escape: ( value ) => String( value ) };
	window.corsivoFormsSettings = { endpoint: '/forms/submit' };
	const form = document.createElement( 'form' );
	form.dataset.corsivoFormForm = JSON.stringify( {
		token: 'signed-token',
		formId: 'form-identity',
		fields: definitions,
	} );
	for ( const definition of definitions ) {
		const field = document.createElement(
			definition.type === 'radio' ? 'fieldset' : 'div'
		);
		field.className = 'corsivo-form-field';
		field.dataset.corsivoFormName = definition.name;
		const choices =
			definition.type === 'radio'
				? definition.values.options.map( ( option ) => option.option )
				: [ '' ];
		for ( const [ index, choice ] of choices.entries() ) {
			const control = document.createElement( 'input' );
			control.name = definition.name;
			control.id = `${ definition.name }-${ index }`;
			control.type = nativeTypes ? definition.type : 'text';
			if (
				[ 'radio', 'checkbox', 'privacy', 'file' ].includes(
					definition.type
				)
			) {
				control.type =
					definition.type === 'privacy'
						? 'checkbox'
						: definition.type;
			}
			control.required =
				definition.type === 'privacy' ||
				Boolean( definition.values.required );
			for ( const bound of [ 'min', 'max', 'step' ] ) {
				if ( definition.values[ bound ] !== undefined ) {
					control.setAttribute( bound, definition.values[ bound ] );
				}
			}
			const raw = values[ definition.name ] ?? '';
			if ( definition.type === 'radio' ) {
				control.value = choice;
				control.checked = raw === choice;
				if ( index === 0 && raw && ! choices.includes( raw ) ) {
					control.value = raw;
					control.checked = true;
				}
			} else if ( control.type === 'checkbox' ) {
				control.value = '1';
				control.checked = [ true, 1, '1', 'on' ].includes( raw );
			} else if ( typeof raw === 'object' ) {
				Object.defineProperty( control, 'value', { get: () => raw } );
			} else if ( control.type !== 'file' ) {
				control.value = raw;
			}
			field.append( control );
		}
		form.append( field );
	}
	const feedback = document.createElement( 'p' );
	feedback.className = 'corsivo-form-form__feedback';
	feedback.tabIndex = -1;
	const button = document.createElement( 'button' );
	button.className = 'corsivo-form-form__submit';
	form.append( feedback, button );
	document.body.append( form );
	window.eval( frontendSource );
	const field = ( name ) =>
		form.querySelector( `[data-corsivo-form-name="${ name }"]` );
	const control = ( name ) => field( name ).querySelector( 'input' );
	return { browser, window, form, field, control };
}

const conditional = (
	name,
	source,
	operator = 'equal',
	value = '1',
	type = 'text'
) => ( {
	type,
	name,
	values: {
		required: true,
		conditions: [ { field: source, operator, value } ],
	},
} );

describe( 'public form conditional fields', () => {
	it.each( fixtures )(
		'matches the shared PHP fixture: $name',
		( fixture ) => {
			const { browser, form } = createForm(
				fixture.fields,
				fixture.values
			);
			const visible = [
				...form.querySelectorAll( '.corsivo-form-field' ),
			]
				.filter( ( field ) => ! field.hidden )
				.map( ( field ) => field.dataset.corsivoFormName );
			expect( visible ).toEqual( fixture.active );
			browser.window.close();
		}
	);

	it( 'resolves reverse dependencies and restores required without losing answers', () => {
		const definitions = [
			conditional( 'followup', 'details', 'is_not_empty', '' ),
			conditional( 'details', 'enabled' ),
			{ type: 'checkbox', name: 'enabled', values: {} },
			{ type: 'privacy', name: 'privacy', values: {} },
		];
		const { browser, window, form, field, control } = createForm(
			definitions,
			{ details: 'Retained answer' }
		);
		expect( field( 'details' ).hidden ).toBe( true );
		expect( control( 'details' ).required ).toBe( false );
		expect( control( 'details' ).disabled ).toBe( true );
		expect( field( 'followup' ).hidden ).toBe( true );
		expect( field( 'privacy' ).hidden ).toBe( false );
		expect( control( 'privacy' ).required ).toBe( true );
		control( 'enabled' ).checked = true;
		control( 'enabled' ).dispatchEvent(
			new window.Event( 'change', { bubbles: true } )
		);
		expect( field( 'followup' ).hidden ).toBe( false );
		expect( control( 'details' ).required ).toBe( true );
		expect( control( 'details' ).value ).toBe( 'Retained answer' );
		control( 'enabled' ).checked = false;
		form.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
		expect( new window.FormData( form ).has( 'details' ) ).toBe( false );
		expect( new window.FormData( form ).has( 'followup' ) ).toBe( false );
		browser.window.close();
	} );

	it( 'excludes hidden radios and attachments and removes their errors', () => {
		const definitions = [
			{ type: 'checkbox', name: 'enabled', values: {} },
			{
				...conditional( 'choice', 'enabled', 'equal', '1', 'radio' ),
				values: {
					...conditional( 'choice', 'enabled' ).values,
					options: [ { option: 'A' }, { option: 'B' } ],
				},
			},
			conditional( 'attachment', 'enabled', 'equal', '1', 'file' ),
		];
		const { browser, window, form, field, control } = createForm(
			definitions,
			{ enabled: '1', choice: 'A' }
		);
		field( 'choice' ).classList.add( 'has-error' );
		control( 'choice' ).setAttribute( 'aria-invalid', 'true' );
		control( 'choice' ).setAttribute(
			'aria-describedby',
			'corsivo-form-error-1'
		);
		const notice = window.document.createElement( 'p' );
		notice.className = 'corsivo-form-field__error';
		field( 'choice' ).append( notice );
		control( 'enabled' ).checked = false;
		control( 'enabled' ).dispatchEvent(
			new window.Event( 'change', { bubbles: true } )
		);
		const payload = new window.FormData( form );
		expect( payload.has( 'choice' ) ).toBe( false );
		expect( payload.has( 'attachment' ) ).toBe( false );
		expect( control( 'choice' ).hasAttribute( 'aria-invalid' ) ).toBe(
			false
		);
		expect( field( 'choice' ).classList.contains( 'has-error' ) ).toBe(
			false
		);
		expect(
			field( 'choice' ).querySelector( '.corsivo-form-field__error' )
		).toBeNull();
		control( 'enabled' ).checked = true;
		control( 'enabled' ).dispatchEvent(
			new window.Event( 'change', { bubbles: true } )
		);
		expect( control( 'choice' ).checked ).toBe( true );
		expect( control( 'choice' ).required ).toBe( true );
		expect( control( 'attachment' ).required ).toBe( true );
		browser.window.close();
	} );

	it( 'recalculates after native reset has restored values', async () => {
		const { browser, window, form, field, control } = createForm( [
			{ type: 'checkbox', name: 'enabled', values: {} },
			conditional( 'details', 'enabled' ),
		] );
		control( 'enabled' ).checked = true;
		control( 'enabled' ).dispatchEvent(
			new window.Event( 'change', { bubbles: true } )
		);
		expect( field( 'details' ).hidden ).toBe( false );
		form.reset();
		await new Promise( ( resolve ) => window.queueMicrotask( resolve ) );
		expect( field( 'details' ).hidden ).toBe( true );
		browser.window.close();
	} );

	it.each( [
		[ conditional( 'details', 'missing' ) ],
		[ conditional( 'details', 'details' ) ],
		[
			conditional( 'details', 'other' ),
			conditional( 'other', 'details' ),
		],
	] )( 'fails closed for invalid references and cycles', ( ...fields ) => {
		const { browser, form } = createForm( fields );
		expect(
			[ ...form.querySelectorAll( '.corsivo-form-field' ) ].every(
				( field ) => field.hidden
			)
		).toBe( true );
		browser.window.close();
	} );

	it( 'respects decimal increments based on the minimum with real number controls', () => {
		const { browser, window, field, control } = createForm(
			[
				{
					type: 'number',
					name: 'quantity',
					values: { min: '0.2', step: '0.5' },
				},
				conditional( 'details', 'quantity', 'greater', '0' ),
			],
			{ quantity: '0.7' },
			true
		);
		expect( field( 'details' ).hidden ).toBe( false );
		control( 'quantity' ).value = '0.5';
		control( 'quantity' ).dispatchEvent(
			new window.Event( 'input', { bubbles: true } )
		);
		expect( field( 'details' ).hidden ).toBe( true );
		browser.window.close();
	} );

	it( 'keeps cached legacy form markup without field definitions working', async () => {
		const { browser, window, form } = createForm( [] );
		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			text: async () => JSON.stringify( { sent: true, message: 'Done' } ),
		} );
		form.dispatchEvent(
			new window.Event( 'submit', { bubbles: true, cancelable: true } )
		);
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		expect( window.fetch.mock.calls[ 0 ][ 1 ].body.get( 'token' ) ).toBe(
			'signed-token'
		);
		browser.window.close();
	} );

	it( 'rechecks silent field changes before building the submitted payload', async () => {
		const { browser, window, form, control } = createForm(
			[
				{ type: 'checkbox', name: 'enabled', values: {} },
				conditional( 'details', 'enabled' ),
			],
			{ enabled: '1', details: 'Should be omitted' }
		);
		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			text: async () => JSON.stringify( { sent: true, message: 'Done' } ),
		} );
		control( 'enabled' ).checked = false;
		form.dispatchEvent(
			new window.Event( 'submit', { bubbles: true, cancelable: true } )
		);
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		expect( window.fetch.mock.calls[ 0 ][ 1 ].body.has( 'details' ) ).toBe(
			false
		);
		browser.window.close();
	} );

	it( 'associates server radio errors with every option and focuses the selected control', async () => {
		const { browser, window, form, field } = createForm(
			[
				{
					type: 'radio',
					name: 'choice',
					values: {
						required: true,
						options: [ { option: 'A' }, { option: 'B' } ],
					},
				},
			],
			{ choice: 'B' }
		);
		window.fetch = jest.fn().mockResolvedValue( {
			ok: false,
			status: 422,
			text: async () =>
				JSON.stringify( {
					code: 'invalid_fields',
					message: 'Choose again',
					data: { errors: { choice: 'Unavailable choice' } },
				} ),
		} );
		form.dispatchEvent(
			new window.Event( 'submit', { bubbles: true, cancelable: true } )
		);
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		const options = [ ...field( 'choice' ).querySelectorAll( 'input' ) ];
		expect(
			options.every(
				( option ) => option.getAttribute( 'aria-invalid' ) === 'true'
			)
		).toBe( true );
		expect(
			options.every(
				( option ) =>
					option.getAttribute( 'aria-describedby' ) ===
					options[ 0 ].getAttribute( 'aria-describedby' )
			)
		).toBe( true );
		expect( window.document.activeElement ).toBe( options[ 1 ] );
		browser.window.close();
	} );

	it( 'does not treat native bad numeric input as an empty conditional source', () => {
		const { browser, window, field, control } = createForm(
			[
				{ type: 'number', name: 'quantity', values: { step: 'any' } },
				conditional( 'details', 'quantity', 'is_empty', '' ),
			],
			{},
			true
		);
		Object.defineProperty( control( 'quantity' ), 'validity', {
			get: () => ( { badInput: true } ),
		} );
		control( 'quantity' ).dispatchEvent(
			new window.Event( 'input', { bubbles: true } )
		);
		expect( field( 'details' ).hidden ).toBe( true );
		browser.window.close();
	} );

	it.each( [ null, false, 0, '0', '' ] )(
		'fails closed for malformed falsy condition lists: %s',
		( conditions ) => {
			const { browser, field } = createForm( [
				{
					type: 'text',
					name: 'details',
					values: { conditions },
				},
			] );
			expect( field( 'details' ).hidden ).toBe( true );
			browser.window.close();
		}
	);
} );

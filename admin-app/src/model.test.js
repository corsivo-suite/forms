import {
	collectionQueryMapping,
	createActivityRequestUrl,
	createActivityUrlState,
	statusTone,
	syncActivityViewToUrl,
} from './model';

describe( 'activity collection model', () => {
	it( 'maps the controlled view to the existing PHP query inputs', () => {
		expect( collectionQueryMapping ).toEqual( {
			search: 'search',
			page: 'page',
			perPage: 'per_page',
			filters: {
				event: 'event',
				status: 'status',
			},
		} );
	} );

	it.each( [
		[
			'https://example.test/wp-json/corsivo/v1/forms/activity',
			'/wp-json/corsivo/v1/forms/activity',
			null,
		],
		[
			'https://example.test/index.php?rest_route=%2Fcorsivo%2Fv1%2Fforms%2Factivity&_locale=user',
			'/index.php',
			'/corsivo/v1/forms/activity',
		],
	] )(
		'builds the activity request for %s',
		( endpoint, pathname, route ) => {
			const url = createActivityRequestUrl(
				endpoint,
				{ page: 2, per_page: 20, search: 'Ordine #21 & A+B' },
				'https://example.test/wp-admin/admin.php'
			);

			expect( url.pathname ).toBe( pathname );
			expect( url.searchParams.get( 'rest_route' ) ).toBe( route );
			expect( url.searchParams.get( '_locale' ) ).toBe(
				route ? 'user' : null
			);
			expect( url.searchParams.get( 'page' ) ).toBe( '2' );
			expect( url.searchParams.get( 'per_page' ) ).toBe( '20' );
			expect( url.searchParams.get( 'search' ) ).toBe(
				'Ordine #21 & A+B'
			);
		}
	);

	it.each( [
		[ 'success', 'success' ],
		[ 'failed', 'error' ],
		[ 'processing', 'info' ],
		[ 'unknown', 'warning' ],
		[ 'custom', 'neutral' ],
	] )( 'maps %s to the %s tone', ( status, tone ) => {
		expect( statusTone( status ) ).toBe( tone );
	} );

	it( 'hydrates and synchronizes the legacy activity URL state', () => {
		const state = createActivityUrlState(
			'?page=corsivo-forms-settings&tab=activity&activity_event=submission&activity_status=success&activity_search=invoice&paged=3&per_page=50',
			{ events: [ 'submission' ], statuses: [ 'success' ] }
		);
		expect( state ).toEqual( {
			search: 'invoice',
			page: 3,
			perPage: 50,
			filters: [
				{ field: 'event', operator: 'is', value: 'submission' },
				{ field: 'status', operator: 'is', value: 'success' },
			],
		} );

		const replaceState = jest.fn();
		syncActivityViewToUrl(
			state,
			{
				pathname: '/wp-admin/tools.php',
				search: '?page=corsivo-forms-settings&tab=activity',
				hash: '#results',
			},
			{ replaceState }
		);
		expect( replaceState.mock.calls[ 0 ][ 2 ] ).toBe(
			'/wp-admin/tools.php?page=corsivo-forms-settings&tab=activity&activity_search=invoice&activity_event=submission&activity_status=success&paged=3&per_page=50#results'
		);
	} );
} );

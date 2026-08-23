import {
	collectionQueryMapping,
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

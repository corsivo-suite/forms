export const statusTone = ( status ) => {
	if ( [ 'success', 'sent', 'stored', 'completed' ].includes( status ) ) {
		return 'success';
	}
	if ( [ 'failed', 'error', 'rejected' ].includes( status ) ) {
		return 'error';
	}
	if ( [ 'processing', 'pending' ].includes( status ) ) {
		return 'info';
	}
	if ( [ 'unknown', 'warning' ].includes( status ) ) {
		return 'warning';
	}

	return 'neutral';
};

export const collectionQueryMapping = {
	search: 'search',
	page: 'page',
	perPage: 'per_page',
	filters: {
		event: 'event',
		status: 'status',
	},
};

const getPositiveInteger = ( value, fallback ) => {
	const number = Number( value );
	return Number.isInteger( number ) && number > 0 ? number : fallback;
};

const createFilter = ( field, value ) => ( { field, operator: 'is', value } );

const getFilterValue = ( view, field ) =>
	view.filters?.find( ( filter ) => filter.field === field )?.value || '';

const setOptionalParameter = ( parameters, key, value, fallback = '' ) => {
	if ( value === fallback || value === '' || value === null ) {
		parameters.delete( key );
		return;
	}

	parameters.set( key, String( value ) );
};

export const createActivityUrlState = (
	search,
	{ events = [], statuses = [] } = {}
) => {
	const parameters = new URLSearchParams( search );
	const filters = [];
	const perPage = getPositiveInteger( parameters.get( 'per_page' ), 20 );
	const event = parameters.get( 'activity_event' ) || '';
	const status = parameters.get( 'activity_status' ) || '';

	if ( events.includes( event ) ) {
		filters.push( createFilter( 'event', event ) );
	}
	if ( statuses.includes( status ) ) {
		filters.push( createFilter( 'status', status ) );
	}

	return {
		search: parameters.get( 'activity_search' ) || '',
		page: getPositiveInteger( parameters.get( 'paged' ), 1 ),
		perPage: [ 10, 20, 50, 100 ].includes( perPage ) ? perPage : 20,
		filters,
	};
};

export const syncActivityViewToUrl = ( view, location, history ) => {
	const parameters = new URLSearchParams( location.search );

	setOptionalParameter( parameters, 'activity_search', view.search || '' );
	setOptionalParameter(
		parameters,
		'activity_event',
		getFilterValue( view, 'event' )
	);
	setOptionalParameter(
		parameters,
		'activity_status',
		getFilterValue( view, 'status' )
	);
	setOptionalParameter( parameters, 'paged', view.page, 1 );
	setOptionalParameter( parameters, 'per_page', view.perPage, 20 );

	const query = parameters.toString();
	history.replaceState(
		history.state ?? null,
		'',
		`${ location.pathname }${ query ? `?${ query }` : '' }${
			location.hash || ''
		}`
	);
};

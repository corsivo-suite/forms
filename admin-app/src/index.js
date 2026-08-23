import { Button } from '@wordpress/components';
import {
	createRoot,
	useCallback,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { rotateRight, trash } from '@wordpress/icons';
import {
	DataCollection,
	normalizeWordPressRestResponse,
	useDataCollectionSource,
} from '@corsivo-suite/wp-admin-dataviews';
import { StatusBadge } from '@corsivo-suite/wp-admin-ui/status-badge';
import '@corsivo-suite/wp-admin-dataviews/styles.css';
import '@corsivo-suite/wp-admin-ui/styles.css';
import './style.css';
import {
	collectionQueryMapping,
	createActivityUrlState,
	statusTone,
	syncActivityViewToUrl,
} from './model';

const config = window.corsivoFormsActivityConfig || {};

const getOptionLabel = ( options, value ) =>
	options.find( ( option ) => option.value === value )?.label || value;

function ActivityStatus( { item } ) {
	return (
		<StatusBadge tone={ statusTone( item.status ) }>
			{ getOptionLabel( config.statuses || [], item.status ) }
		</StatusBadge>
	);
}

function ClearActivityButton() {
	if ( ! config.clear?.action || ! config.clear?.nonce ) {
		return null;
	}

	return (
		<form method="post" action={ config.clear.action }>
			<input
				type="hidden"
				name="action"
				value="corsivo_forms_clear_activity"
			/>
			<input type="hidden" name="_wpnonce" value={ config.clear.nonce } />
			<Button
				type="submit"
				icon={ trash }
				label="Pulisci attività"
				showTooltip
				isDestructive
			/>
		</form>
	);
}

function ActivityCollection() {
	const [ view, setView ] = useState( () => ( {
		...createActivityUrlState( window.location.search, {
			events: ( config.events || [] ).map( ( option ) => option.value ),
			statuses: ( config.statuses || [] ).map(
				( option ) => option.value
			),
		} ),
		type: 'table',
		titleField: 'date',
		fields: [
			'event',
			'status',
			'form',
			'storageStatus',
			'notificationStatus',
			'detail',
			'requestId',
		],
		layout: { density: 'comfortable' },
	} ) );

	useEffect( () => {
		syncActivityViewToUrl( view, window.location, window.history );
	}, [ view ] );

	const loadItems = useCallback( async ( query, { signal } = {} ) => {
		const parameters = new URLSearchParams( query );
		const response = await fetch( `${ config.endpoint }?${ parameters }`, {
			signal,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce },
		} );

		return normalizeWordPressRestResponse( response );
	}, [] );

	const { items, paginationInfo, loading, error, refresh } =
		useDataCollectionSource( {
			view,
			loadItems,
			queryMapping: collectionQueryMapping,
			debounceMs: 250,
		} );

	const fields = useMemo(
		() => [
			{
				id: 'date',
				label: 'Data',
				type: 'text',
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
			},
			{
				id: 'event',
				label: 'Evento',
				type: 'text',
				enableSorting: false,
				elements: config.events || [],
				filterBy: { operators: [ 'is' ], isPrimary: true },
				enableGlobalSearch: true,
				render: ( { item } ) =>
					getOptionLabel( config.events || [], item.event ),
			},
			{
				id: 'status',
				label: 'Stato',
				type: 'text',
				enableSorting: false,
				elements: config.statuses || [],
				filterBy: { operators: [ 'is' ], isPrimary: true },
				enableGlobalSearch: false,
				render: ( { item } ) => <ActivityStatus item={ item } />,
			},
			{
				id: 'form',
				label: 'Modulo',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
			},
			{
				id: 'storageStatus',
				label: 'Archivio',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: false,
			},
			{
				id: 'notificationStatus',
				label: 'Notifica',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: false,
			},
			{
				id: 'detail',
				label: 'Dettaglio',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
				render: ( { item } ) => (
					<span className="corsivo-forms-activity__detail">
						{ item.detail }
					</span>
				),
			},
			{
				id: 'requestId',
				label: 'Richiesta',
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
				render: ( { item } ) => (
					<code className="corsivo-forms-activity__request">
						{ item.requestId }
					</code>
				),
			},
		],
		[]
	);

	const summary = (
		<div className="corsivo-forms-activity__summary">
			<strong>{ paginationInfo.totalItems }</strong>
			<span>attività</span>
		</div>
	);
	const actions = (
		<div className="corsivo-forms-activity__actions">
			<Button
				icon={ rotateRight }
				label="Aggiorna attività"
				showTooltip
				disabled={ loading }
				onClick={ refresh }
			/>
			<ClearActivityButton />
		</div>
	);

	return (
		<DataCollection
			items={ items }
			fields={ fields }
			view={ view }
			onChangeView={ setView }
			paginationInfo={ paginationInfo }
			perPageSizes={ [ 10, 20, 50, 100 ] }
			loading={ loading }
			error={ error }
			empty={
				<div className="corsivo-forms-activity__empty">
					<strong>Nessuna attività trovata</strong>
					<span>Modifica la ricerca o rimuovi i filtri attivi.</span>
				</div>
			}
			search
			searchLabel="Cerca per modulo, dettaglio o richiesta"
			getItemId={ ( item ) => item.id }
			layouts={ [ 'table' ] }
			toolbarSummary={ summary }
			toolbarActions={ actions }
		/>
	);
}

const root = document.getElementById( 'corsivo-forms-activity-app' );

if ( root && config.endpoint ) {
	createRoot( root ).render( <ActivityCollection /> );
}

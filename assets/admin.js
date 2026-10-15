( function ( wp ) {
	'use strict';

	const { createElement: el, useState, useEffect, createRoot, render } = wp.element;
	const {
		Card, CardHeader, CardBody, CardFooter, Button, Notice, Spinner,
		Flex, FlexItem, FlexBlock,
	} = wp.components;
	const ProgressBar = wp.components.ProgressBar || wp.components.__experimentalProgressBar;
	const apiFetch = wp.apiFetch;
	const { __, sprintf } = wp.i18n;
	const API = '/lytera-ua-lat/v1/';
	const TD = 'lytera-ua-lat';

	function Stat( { label, value } ) {
		return el( FlexItem, { className: 'uatr-stat' },
			el( 'div', { className: 'uatr-stat__value' }, value === null ? el( Spinner ) : value ),
			el( 'div', { className: 'uatr-stat__label' }, label )
		);
	}

	function PreviewTable( { items } ) {
		if ( ! items.length ) {
			return el( 'p', null, __( 'Nothing to convert.', TD ) );
		}
		return el( 'table', { className: 'widefat striped uatr-table' },
			el( 'thead', null, el( 'tr', null,
				el( 'th', null, __( 'Title', TD ) ),
				el( 'th', null, __( 'Type', TD ) ),
				el( 'th', null, __( 'Current slug', TD ) ),
				el( 'th', null, __( 'New slug', TD ) )
			) ),
			el( 'tbody', null, items.map( ( item ) =>
				el( 'tr', { key: item.kindKey + item.id },
					el( 'td', null, item.edit ? el( 'a', { href: item.edit }, item.title || '#' + item.id ) : item.title ),
					el( 'td', null, item.kind ),
					el( 'td', null, el( 'code', null, item.old ) ),
					el( 'td', null, el( 'code', { className: 'uatr-new' }, item.new ) )
				)
			) )
		);
	}

	function App() {
		const [ status, setStatus ] = useState( { post: null, term: null } );
		const [ items, setItems ] = useState( [] );
		const [ cursor, setCursor ] = useState( { type: 'post', after: 0, done: false } );
		const [ loading, setLoading ] = useState( false );
		const [ progress, setProgress ] = useState( null );
		const [ notice, setNotice ] = useState( null );

		const loadStatus = () => apiFetch( { path: API + 'status' } ).then( setStatus );

		useEffect( () => {
			loadStatus().catch( ( e ) => setNotice( { status: 'error', text: e.message } ) );
		}, [] );

		const loadMore = async () => {
			setLoading( true );
			try {
				let { type, after } = cursor;
				let found = [];
				let done = false;
				// Fill one screen: skip empty batches.
				while ( ! done && found.length < 50 ) {
					const res = await apiFetch( { path: API + 'preview?type=' + type + '&after=' + after } );
					found = found.concat( res.items.map( ( i ) => ( { ...i, kindKey: type } ) ) );
					after = res.next;
					if ( res.done ) {
						if ( 'post' === type ) {
							type = 'term';
							after = 0;
						} else {
							done = true;
						}
					}
				}
				setItems( ( prev ) => prev.concat( found ) );
				setCursor( { type, after, done } );
			} catch ( e ) {
				setNotice( { status: 'error', text: e.message } );
			}
			setLoading( false );
		};

		const convertAll = async () => {
			const total = ( status.post || 0 ) + ( status.term || 0 );
			let processed = 0;
			let converted = 0;
			setNotice( null );
			setProgress( 0 );
			try {
				for ( const type of [ 'post', 'term' ] ) {
					let after = 0;
					let done = false;
					while ( ! done ) {
						const res = await apiFetch( { path: API + 'convert', method: 'POST', data: { type, after } } );
						converted += res.converted;
						processed += res.converted;
						after = res.next;
						done = res.done;
						setProgress( total ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 100 );
					}
				}
				setNotice( {
					status: 'success',
					/* translators: %d: number of converted slugs. */
					text: sprintf( __( 'Converted slugs: %d. Old addresses redirect to the new ones (301).', TD ), converted ),
				} );
			} catch ( e ) {
				setNotice( { status: 'error', text: e.message } );
			}
			setProgress( null );
			setItems( [] );
			setCursor( { type: 'post', after: 0, done: false } );
			loadStatus();
		};

		const total = ( status.post || 0 ) + ( status.term || 0 );
		const busy = progress !== null;
		const ready = status.post !== null;

		return el( 'div', { className: 'uatr' },
			notice && el( Notice, { status: notice.status, onRemove: () => setNotice( null ) }, notice.text ),
			el( Card, null,
				el( CardHeader, null,
					el( 'strong', null, __( 'Existing slugs with Cyrillic characters', TD ) )
				),
				el( CardBody, null,
					el( Flex, { justify: 'flex-start', gap: 8 },
						el( Stat, { label: __( 'Posts, pages, media', TD ), value: status.post } ),
						el( Stat, { label: __( 'Categories, tags, terms', TD ), value: status.term } )
					),
					el( 'p', { className: 'uatr-hint' },
						__( 'New slugs are transliterated automatically. Existing ones change only after you click Convert. Old addresses keep working with a 301 redirect.', TD )
					),
					busy && el( 'div', { className: 'uatr-progress' },
						el( ProgressBar, { value: progress } ),
						el( 'span', null, progress + '%' )
					)
				),
				el( CardFooter, null,
					el( Flex, null,
						el( FlexBlock, null,
							el( Button, {
								variant: 'secondary',
								onClick: loadMore,
								isBusy: loading,
								disabled: ! ready || ! total || loading || busy || cursor.done,
							}, items.length ? __( 'Show more', TD ) : __( 'Preview changes', TD ) )
						),
						el( FlexItem, null,
							el( Button, {
								variant: 'primary',
								onClick: convertAll,
								isBusy: busy,
								disabled: ! ready || ! total || busy,
							}, __( 'Convert all', TD ) )
						)
					)
				)
			),
			( items.length > 0 || cursor.done ) && el( Card, { className: 'uatr-preview' },
				el( CardBody, null, el( PreviewTable, { items } ) )
			)
		);
	}

	const style = document.createElement( 'style' );
	style.textContent =
		'.uatr{max-width:960px;margin-top:16px;display:grid;gap:16px}' +
		'.uatr .components-notice{margin:0}' +
		'.uatr-stat{min-width:180px;padding:16px 20px;border:1px solid #e0e0e0;border-radius:8px}' +
		'.uatr-stat__value{font-size:28px;font-weight:600;line-height:1.2}' +
		'.uatr-stat__label{color:#757575;margin-top:4px}' +
		'.uatr-hint{color:#757575;margin:16px 0 0}' +
		'.uatr-progress{display:flex;align-items:center;gap:12px;margin-top:16px}' +
		'.uatr-progress .components-progress-bar{flex:1;max-width:none}' +
		'.uatr .widefat{border:0;box-shadow:none}.uatr-table code{background:none;padding:0;word-break:break-all}' +
		'.uatr-new{color:#007017}';
	document.head.appendChild( style );

	const root = document.getElementById( 'uatr-app' );
	if ( root ) {
		if ( createRoot ) {
			createRoot( root ).render( el( App ) );
		} else {
			render( el( App ), root );
		}
	}
}( window.wp ) );

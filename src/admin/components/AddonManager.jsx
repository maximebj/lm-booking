/**
 * AddonManager — admin component for selecting add-on products.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function AddonManager( { initial, inputId } ) {
	const [ addons, setAddons ] = useState( Array.isArray( initial ) ? initial : [] );
	const [ searchTerm, setSearchTerm ] = useState( '' );
	const [ searchResults, setSearchResults ] = useState( [] );
	const [ isSearching, setIsSearching ] = useState( false );

	// Debounced product search.
	useEffect( () => {
		if ( searchTerm.length < 2 ) {
			setSearchResults( [] );
			return;
		}

		const timer = setTimeout( async () => {
			setIsSearching( true );
			try {
				const results = await apiFetch( {
					path: `/lm-booking/v1/products/search?term=${ encodeURIComponent( searchTerm ) }`,
				} );
				// Filter out products already added.
				const existingIds = addons.map( ( a ) => a.product_id );
				setSearchResults(
					results.filter( ( r ) => ! existingIds.includes( r.id ) )
				);
			} catch {
				setSearchResults( [] );
			}
			setIsSearching( false );
		}, 300 );

		return () => clearTimeout( timer );
	}, [ searchTerm, addons ] );

	const addAddon = useCallback(
		( product ) => {
			setAddons( ( prev ) => [
				...prev,
				{
					product_id: product.id,
					name: product.name,
					type: 'optional',
					max_qty: 1,
					price_override: null,
					price: product.price,
					image: product.image,
				},
			] );
			setSearchTerm( '' );
			setSearchResults( [] );
		},
		[]
	);

	const updateAddon = ( index, field, value ) => {
		setAddons( ( prev ) =>
			prev.map( ( item, i ) =>
				i === index ? { ...item, [ field ]: value } : item
			)
		);
	};

	const removeAddon = ( index ) => {
		setAddons( ( prev ) => prev.filter( ( _, i ) => i !== index ) );
	};

	return (
		<div className="lm-booking-addon-manager" style={ { padding: '0 12px 12px' } }>
			<input
				type="hidden"
				name={ inputId }
				id={ inputId }
				value={ JSON.stringify( addons ) }
			/>
			{ /* Existing add-ons */ }
			{ addons.length > 0 && (
				<table className="widefat" style={ { marginBottom: 12 } }>
					<thead>
						<tr>
							<th>Produit</th>
							<th style={ { width: 120 } }>Type</th>
							<th style={ { width: 80 } }>Qté max</th>
							<th style={ { width: 120 } }>Prix custom</th>
							<th style={ { width: 60 } }></th>
						</tr>
					</thead>
					<tbody>
						{ addons.map( ( addon, index ) => (
							<tr key={ addon.product_id }>
								<td>
									<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
										{ addon.image && (
											<img
												src={ addon.image }
												alt=""
												style={ { width: 32, height: 32, objectFit: 'cover', borderRadius: 4 } }
											/>
										) }
										<span>
											<strong>{ addon.name || `#${ addon.product_id }` }</strong>
											{ addon.price != null && (
												<span style={ { color: '#888', marginLeft: 4 } }>
													({ addon.price }€)
												</span>
											) }
										</span>
									</div>
								</td>
								<td>
									<select
										value={ addon.type }
										onChange={ ( e ) =>
											updateAddon( index, 'type', e.target.value )
										}
										style={ { width: '100%' } }
									>
										<option value="optional">Optionnel</option>
										<option value="included">Inclus</option>
									</select>
								</td>
								<td>
									<input
										type="number"
										min="1"
										value={ addon.max_qty }
										onChange={ ( e ) =>
											updateAddon( index, 'max_qty', parseInt( e.target.value, 10 ) || 1 )
										}
										style={ { width: '100%' } }
									/>
								</td>
								<td>
									<input
										type="number"
										step="0.01"
										min="0"
										value={ addon.price_override ?? '' }
										onChange={ ( e ) =>
											updateAddon(
												index,
												'price_override',
												e.target.value === '' ? null : parseFloat( e.target.value )
											)
										}
										placeholder="—"
										style={ { width: '100%' } }
									/>
								</td>
								<td>
									<button
										type="button"
										className="button"
										onClick={ () => removeAddon( index ) }
										style={ { color: '#a00' } }
									>
										✕
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ /* Search for new add-on */ }
			<div>
				<input
					type="text"
					value={ searchTerm }
					onChange={ ( e ) => setSearchTerm( e.target.value ) }
					placeholder="Rechercher un produit simple à ajouter…"
					className="regular-text"
					style={ { width: '100%', maxWidth: 400 } }
				/>

				{ isSearching && (
					<span style={ { marginLeft: 8, color: '#888' } }>Recherche…</span>
				) }

				{ searchResults.length > 0 && (
					<div
						style={ {
							maxWidth: 400,
							background: '#fff',
							border: '1px solid #ddd',
							borderTop: 'none',
							maxHeight: 200,
							overflowY: 'auto',
							boxShadow: '0 2px 4px rgba(0,0,0,.1)',
						} }
					>
						{ searchResults.map( ( product ) => (
							<div
								key={ product.id }
								onClick={ () => addAddon( product ) }
								style={ {
									padding: '8px 12px',
									cursor: 'pointer',
									display: 'flex',
									alignItems: 'center',
									gap: 8,
									borderBottom: '1px solid #f0f0f0',
								} }
								onMouseEnter={ ( e ) => {
									e.currentTarget.style.background = '#f7f7f7';
								} }
								onMouseLeave={ ( e ) => {
									e.currentTarget.style.background = '#fff';
								} }
								role="button"
								tabIndex={ 0 }
								onKeyDown={ ( e ) => {
									if ( e.key === 'Enter' ) addAddon( product );
								} }
							>
								{ product.image && (
									<img
										src={ product.image }
										alt=""
										style={ { width: 24, height: 24, objectFit: 'cover', borderRadius: 2 } }
									/>
								) }
								<span>{ product.name }</span>
								<span style={ { color: '#888', marginLeft: 'auto' } }>
									{ product.price }€
								</span>
							</div>
						) ) }
					</div>
				) }
			</div>
		</div>
	);
}

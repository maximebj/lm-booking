/**
 * DateOverrides — admin component for managing date exceptions (holidays, special hours).
 */
import { useState, useEffect } from '@wordpress/element';

export default function DateOverrides( { initial, inputId } ) {
	const [ overrides, setOverrides ] = useState( () => {
		// Convert object keyed by date to array for easier manipulation.
		if ( typeof initial === 'object' && ! Array.isArray( initial ) ) {
			return Object.entries( initial ).map( ( [ date, config ] ) => ( {
				date,
				...config,
			} ) );
		}
		return [];
	} );

	// Sync to hidden input as object keyed by date.
	useEffect( () => {
		const input = document.getElementById( inputId );
		if ( input ) {
			const obj = {};
			overrides.forEach( ( o ) => {
				if ( o.date ) {
					obj[ o.date ] = {
						type: o.type || 'closed',
						start: o.start || '',
						end: o.end || '',
					};
				}
			} );
			input.value = JSON.stringify( obj );
		}
	}, [ overrides, inputId ] );

	const addOverride = () => {
		setOverrides( ( prev ) => [
			...prev,
			{ date: '', type: 'closed', start: '', end: '' },
		] );
	};

	const updateOverride = ( index, field, value ) => {
		setOverrides( ( prev ) =>
			prev.map( ( item, i ) =>
				i === index ? { ...item, [ field ]: value } : item
			)
		);
	};

	const removeOverride = ( index ) => {
		setOverrides( ( prev ) => prev.filter( ( _, i ) => i !== index ) );
	};

	return (
		<div className="lm-booking-date-overrides" style={ { padding: '0 12px 12px' } }>
			{ overrides.map( ( override, index ) => (
				<div
					key={ index }
					style={ {
						display: 'flex',
						gap: 8,
						alignItems: 'center',
						marginBottom: 8,
						flexWrap: 'wrap',
					} }
				>
					<input
						type="date"
						value={ override.date }
						onChange={ ( e ) => updateOverride( index, 'date', e.target.value ) }
						style={ { width: 160 } }
					/>

					<select
						value={ override.type }
						onChange={ ( e ) => updateOverride( index, 'type', e.target.value ) }
						style={ { width: 160 } }
					>
						<option value="closed">Fermé</option>
						<option value="special">Horaires spéciaux</option>
					</select>

					{ override.type === 'special' && (
						<>
							<input
								type="time"
								value={ override.start }
								onChange={ ( e ) =>
									updateOverride( index, 'start', e.target.value )
								}
								placeholder="Début"
								style={ { width: 110 } }
							/>
							<span>–</span>
							<input
								type="time"
								value={ override.end }
								onChange={ ( e ) =>
									updateOverride( index, 'end', e.target.value )
								}
								placeholder="Fin"
								style={ { width: 110 } }
							/>
						</>
					) }

					<button
						type="button"
						className="button"
						onClick={ () => removeOverride( index ) }
						style={ { color: '#a00' } }
					>
						✕
					</button>
				</div>
			) ) }

			<button type="button" className="button" onClick={ addOverride }>
				+ Ajouter une exception
			</button>
		</div>
	);
}

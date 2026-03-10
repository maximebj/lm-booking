/**
 * WeeklySchedule — admin component for configuring weekly opening hours.
 */
import { useState, useEffect } from '@wordpress/element';

const DAYS = [
	{ key: '0', label: 'Lundi' },
	{ key: '1', label: 'Mardi' },
	{ key: '2', label: 'Mercredi' },
	{ key: '3', label: 'Jeudi' },
	{ key: '4', label: 'Vendredi' },
	{ key: '5', label: 'Samedi' },
	{ key: '6', label: 'Dimanche' },
];

const DEFAULT_DAY = { enabled: false, start: '09:00', end: '18:00' };

export default function WeeklySchedule( { initial, inputId } ) {
	const [ schedule, setSchedule ] = useState( () => {
		const s = {};
		DAYS.forEach( ( { key } ) => {
			s[ key ] = initial[ key ] || { ...DEFAULT_DAY };
		} );
		return s;
	} );

	// Sync to hidden input.
	useEffect( () => {
		const input = document.getElementById( inputId );
		if ( input ) {
			input.value = JSON.stringify( schedule );
		}
	}, [ schedule, inputId ] );

	const updateDay = ( key, field, value ) => {
		setSchedule( ( prev ) => ( {
			...prev,
			[ key ]: { ...prev[ key ], [ field ]: value },
		} ) );
	};

	return (
		<div className="lm-booking-weekly-schedule" style={ { padding: '0 12px 12px' } }>
			<table className="widefat" style={ { maxWidth: 600 } }>
				<thead>
					<tr>
						<th>Jour</th>
						<th style={ { width: 60, textAlign: 'center' } }>Ouvert</th>
						<th style={ { width: 120 } }>Début</th>
						<th style={ { width: 120 } }>Fin</th>
					</tr>
				</thead>
				<tbody>
					{ DAYS.map( ( { key, label } ) => {
						const day = schedule[ key ];
						return (
							<tr key={ key }>
								<td><strong>{ label }</strong></td>
								<td style={ { textAlign: 'center' } }>
									<input
										type="checkbox"
										checked={ day.enabled }
										onChange={ ( e ) => updateDay( key, 'enabled', e.target.checked ) }
									/>
								</td>
								<td>
									<input
										type="time"
										value={ day.start }
										disabled={ ! day.enabled }
										onChange={ ( e ) => updateDay( key, 'start', e.target.value ) }
										style={ { width: '100%' } }
									/>
								</td>
								<td>
									<input
										type="time"
										value={ day.end }
										disabled={ ! day.enabled }
										onChange={ ( e ) => updateDay( key, 'end', e.target.value ) }
										style={ { width: '100%' } }
									/>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}

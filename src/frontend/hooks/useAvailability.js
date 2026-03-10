/**
 * useAvailability — fetches available time slots from the REST API.
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function useAvailability( productId, date ) {
	const [ slots, setSlots ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( ! productId || ! date ) {
			setSlots( [] );
			return;
		}

		let cancelled = false;
		setLoading( true );
		setError( null );

		apiFetch( {
			path: `/lm-booking/v1/availability?product_id=${ productId }&date=${ date }`,
		} )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setSlots( data.slots || [] );
					setLoading( false );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setError( err.message || 'Erreur' );
					setSlots( [] );
					setLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ productId, date ] );

	return { slots, loading, error };
}

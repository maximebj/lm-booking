/**
 * Frontend entry point — mounts the BookingForm on product pages.
 */
import { createRoot } from '@wordpress/element';
import BookingForm from './components/BookingForm';

document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'lm-booking-form' );
	if ( ! container ) {
		return;
	}

	const root = createRoot( container );
	root.render( <BookingForm /> );
} );

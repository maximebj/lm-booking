/**
 * BookingSummary — displays a recap before adding to cart.
 */

export default function BookingSummary( {
	date,
	slot,
	addons,
	selectedAddons,
	total,
	currency,
	i18n,
} ) {
	if ( ! slot ) {
		return null;
	}

	// Format date nicely.
	const dateObj = new Date( date + 'T00:00:00' );
	const formattedDate = dateObj.toLocaleDateString( 'fr-FR', {
		weekday: 'long',
		year: 'numeric',
		month: 'long',
		day: 'numeric',
	} );

	// Collect selected add-on details.
	const addonLines = [];
	if ( addons?.length > 0 ) {
		addons.forEach( ( addon ) => {
			const qty = selectedAddons[ addon.product_id ] || 0;
			const isIncluded = addon.type === 'included';

			if ( qty > 0 || isIncluded ) {
				addonLines.push( {
					name: addon.name,
					qty: isIncluded ? 1 : qty,
					price: addon.price,
					isIncluded,
					subtotal: isIncluded ? 0 : addon.price * qty,
				} );
			}
		} );
	}

	return (
		<div className="lm-booking-summary">
			<h3 className="lm-booking-summary-title">
				{ i18n?.summary || 'Résumé' }
			</h3>

			<div className="lm-booking-summary-row">
				<span className="lm-booking-summary-label">Date</span>
				<span className="lm-booking-summary-value">{ formattedDate }</span>
			</div>

			<div className="lm-booking-summary-row">
				<span className="lm-booking-summary-label">
					{ i18n?.selectSlot || 'Créneau' }
				</span>
				<span className="lm-booking-summary-value">
					{ slot.start } – { slot.end }
				</span>
			</div>

			<div className="lm-booking-summary-row">
				<span className="lm-booking-summary-label">Réservation</span>
				<span className="lm-booking-summary-value">
					{ slot.price.toFixed( 2 ) }{ currency }
				</span>
			</div>

			{ addonLines.map( ( line ) => (
				<div key={ line.name } className="lm-booking-summary-row addon">
					<span className="lm-booking-summary-label">
						{ line.isIncluded ? '✓ ' : '' }
						{ line.name }
						{ line.qty > 1 ? ` ×${ line.qty }` : '' }
					</span>
					<span className="lm-booking-summary-value">
						{ line.isIncluded
							? ( i18n?.included || 'Inclus' )
							: `${ line.subtotal.toFixed( 2 ) }${ currency }` }
					</span>
				</div>
			) ) }

			<div className="lm-booking-summary-row total">
				<span className="lm-booking-summary-label">
					<strong>{ i18n?.total || 'Total' }</strong>
				</span>
				<span className="lm-booking-summary-value">
					<strong>{ total.toFixed( 2 ) }{ currency }</strong>
				</span>
			</div>
		</div>
	);
}

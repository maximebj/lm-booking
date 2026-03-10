/**
 * TimeSlotGrid — displays available time slots for a selected date.
 */

export default function TimeSlotGrid( {
	slots,
	loading,
	error,
	selectedSlot,
	onSelect,
	currency,
	i18n,
} ) {
	if ( loading ) {
		return (
			<div className="lm-booking-slots-loading">
				{ i18n?.loading || 'Chargement…' }
			</div>
		);
	}

	if ( error ) {
		return <div className="lm-booking-slots-error">{ error }</div>;
	}

	if ( ! slots || slots.length === 0 ) {
		return (
			<div className="lm-booking-slots-empty">
				{ i18n?.noSlots || 'Aucun créneau disponible pour cette date.' }
			</div>
		);
	}

	return (
		<div className="lm-booking-slots-grid">
			{ slots.map( ( slot ) => {
				const isSelected =
					selectedSlot?.start_utc === slot.start_utc &&
					selectedSlot?.end_utc === slot.end_utc;
				const isAvailable = slot.available > 0;

				const classes = [
					'lm-booking-slot',
					isAvailable ? 'available' : 'unavailable',
					isSelected && 'selected',
				]
					.filter( Boolean )
					.join( ' ' );

				return (
					<button
						key={ slot.start_utc }
						type="button"
						className={ classes }
						disabled={ ! isAvailable }
						onClick={ () => onSelect( slot ) }
						aria-pressed={ isSelected }
					>
						<span className="lm-booking-slot-time">
							{ slot.start } – { slot.end }
						</span>
						<span className="lm-booking-slot-price">
							{ slot.price.toFixed( 2 ) }{ currency }
						</span>
						<span className="lm-booking-slot-status">
							{ isAvailable
								? `${ slot.available } ${ i18n?.available || 'dispo.' }`
								: ( i18n?.unavailable || 'complet' ) }
						</span>
					</button>
				);
			} ) }
		</div>
	);
}

/**
 * BookingForm — main front-end component for the booking flow.
 * Steps: 1. Pick date → 2. Pick slot → 3. Pick add-ons → 4. Summary → Add to cart
 */
import { useState, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import DatePicker from './DatePicker';
import TimeSlotGrid from './TimeSlotGrid';
import AddonSelector from './AddonSelector';
import BookingSummary from './BookingSummary';
import useAvailability from '../hooks/useAvailability';

/* global lmBookingData */
const {
	productId,
	price: basePrice,
	currency,
	addons: addonConfigs,
	restUrl,
	nonce,
	cartUrl,
	i18n,
} = window.lmBookingData || {};

export default function BookingForm() {
	const [ selectedDate, setSelectedDate ] = useState( '' );
	const [ selectedSlot, setSelectedSlot ] = useState( null );
	const [ selectedAddons, setSelectedAddons ] = useState( {} ); // { product_id: quantity }
	const [ step, setStep ] = useState( 1 ); // 1=date, 2=slot, 3=addons/summary
	const [ submitting, setSubmitting ] = useState( false );
	const [ message, setMessage ] = useState( null );

	const { slots, loading, error } = useAvailability( productId, selectedDate );

	const handleDateSelect = useCallback( ( date ) => {
		setSelectedDate( date );
		setSelectedSlot( null );
		setStep( 2 );
		setMessage( null );
	}, [] );

	const handleSlotSelect = useCallback( ( slot ) => {
		setSelectedSlot( slot );
		setStep( 3 );
		setMessage( null );
	}, [] );

	const handleAddonChange = useCallback( ( addonProductId, quantity ) => {
		setSelectedAddons( ( prev ) => {
			const next = { ...prev };
			if ( quantity <= 0 ) {
				delete next[ addonProductId ];
			} else {
				next[ addonProductId ] = quantity;
			}
			return next;
		} );
	}, [] );

	const handleBackToDate = useCallback( () => {
		setStep( 1 );
		setSelectedSlot( null );
		setMessage( null );
	}, [] );

	const handleBackToSlots = useCallback( () => {
		setStep( 2 );
		setMessage( null );
	}, [] );

	const computeTotal = useCallback( () => {
		const slotPrice = selectedSlot?.price ?? basePrice;
		let total = slotPrice;

		if ( addonConfigs?.length > 0 ) {
			Object.entries( selectedAddons ).forEach( ( [ pid, qty ] ) => {
				const config = addonConfigs.find(
					( a ) => String( a.product_id ) === String( pid )
				);
				if ( config ) {
					total += config.price * qty;
				}
			} );
		}

		return total;
	}, [ selectedSlot, selectedAddons, addonConfigs, basePrice ] );

	const handleSubmit = useCallback( async () => {
		if ( ! selectedSlot || submitting ) {
			return;
		}

		setSubmitting( true );
		setMessage( null );

		// Build add-ons array.
		const addonsPayload = Object.entries( selectedAddons ).map(
			( [ pid, qty ] ) => ( {
				product_id: parseInt( pid, 10 ),
				quantity: qty,
			} )
		);

		// Also add "included" add-ons automatically.
		if ( addonConfigs?.length > 0 ) {
			addonConfigs
				.filter( ( a ) => a.type === 'included' )
				.forEach( ( a ) => {
					if (
						! addonsPayload.find(
							( p ) => p.product_id === a.product_id
						)
					) {
						addonsPayload.push( {
							product_id: a.product_id,
							quantity: 1,
						} );
					}
				} );
		}

		try {
			const response = await apiFetch( {
				path: '/lm-booking/v1/add-to-cart',
				method: 'POST',
				data: {
					product_id: productId,
					start_utc: selectedSlot.start_utc,
					end_utc: selectedSlot.end_utc,
					addons: addonsPayload,
				},
			} );

			if ( response.success ) {
				setMessage( { type: 'success', text: i18n?.addedToCart || 'Ajouté au panier !' } );
				// Redirect to cart after a short delay.
				setTimeout( () => {
					window.location.href = response.cart_url || cartUrl;
				}, 1000 );
			} else {
				setMessage( { type: 'error', text: response.message || i18n?.errorAdding } );
			}
		} catch ( err ) {
			setMessage( {
				type: 'error',
				text: err.message || i18n?.errorAdding || 'Erreur',
			} );
		}

		setSubmitting( false );
	}, [ selectedSlot, selectedAddons, submitting, addonConfigs, productId, cartUrl, i18n ] );

	return (
		<div className="lm-booking-form">
			{ /* Step 1: Date picker */ }
			<div className={ `lm-booking-step ${ step >= 1 ? 'active' : '' }` }>
				<h3 className="lm-booking-step-title">
					{ i18n?.selectDate || 'Sélectionnez une date' }
				</h3>
				<DatePicker
					selectedDate={ selectedDate }
					onSelect={ handleDateSelect }
				/>
			</div>

			{ /* Step 2: Time slot grid */ }
			{ step >= 2 && selectedDate && (
				<div className="lm-booking-step active">
					<div className="lm-booking-step-header">
						<h3 className="lm-booking-step-title">
							{ i18n?.selectSlot || 'Choisissez un créneau' }
						</h3>
						<button
							type="button"
							className="lm-booking-back-link"
							onClick={ handleBackToDate }
						>
							&larr; Modifier la date
						</button>
					</div>
					<TimeSlotGrid
						slots={ slots }
						loading={ loading }
						error={ error }
						selectedSlot={ selectedSlot }
						onSelect={ handleSlotSelect }
						currency={ currency }
						i18n={ i18n }
					/>
				</div>
			) }

			{ /* Step 3: Add-ons + Summary */ }
			{ step >= 3 && selectedSlot && (
				<>
					{ addonConfigs?.length > 0 && (
						<div className="lm-booking-step active">
							<div className="lm-booking-step-header">
								<h3 className="lm-booking-step-title">
									{ i18n?.addons || 'Options supplémentaires' }
								</h3>
								<button
									type="button"
									className="lm-booking-back-link"
									onClick={ handleBackToSlots }
								>
									&larr; Modifier le créneau
								</button>
							</div>
							<AddonSelector
								addons={ addonConfigs }
								selected={ selectedAddons }
								onChange={ handleAddonChange }
								currency={ currency }
								i18n={ i18n }
							/>
						</div>
					) }

					<div className="lm-booking-step active">
						<BookingSummary
							date={ selectedDate }
							slot={ selectedSlot }
							addons={ addonConfigs }
							selectedAddons={ selectedAddons }
							total={ computeTotal() }
							currency={ currency }
							i18n={ i18n }
						/>

						{ message && (
							<div
								className={ `lm-booking-message lm-booking-message--${ message.type }` }
							>
								{ message.text }
							</div>
						) }

						<button
							type="button"
							className="lm-booking-submit button alt"
							onClick={ handleSubmit }
							disabled={ submitting }
						>
							{ submitting
								? ( i18n?.loading || 'Chargement…' )
								: ( i18n?.bookNow || 'Réserver' ) }
						</button>
					</div>
				</>
			) }
		</div>
	);
}

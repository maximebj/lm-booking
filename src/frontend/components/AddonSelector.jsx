/**
 * AddonSelector — lets the customer select optional add-ons during booking.
 */

export default function AddonSelector( {
	addons,
	selected,
	onChange,
	currency,
	i18n,
} ) {
	if ( ! addons || addons.length === 0 ) {
		return null;
	}

	return (
		<div className="lm-booking-addons">
			{ addons.map( ( addon ) => {
				const qty = selected[ addon.product_id ] || 0;
				const isIncluded = addon.type === 'included';
				const isOutOfStock = ! addon.in_stock;
				const displayPrice = addon.price || 0;

				return (
					<div
						key={ addon.product_id }
						className={ `lm-booking-addon ${ isIncluded ? 'included' : 'optional' } ${ qty > 0 || isIncluded ? 'active' : '' }` }
					>
						<div className="lm-booking-addon-info">
							{ addon.image && (
								<img
									src={ addon.image }
									alt={ addon.name }
									className="lm-booking-addon-image"
								/>
							) }
							<div className="lm-booking-addon-details">
								<span className="lm-booking-addon-name">
									{ addon.name }
								</span>
								{ isIncluded ? (
									<span className="lm-booking-addon-badge included">
										{ i18n?.included || 'Inclus' }
									</span>
								) : (
									<span className="lm-booking-addon-price">
										+{ displayPrice.toFixed( 2 ) }
										{ currency }
									</span>
								) }
							</div>
						</div>

						{ ! isIncluded && (
							<div className="lm-booking-addon-controls">
								{ isOutOfStock ? (
									<span className="lm-booking-addon-oos">
										{ i18n?.outOfStock || 'Indisponible' }
									</span>
								) : (
									<>
										<button
											type="button"
											className="lm-booking-addon-btn"
											disabled={ qty <= 0 }
											onClick={ () =>
												onChange(
													addon.product_id,
													qty - 1
												)
											}
											aria-label="Diminuer"
										>
											−
										</button>
										<span className="lm-booking-addon-qty">
											{ qty }
										</span>
										<button
											type="button"
											className="lm-booking-addon-btn"
											disabled={
												qty >= addon.max_qty ||
												( addon.stock_qty !== null &&
													qty >= addon.stock_qty )
											}
											onClick={ () =>
												onChange(
													addon.product_id,
													qty + 1
												)
											}
											aria-label="Augmenter"
										>
											+
										</button>
									</>
								) }
							</div>
						) }
					</div>
				);
			} ) }
		</div>
	);
}

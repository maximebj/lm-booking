/**
 * DatePicker — simple calendar for selecting a booking date.
 */
import { useState, useMemo } from '@wordpress/element';

const DAY_NAMES = [ 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim' ];
const MONTH_NAMES = [
	'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
	'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre',
];

/* global lmBookingData */
const maxAdvance = window.lmBookingData?.maxAdvance || 90;
const minAdvance = window.lmBookingData?.minAdvance || 1;

function pad( n ) {
	return String( n ).padStart( 2, '0' );
}

function formatDate( year, month, day ) {
	return `${ year }-${ pad( month + 1 ) }-${ pad( day ) }`;
}

export default function DatePicker( { selectedDate, onSelect } ) {
	const today = useMemo( () => new Date(), [] );
	const [ viewYear, setViewYear ] = useState( today.getFullYear() );
	const [ viewMonth, setViewMonth ] = useState( today.getMonth() );

	const minDate = useMemo( () => {
		const d = new Date( today );
		d.setHours( d.getHours() + minAdvance );
		// If min advance pushes us past today, start from tomorrow.
		return formatDate( d.getFullYear(), d.getMonth(), d.getDate() );
	}, [ today ] );

	const maxDate = useMemo( () => {
		const d = new Date( today );
		d.setDate( d.getDate() + maxAdvance );
		return formatDate( d.getFullYear(), d.getMonth(), d.getDate() );
	}, [ today ] );

	// Build calendar grid.
	const calendarDays = useMemo( () => {
		const firstDay = new Date( viewYear, viewMonth, 1 );
		const lastDay = new Date( viewYear, viewMonth + 1, 0 );

		// Monday-based: 0=Mon, 6=Sun.
		let startDow = firstDay.getDay() - 1;
		if ( startDow < 0 ) startDow = 6;

		const days = [];

		// Empty cells before the first day.
		for ( let i = 0; i < startDow; i++ ) {
			days.push( null );
		}

		for ( let d = 1; d <= lastDay.getDate(); d++ ) {
			const dateStr = formatDate( viewYear, viewMonth, d );
			const isDisabled = dateStr < minDate || dateStr > maxDate;
			days.push( { day: d, date: dateStr, disabled: isDisabled } );
		}

		return days;
	}, [ viewYear, viewMonth, minDate, maxDate ] );

	const prevMonth = () => {
		if ( viewMonth === 0 ) {
			setViewMonth( 11 );
			setViewYear( ( y ) => y - 1 );
		} else {
			setViewMonth( ( m ) => m - 1 );
		}
	};

	const nextMonth = () => {
		if ( viewMonth === 11 ) {
			setViewMonth( 0 );
			setViewYear( ( y ) => y + 1 );
		} else {
			setViewMonth( ( m ) => m + 1 );
		}
	};

	return (
		<div className="lm-booking-datepicker">
			<div className="lm-booking-datepicker-header">
				<button
					type="button"
					className="lm-booking-datepicker-nav"
					onClick={ prevMonth }
					aria-label="Mois précédent"
				>
					&lsaquo;
				</button>
				<span className="lm-booking-datepicker-title">
					{ MONTH_NAMES[ viewMonth ] } { viewYear }
				</span>
				<button
					type="button"
					className="lm-booking-datepicker-nav"
					onClick={ nextMonth }
					aria-label="Mois suivant"
				>
					&rsaquo;
				</button>
			</div>

			<div className="lm-booking-datepicker-grid">
				{ DAY_NAMES.map( ( name ) => (
					<div key={ name } className="lm-booking-datepicker-dayname">
						{ name }
					</div>
				) ) }

				{ calendarDays.map( ( cell, idx ) => {
					if ( ! cell ) {
						return <div key={ `empty-${ idx }` } className="lm-booking-datepicker-empty" />;
					}

					const isSelected = cell.date === selectedDate;
					const classes = [
						'lm-booking-datepicker-day',
						cell.disabled && 'disabled',
						isSelected && 'selected',
					]
						.filter( Boolean )
						.join( ' ' );

					return (
						<button
							key={ cell.date }
							type="button"
							className={ classes }
							disabled={ cell.disabled }
							onClick={ () => onSelect( cell.date ) }
							aria-pressed={ isSelected }
						>
							{ cell.day }
						</button>
					);
				} ) }
			</div>
		</div>
	);
}

/**
 * Admin entry point — mounts React components into the product data panels.
 */
import { createRoot } from '@wordpress/element';
import WeeklySchedule from './components/WeeklySchedule';
import DateOverrides from './components/DateOverrides';
import AddonManager from './components/AddonManager';

document.addEventListener( 'DOMContentLoaded', () => {
	// WeeklySchedule
	const weeklyEl = document.getElementById( 'lm-booking-weekly-schedule' );
	if ( weeklyEl ) {
		const input = weeklyEl.querySelector( 'input[name="_lm_booking_weekly_hours"]' );
		let initial = {};
		try {
			initial = JSON.parse( input?.value || '{}' );
		} catch ( e ) {
			initial = {};
		}
		const root = createRoot( weeklyEl );
		root.render( <WeeklySchedule initial={ initial } inputId="_lm_booking_weekly_hours" /> );
	}

	// DateOverrides
	const overridesEl = document.getElementById( 'lm-booking-date-overrides' );
	if ( overridesEl ) {
		const input = overridesEl.querySelector( 'input[name="_lm_booking_date_overrides"]' );
		let initial = {};
		try {
			initial = JSON.parse( input?.value || '{}' );
		} catch ( e ) {
			initial = {};
		}
		const root = createRoot( overridesEl );
		root.render( <DateOverrides initial={ initial } inputId="_lm_booking_date_overrides" /> );
	}

	// AddonManager
	const addonEl = document.getElementById( 'lm-booking-addon-manager' );
	if ( addonEl ) {
		const input = addonEl.querySelector( 'input[name="_lm_booking_addons"]' );
		let initial = [];
		try {
			initial = JSON.parse( input?.value || '[]' );
		} catch ( e ) {
			initial = [];
		}
		const root = createRoot( addonEl );
		root.render( <AddonManager initial={ initial } inputId="_lm_booking_addons" /> );
	}
} );

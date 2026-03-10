/**
 * Admin entry point — mounts React components into the product data panels.
 */
import { createRoot } from '@wordpress/element';
import WeeklySchedule from './components/WeeklySchedule';
import DateOverrides from './components/DateOverrides';
import AddonManager from './components/AddonManager';

document.addEventListener( 'DOMContentLoaded', () => {
	const data = window.lmBookingAdmin || {};

	const weeklyEl = document.getElementById( 'lm-booking-weekly-schedule' );
	if ( weeklyEl ) {
		const initial = typeof data.weeklyHours === 'object' ? data.weeklyHours : {};
		const root = createRoot( weeklyEl );
		root.render( <WeeklySchedule initial={ initial } inputId="_lm_booking_weekly_hours" /> );
	}

	const overridesEl = document.getElementById( 'lm-booking-date-overrides' );
	if ( overridesEl ) {
		const initial = typeof data.dateOverrides === 'object' ? data.dateOverrides : {};
		const root = createRoot( overridesEl );
		root.render( <DateOverrides initial={ initial } inputId="_lm_booking_date_overrides" /> );
	}

	const addonEl = document.getElementById( 'lm-booking-addon-manager' );
	if ( addonEl ) {
		const initial = Array.isArray( data.addons ) ? data.addons : [];
		const root = createRoot( addonEl );
		root.render( <AddonManager initial={ initial } inputId="_lm_booking_addons" /> );
	}
} );

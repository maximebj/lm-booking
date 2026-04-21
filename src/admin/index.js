/**
 * Admin entry point — mounts React components into the product data panels.
 */
import { createRoot } from "@wordpress/element";
import AddonManager from "./components/AddonManager";
import DateOverrides from "./components/DateOverrides";
import WeeklySchedule from "./components/WeeklySchedule";

/**
 * WooCommerce hides price fields for unknown product types.
 * Add show_if_booking to the standard price rows and re-trigger
 * WC's type-change logic so the fields appear on page load and on switch.
 */
jQuery(document).ready(function ($) {
  $(".general_tab").addClass("show_if_booking").css("display", "block");
  $(".options_group.pricing")
    .addClass("show_if_booking")
    .css("display", "block");
});

document.addEventListener("DOMContentLoaded", () => {
  const data = window.lmBookingAdmin || {};

  const weeklyEl = document.getElementById("lm-booking-weekly-schedule");
  if (weeklyEl) {
    const initial =
      typeof data.weeklyHours === "object" ? data.weeklyHours : {};
    const root = createRoot(weeklyEl);
    root.render(
      <WeeklySchedule initial={initial} inputId="_lm_booking_weekly_hours" />,
    );
  }

  const overridesEl = document.getElementById("lm-booking-date-overrides");
  if (overridesEl) {
    const initial =
      typeof data.dateOverrides === "object" ? data.dateOverrides : {};
    const root = createRoot(overridesEl);
    root.render(
      <DateOverrides initial={initial} inputId="_lm_booking_date_overrides" />,
    );
  }

  const addonEl = document.getElementById("lm-booking-addon-manager");
  if (addonEl) {
    const initial = Array.isArray(data.addons) ? data.addons : [];
    const root = createRoot(addonEl);
    root.render(
      <AddonManager initial={initial} inputId="_lm_booking_addons" />,
    );
  }
});

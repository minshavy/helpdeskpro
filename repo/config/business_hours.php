<?php
// ---------------------------------------------------------
// Business Hours (SLA clock)
// ---------------------------------------------------------
// SLA timers only run during working hours, so a ticket filed at 1pm on a
// Thursday doesn't burn its whole budget over the weekend.
//
// Defaults below follow standard MALDIVES GOVERNMENT OFFICE hours:
//   Working week : Sunday - Thursday
//   Weekend      : Friday & Saturday
//   Hours        : 08:00 - 14:00
//
// Adjust these if your office differs (e.g. some agencies run 07:30-14:30).

define('BUSINESS_HOURS_ENABLED', true);

// Day numbers follow PHP's date('w'): 0=Sun, 1=Mon ... 5=Fri, 6=Sat
define('BUSINESS_WORKDAYS', [0, 1, 2, 3, 4]); // Sunday through Thursday

define('BUSINESS_DAY_START', '08:00');
define('BUSINESS_DAY_END',   '14:00');

// Timezone used for all SLA calculations (Maldives = UTC+5, no DST).
define('BUSINESS_TIMEZONE', 'Indian/Maldives');

/**
 * Public holidays - SLA clocks pause on these dates.
 *
 * NOTE: the fixed-date national holidays are listed below. Islamic holidays
 * (Ramadan/Eid, Prophet's Birthday, Islamic New Year) move each year against
 * the Gregorian calendar and are announced by the President's Office, so they
 * must be added manually each year - add them in 'YYYY-MM-DD' form.
 */
define('BUSINESS_HOLIDAYS', [
    // Fixed-date national holidays
    '2026-01-01', // New Year's Day
    '2026-07-26', // Independence Day
    '2026-07-27', // Independence Day (second day)
    '2026-11-03', // Victory Day
    '2026-11-11', // Republic Day

    // Add this year's announced Islamic holidays here, e.g.:
    // '2026-03-20', // Eid al-Fitr
]);

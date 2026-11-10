<?php
/**
 * Business Hours engine
 * ---------------------------------------------------------
 * Converts an SLA expressed in *working* hours into a real calendar
 * deadline, skipping evenings, weekends and public holidays.
 *
 * Example with Maldives government hours (Sun-Thu, 08:00-14:00 = 6h/day):
 *   A High priority ticket (24 working hours) filed Thursday 13:00
 *   is NOT due Friday 13:00 - it's due the following Thursday 13:00,
 *   because Fri/Sat don't count and each working day only contributes 6h.
 */

require_once __DIR__ . '/../config/business_hours.php';

class BusinessHours {

    private static function tz() {
        return new DateTimeZone(BUSINESS_TIMEZONE);
    }

    /** Is this date a working day (correct weekday, and not a public holiday)? */
    public static function isWorkday(DateTimeInterface $dt) {
        $dayOfWeek = (int) $dt->format('w');
        if (!in_array($dayOfWeek, BUSINESS_WORKDAYS, true)) {
            return false;
        }
        return !in_array($dt->format('Y-m-d'), BUSINESS_HOLIDAYS, true);
    }

    /** Working seconds in a full working day. */
    public static function secondsPerDay() {
        [$sh, $sm] = array_map('intval', explode(':', BUSINESS_DAY_START));
        [$eh, $em] = array_map('intval', explode(':', BUSINESS_DAY_END));
        return (($eh * 3600 + $em * 60) - ($sh * 3600 + $sm * 60));
    }

    private static function dayStart(DateTimeInterface $dt) {
        [$h, $m] = array_map('intval', explode(':', BUSINESS_DAY_START));
        $d = (new DateTime($dt->format('Y-m-d'), self::tz()))->setTime($h, $m, 0);
        return $d;
    }

    private static function dayEnd(DateTimeInterface $dt) {
        [$h, $m] = array_map('intval', explode(':', BUSINESS_DAY_END));
        $d = (new DateTime($dt->format('Y-m-d'), self::tz()))->setTime($h, $m, 0);
        return $d;
    }

    /**
     * Given any moment, return the next instant the clock is actually running.
     * Outside hours -> next working day's opening time. Before opening on a
     * working day -> that day's opening time.
     */
    public static function nextWorkingMoment(DateTime $dt) {
        $cursor = clone $dt;
        // Look ahead a bounded number of days so a misconfiguration
        // (e.g. no workdays set) can't spin forever.
        for ($i = 0; $i < 400; $i++) {
            if (self::isWorkday($cursor)) {
                $start = self::dayStart($cursor);
                $end   = self::dayEnd($cursor);
                if ($cursor < $start) return $start;
                if ($cursor < $end)   return clone $cursor; // already inside hours
            }
            // move to the start of the next day
            $cursor = (clone $cursor)->modify('+1 day');
            [$h, $m] = array_map('intval', explode(':', BUSINESS_DAY_START));
            $cursor->setTime($h, $m, 0);
        }
        return $cursor;
    }

    /**
     * Count working SECONDS elapsed between two moments.
     */
    public static function secondsBetween(DateTime $from, DateTime $to) {
        if ($to <= $from) return 0;

        $total = 0;
        $cursor = clone $from;

        for ($i = 0; $i < 2000; $i++) {
            if ($cursor >= $to) break;

            if (self::isWorkday($cursor)) {
                $dayStart = self::dayStart($cursor);
                $dayEnd   = self::dayEnd($cursor);

                $segStart = $cursor > $dayStart ? $cursor : $dayStart;
                $segEnd   = $to < $dayEnd ? $to : $dayEnd;

                if ($segEnd > $segStart) {
                    $total += $segEnd->getTimestamp() - $segStart->getTimestamp();
                }
            }

            $cursor = (clone $cursor)->modify('+1 day')->setTime(0, 0, 0);
        }
        return $total;
    }

    /** Working HOURS elapsed between two moments (float). */
    public static function hoursBetween(DateTime $from, DateTime $to) {
        return self::secondsBetween($from, $to) / 3600;
    }

    /**
     * Add N working hours to a starting moment and return the real deadline.
     * This is what turns "24 working hours" into an actual due date/time.
     */
    public static function addHours(DateTime $start, $hours) {
        $remaining = (int) round($hours * 3600);
        $cursor = self::nextWorkingMoment(clone $start);

        for ($i = 0; $i < 2000 && $remaining > 0; $i++) {
            $dayEnd = self::dayEnd($cursor);
            $availableToday = $dayEnd->getTimestamp() - $cursor->getTimestamp();

            if ($availableToday >= $remaining) {
                return (clone $cursor)->modify("+$remaining seconds");
            }

            if ($availableToday > 0) {
                $remaining -= $availableToday;
            }

            // jump to the opening of the next working day
            $cursor = (clone $cursor)->modify('+1 day')->setTime(0, 0, 0);
            $cursor = self::nextWorkingMoment($cursor);
        }
        return $cursor;
    }

    /**
     * Compute the SLA deadline for a ticket, as a 'Y-m-d H:i:s' string ready
     * for the database. Falls back to plain calendar hours if business hours
     * are disabled in config.
     */
    public static function slaDueAt($createdAt, $slaHours) {
        $start = new DateTime($createdAt, self::tz());

        if (!BUSINESS_HOURS_ENABLED) {
            return (clone $start)->modify('+' . (int) $slaHours . ' hours')->format('Y-m-d H:i:s');
        }
        return self::addHours($start, $slaHours)->format('Y-m-d H:i:s');
    }

    /** Human-readable summary of the configured schedule, for the UI. */
    public static function scheduleSummary() {
        $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $days = BUSINESS_WORKDAYS;
        sort($days);

        // Collapse consecutive days into ranges: "Sun-Thu" reads far better
        // than "Sun, Mon, Tue, Wed, Thu".
        $parts = [];
        $i = 0;
        $n = count($days);
        while ($i < $n) {
            $start = $i;
            while ($i + 1 < $n && $days[$i + 1] === $days[$i] + 1) {
                $i++;
            }
            if ($i - $start >= 2) {
                $parts[] = $dayNames[$days[$start]] . '-' . $dayNames[$days[$i]];
            } else {
                for ($j = $start; $j <= $i; $j++) {
                    $parts[] = $dayNames[$days[$j]];
                }
            }
            $i++;
        }
        return implode(', ', $parts) . ' ' . BUSINESS_DAY_START . '-' . BUSINESS_DAY_END;
    }
}

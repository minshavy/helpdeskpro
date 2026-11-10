<?php
// ---------------------------------------------------------
// Password Expiry Policy
// ---------------------------------------------------------
// When enabled, Admin accounts are forced to set a new password after
// PASSWORD_EXPIRY_DAYS since their password was last changed. Disabled by
// default so it doesn't surprise anyone until you're ready to turn it on.
define('PASSWORD_EXPIRY_ENABLED', false);
define('PASSWORD_EXPIRY_DAYS', 90);

// ---------------------------------------------------------
// Session Auto-Logout
// ---------------------------------------------------------
// Everyone gets logged out automatically after this many seconds of
// inactivity (no page loads). 900 seconds = 15 minutes.
define('SESSION_TIMEOUT_SECONDS', 900);

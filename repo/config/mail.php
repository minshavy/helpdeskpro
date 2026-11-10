<?php
// ---------------------------------------------------------
// Email / SMTP Configuration
// ---------------------------------------------------------
// Fill these in with your own SMTP details to enable email notifications.
// For Gmail: enable 2FA on the account, then create an "App Password"
// at https://myaccount.google.com/apppasswords and use that (not your
// normal Gmail password) as MAIL_PASSWORD below.
//
// If MAIL_ENABLED is false, HelpDeskPro will simply skip sending emails
// (the app still works normally - tickets, comments, etc. are unaffected).

define('MAIL_ENABLED', false); // set to true once the settings below are filled in

define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', 'your_email@gmail.com');
define('MAIL_PASSWORD', 'your_app_password');
define('MAIL_ENCRYPTION', 'tls'); // 'tls' or 'ssl'

define('MAIL_FROM_ADDRESS', 'your_email@gmail.com');
define('MAIL_FROM_NAME', 'HelpDeskPro');

// Base URL of your installation, used to build the "View Ticket" link in emails.
// Change this to match your actual server address (no trailing slash).
define('APP_URL', 'http://localhost/helpdeskpro');

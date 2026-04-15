<?php
/**
 * email_config.php — Gmail SMTP credentials
 *
 * HOW TO SET UP GMAIL APP PASSWORD (do this once):
 * ─────────────────────────────────────────────────
 * 1. Go to your Google Account → Security
 * 2. Make sure "2-Step Verification" is ON (required for App Passwords)
 * 3. Search for "App passwords" in the Security page
 * 4. Click "App passwords" → choose app: Mail, device: Other → type "CATMIS"
 * 5. Google gives you a 16-character password like: abcd efgh ijkl mnop
 * 6. Copy that password (without spaces) into MAIL_PASS below
 * 7. Put your Gmail address in MAIL_FROM and MAIL_USER
 *
 * NEVER use your regular Gmail password here — use the App Password only.
 * Add this file to .gitignore so credentials are never committed to git.
 */

define('MAIL_HOST',       'smtp.gmail.com');
define('MAIL_PORT',       587);
define('MAIL_ENCRYPTION', 'tls');
define('MAIL_USER',       'ezetest240@gmail.com');   // ← your Gmail address
define('MAIL_PASS',       'nyfyvaduwsebqvir'); // ← 16-char App Password (no spaces)
define('MAIL_FROM',       'ezetest240@gmail.com');   // ← same as MAIL_USER usually
define('MAIL_FROM_NAME',  'CATMIS Portal');
?>
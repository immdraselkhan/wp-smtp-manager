=== WP SMTP Manager ===
Contributors: immdraselkhan
Tags: smtp, email, wp mail, woocommerce, phpmailer
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later

A lightweight SMTP manager with a clean admin interface, encrypted password storage, test email, debug logging, HELO control, and sender identity settings.

== Installation ==

1. Upload the plugin ZIP from Plugins > Add New > Upload Plugin.
2. Activate WP SMTP Manager.
3. Open Settings > WP SMTP Manager.
4. Enter the SMTP account details.
5. Save the settings.
6. Send a test email.

== Suggested settings for solar.com.bd ==

SMTP Host: mail.solar.com.bd
SMTP Port: 465
Encryption: SSL / SMTPS
Authentication: Enabled
From Email: Your actual mailbox, such as info@solar.com.bd
Force From Email: Enabled
Set Return-Path: Enabled
Verify SSL Certificate: Enabled
Custom HELO/EHLO: Usually disabled. Enable only when specifically required.

== Security ==

The SMTP password is encrypted with AES-256-CBC using the WordPress authentication salt when OpenSSL is available.

== Changelog ==

= 1.0.1 =
* Matched encryption field height with other inputs.
* Added browser autofill protection attributes for SMTP username and password.
* Added Settings, developer, and website links on the Plugins page.


== Version 1.1.0 ==

* Admin warning when one or more emails fail.
* Dismissible failure notice with a direct View Details link.
* WordPress Dashboard SMTP status widget.
* Tracks the latest 50 sent and failed email attempts.
* Displays the last successful email and failures from the previous 24 hours.
* Retry button for failed emails.
* Clear email history option.


== Version 1.1.1 ==

* SMTP Host now defaults to mail.current-domain.com.
* SMTP Username defaults to the WordPress Administration Email Address.
* From Email defaults to the WordPress Administration Email Address.
* From Name defaults to the WordPress Site Title.
* HELO hostname defaults to the current domain.
* Empty saved identity fields automatically fall back to the current WordPress values.
* SMTP port changes intelligently when SSL, TLS, or no encryption is selected.


== Version 1.1.2 ==

* SMTP Username is empty by default.
* Custom HELO/EHLO hostname is empty by default.
* Fixed duplicate and unreadable settings-saved notices.
* Added a single readable success notification that automatically closes.
* Improved debug-log contrast and readability.
* Saved SMTP passwords now display as a locked masked value.
* Added Remove Password and Cancel controls.
* The password field cannot be edited until the saved password is removed.
* No WP Mail SMTP import feature is included.


== Version 1.1.3 ==

* Replaced the default WordPress failure notice with a custom WP SMTP alert.
* Forced high-contrast text colors to prevent theme or admin CSS conflicts.
* Added a clear error icon, last error summary, View Details button, and Dismiss button.
* Improved mobile layout and visibility.


== Version 1.1.4 ==

* Removed the duplicate generic WordPress “Settings saved.” notice.
* Improved SMTP authentication compatibility with PHPMailer/WP Mail SMTP-style configuration.
* Password input is now unslashed before encryption, preserving special characters exactly.
* Added authenticated versioned password encryption while retaining backward compatibility.
* Added a clear warning when an older saved password cannot be decrypted.
* Debug mode now records SMTP host, port, encryption, username, and password length without exposing the password.
* Uses PHPMailer setFrom() and resets custom HELO when disabled.


== Version 1.2.0 ==

* Restored a WordPress-native admin error notice and forced readable text colors on every admin page.
* Settings are now saved by AJAX without reloading the page.
* The successful-save message remains visible and automatically disappears after five seconds.
* Test Email, Retry, Clear Log, and Clear History now refresh the debug log and email history immediately.
* Failed email results appear without requiring a manual page reload.
* Uninstall cleanup now removes all plugin settings, history, status data, and the physical debug log file when Delete Settings on Uninstall is enabled.
* Improved activity rendering and status updates.


== Version 1.2.1 ==

* Added a Copy Log button.
* Send Test Email card now stays in normal page flow and is no longer sticky.
* AJAX success messages now appear at the top and disappear after five seconds.
* AJAX error messages appear at the top and remain visible until dismissed or the page is reloaded.
* Test email failures still refresh the debug log and email history immediately without reloading.


== Version 1.2.2 ==

* Test Email now always uses the saved SMTP profile, even when global SMTP delivery is disabled.
* Prevents false-positive test results caused by WordPress falling back to PHP mail().
* Validates the SMTP host and required authentication fields before testing.
* Test mode automatically writes the SMTP conversation to the debug log.
* Test results and logs continue to refresh immediately through AJAX.
* Success and error messages now appear as fixed top-right toast notifications.
* Success toasts disappear after five seconds; error toasts remain until dismissed or reloaded.
* Fixed the SMTP Enabled/Disabled badge overflowing outside the header.


== Version 1.3.0 ==

* Renamed the plugin to WP SMTP Manager.
* Removed WP branding from the interface and plugin metadata.
* Updated developer information to Md Rasel Khan and raselkhan.dev.
* Added the GitHub project link.
* All action messages now use one bottom-center toast.
* Success and error toasts both disappear automatically after five seconds.
* Clear Log, Clear History, Copy Log, Save Settings, Test Email, and Retry all use toast messages.


== Version 1.3.1 ==

* Removed the remaining NameHost badge and branding.
* Fixed the broken standalone close button.
* Rebuilt all AJAX action messaging with a reliable bottom-center toast.
* Added exact plugin metadata: By Md Rasel Khan, View Github, Visit Website.


== Version 1.3.2 ==

* Removed duplicate custom plugin-row author, GitHub, and website metadata.
* Simplified the WordPress-native failed-email notice to a single concise line.
* Disabled the Test Email section until all required SMTP fields are complete.
* Test availability now updates instantly when SMTP fields change.
* Added matching server-side validation to prevent incomplete SMTP tests.

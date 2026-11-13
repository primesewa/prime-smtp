=== Prime SMTP ===
Contributors: primeitsewa
Tags: smtp, email, mailer, phpmailer, transactional email
Requires at least: 5.0
Tested up to: 6.9.1
Requires PHP: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Simple, clean, and safer WordPress SMTP mailer with reliable SMTP settings, PHPMailer reset, and test email delivery.

== Description ==

Prime SMTP is a simple WordPress SMTP plugin for sending site emails through a real SMTP server instead of the default PHP mail transport. It is built for site owners who want clean settings, reliable test emails, and safer mail handling without a heavy setup process.

Developed by [Prime IT Sewa](https://primeitsewa.com/), a web design and development agency from Kathmandu, Nepal.

Features include:

* SMTP host, port, encryption, username, and password settings.
* Configurable From Email and From Name.
* Optional forced sender identity for all WordPress emails.
* Optional return path alignment.
* Cache refresh and PHPMailer reset whenever settings are saved.
* Admin test email tool with delivery error feedback.

Prime SMTP works with common SMTP providers that support standard SMTP credentials, including business email hosting, cPanel email, Gmail app passwords, Outlook, and transactional email services.

== Installation ==

1. Upload the `prime-smtp` folder to `/wp-content/plugins/`.
2. Activate Prime SMTP from the Plugins screen.
3. Go to Settings > Prime SMTP.
4. Save your SMTP settings.
5. Send a test email.

== Why Prime SMTP? ==

Prime SMTP focuses on the essentials: enter SMTP settings, save, clear the mailer cache, and send a test email. It avoids unnecessary features and keeps the admin screen easy to understand.

For best results, use an SMTP account from the same domain as your From Email and configure SPF, DKIM, and DMARC records with your DNS provider.

== Frequently Asked Questions ==

= Does this guarantee inbox delivery? =

No plugin can guarantee inbox placement. For best deliverability, use a verified From Email on the same domain as your SMTP account and configure SPF, DKIM, and DMARC DNS records with your email provider.

= Where is the SMTP password stored? =

The SMTP password is stored in the WordPress options table, like most WordPress SMTP plugins. Only administrators with settings access should manage this screen.

== Privacy ==

Prime SMTP does not track users, set cookies, or send plugin data to Prime IT Sewa. The plugin sends email only when WordPress or an administrator triggers a mail action.

== Changelog ==

= 1.0.0 =
* Initial Prime SMTP release.

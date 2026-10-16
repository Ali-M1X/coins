# Email price alerts — recommendation (not built; waiting for your approval)

The browser alerts in 2.12.0 need no account, no address and no server storage. Email alerts are a different kind of feature: the site would store personal data, send mail on its own, and keep doing so after the visitor has left. Below is what that takes, and what I recommend.

## Recommendation in one paragraph

Build it only with a real transactional mail service (not PHP `mail()` from the shared host), double opt-in, a one-click unsubscribe in every message, at most 5 active alerts per address, and checking from the existing scheduler against cached prices only. Store as little as possible: the address (encrypted at rest), the alerts and the confirmation state. Delete unconfirmed sign-ups after 48 hours, and all data for an address 30 days after its last alert ends or immediately on unsubscribe. Before launch, publish a short Persian privacy notice, and check with your legal adviser which law applies to your users and to the mail provider's country.

## What is needed

**1. Sending mail.** Shared hosting's `mail()` lands in spam, has no bounce handling and can get the server's IP listed.
- Use a transactional provider through an SMTP plugin (e.g. WP Mail SMTP) or its API: Amazon SES, Postmark, Mailgun or Brevo.
- Set SPF, DKIM and DMARC on the sending domain, and use a separate sender such as `alerts@thehybit.com`.
- Providers in some countries may refuse service to an Iranian site or audience. Check the provider's terms before choosing one; this is a real risk for this site.

**2. Double opt-in.**
- **Form:** the visitor enters an address and an alert. We store it as *pending* and send one confirmation email with a single-use signed link (random 32-byte token, stored hashed, valid 48 hours).
- **Activation:** nothing else is ever sent until the link is clicked.
- **Cleanup:** unconfirmed entries are deleted after 48 hours.

**3. Unsubscribe.**
- **Links:** every alert email carries a one-click unsubscribe link (signed, no login) and the `List-Unsubscribe` + `List-Unsubscribe-Post` headers, so mail clients show their own button.
- **Two levels:** "this alert" and "all alerts for this address".
- **Effect:** immediate, and the data is deleted.

**4. Rate limiting and spam protection.** The form must not be usable to email strangers or flood an inbox.
- **Per visitor:** a sign-up endpoint limited per IP hash (the same `RateLimit` class as the search index), e.g. 5 per hour.
- **Per address:** at most 3 confirmation emails a day and 5 active alerts.
- **Form protection:** a WordPress nonce, a honeypot field and a minimum fill time. If abuse appears, add a privacy-respecting challenge (e.g. Cloudflare Turnstile, which needs your approval as a third party).
- **Per alert:** fires once, then stays off until re-armed from the email's link, and never more than one email per address per 10 minutes.
- **Global:** a daily cap on outgoing alert mail, so a price crash cannot turn into thousands of messages at once; the rest is queued.

**5. Checking alerts.**
- **Where:** inside the existing scheduler tick, from the cached live-price entry. There are **no new API requests**: one cache read covers every coin.
- **Sending:** matches go into a queue table, and a separate cron step sends a bounded batch per tick.

**6. Storage.**
- **Tables:** one for addresses (email encrypted with a key from `wp-config.php`, plus a hash for lookup, status, created and confirmed dates) and one for alerts (address id, coin, direction, price, created, fired).
- **What is never stored:** no IP address beyond the transient rate-limit hash, no name, no tracking pixels and no link tracking.
- **Admin:** a wp-admin screen with counts only, plus "export / delete this address" for data requests.

## Privacy wording (Persian draft for the form and the notice)

> با ثبت نشانی ایمیل، فقط برای همین هشدار قیمت به شما ایمیل می‌زنیم. ابتدا یک ایمیل تأیید می‌فرستیم و تا روی پیوند آن کلیک نکنید، هیچ ایمیل دیگری دریافت نمی‌کنید. نشانی شما رمزگذاری‌شده نگهداری می‌شود، به هیچ‌کس داده یا فروخته نمی‌شود و در هر ایمیل پیوند لغو اشتراک هست. با لغو اشتراک، نشانی و هشدارهای شما فوراً پاک می‌شوند؛ اگر نشانی را تأیید نکنید، پس از ۴۸ ساعت پاک می‌شود. ارسال ایمیل از طریق [نام سرویس ارسال ایمیل] انجام می‌شود.

## Legal and practical risks

- **Personal data.** An email address is personal data. If you have users in the EU or UK, GDPR applies: you need a lawful basis (consent via double opt-in), access and deletion on request, and a data processing agreement with the mail provider. Iranian law on personal data is less specific, but your own privacy page should still say what is kept and for how long.
- **Financial content.** An alert must stay a notification about a public price, never advice. Every email should carry «این پیام توصیه خرید یا فروش نیست».
- **Deliverability and reputation.** One spam complaint wave can block the domain for all of the site's mail, including WordPress password resets. A separate sending subdomain limits the damage.
- **Accuracy.** Alerts are checked once a minute against cached prices. The email must say the price and its time, and that the market may have moved since.
- **Sanctions and provider terms.** As above, choose the provider with this in mind.

## Effort, once approved

About 2–3 versions:
1. Tables, the sign-up form with double opt-in and the unsubscribe flow.
2. Scheduler checks, the send queue, and the caps and limits.
3. The admin screen, data export and delete, and tests (abuse cases included).

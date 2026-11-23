# Bizgrowth360 Billing, Plans & Subscriptions

Phase 9 adds a subscription, payment, invoice, coupon and usage-enforcement
layer to the existing SaaS App Builder. It uses the existing SaaS workspaces,
applications, builds and customer portal; it does not replace them.

## 1. Architecture

```
WordPress customer/workspace
        |
        +-- SaaS workspace + apps + builds (existing SaaS foundation)
        |
        +-- Billing engine
              +-- Plans (bg360_plans, extended)
              +-- Subscriptions + history
              +-- Usage events + quota reservations
              +-- Entitlements (server-side enforcement)
              +-- Payments -> Razorpay / Stripe gateway adapters
              +-- Signed webhooks -> idempotent processing
              +-- Invoices + tax configuration
              +-- Coupons + redemptions
              +-- Renewal/grace/suspension cron
              +-- Notifications
              +-- Super Admin console + customer pricing/dashboard
```

### Tenancy

A billing tenant is a **SaaS workspace ID**, not a WordPress user ID. This keeps
billing isolated in the same way as the existing workspace/application layer.
The workspace owner is resolved through `bg360_workspaces.user_id`.

Existing workspaces are given a billing subscription from their SaaS plan during
the first upgrade. Workspace 1 (platform admin) is grandfathered on the
Enterprise plan. New customers receive the Starter plan and an active manual
subscription, then can upgrade through checkout.

> The existing plugin stores one app configuration per workspace. The billing
> app limit is enforced against the SaaS application registry
> (`bg360_apps`), so each distinct package ID counts as one application.

## 2. Database Tables

Created by `BillingDb::install()` with non-destructive `dbDelta`:

| Table | Purpose |
| :--- | :--- |
| `bg360_plans` | SaaS-owned plan table extended with billing columns |
| `bg360_subscriptions` | Current subscription per workspace |
| `bg360_subscription_history` | Every status/plan transition |
| `bg360_transactions` | Payment/refund records, gateway IDs, idempotency keys |
| `bg360_invoices` | Issued invoices and tax breakdown |
| `bg360_coupons` | Coupon definitions |
| `bg360_coupon_redemptions` | Per-customer coupon usage |
| `bg360_usage_events` | Idempotent usage/quota events |
| `bg360_webhook_events` | Deduplicated signed webhook records |
| `bg360_customer_profiles` | Billing name/address/GSTIN |
| `bg360_invoice_counters` | Atomic invoice numbering |
| `bg360_audit_log` | Billing audit trail |
| `bg360_notification_log` | Email delivery log |

Existing `bg360_apps`, `bg360_workspaces`, `bg360_usage` and `bg360_builds`
remain the SaaS source of truth. `bg360_builds.workspace_id` and `app_id` are
used for tenant attribution.

Indexes/constraints include unique plan slug, unique invoice number, unique
gateway payment ID, unique transaction idempotency key, unique webhook
`(gateway,event_id)`, unique usage idempotency key and unique coupon redemption
per transaction.

## 3. Plans

Super Admin: **App Builder → Billing & Plans → Plans**.

Every plan is database-driven. Supported fields:

- Name, slug, description
- Monthly price, annual price, currency, default interval
- Trial days
- Max applications, monthly build limit, concurrent build limit, storage MB
- APK / AAB / IPA access
- Native features, push notifications, Design Studio, custom branding,
  priority builds
- Support level, visibility, sort order, status

`-1` means unlimited for max applications, monthly builds and storage. Plan
prices and limits are never hardcoded in build/entitlement logic.

Plans can be created, edited, activated, deactivated and archived. Archiving
removes a plan from public pricing while preserving existing subscriptions.

## 4. Subscription Lifecycle

Statuses: `trial`, `active`, `pending`, `past_due`, `grace`, `suspended`,
`cancelled`, `expired`.

| Action | Behaviour |
| :--- | :--- |
| Start | Free plan activates immediately; paid plan starts trial or pending checkout |
| Activate | After verified payment/webhook, sets active period |
| Renew | Extends the current period by monthly/annual interval |
| Cancel | At period end by default; immediate cancellation is admin/customer explicit |
| Resume | Clears cancel-at-period-end, or moves expired/suspended to pending payment |
| Upgrade | Immediate after verified payment (or proration credit) |
| Downgrade | Scheduled for next period; existing apps are never deleted |
| Past due | Renewal failure, grace window begins |
| Grace | Access continues during grace |
| Suspend | Grace expired or admin action; builds blocked |
| Expire | Period/trial ended with no recovery |

Every transition is written to `bg360_subscription_history`.

## 5. Checkout and Gateways

### Razorpay (primary)

Constants in `wp-config.php`:

```php
define( 'BIZGROWTH360_RAZORPAY_KEY_ID', 'rzp_live_...' );
define( 'BIZGROWTH360_RAZORPAY_KEY_SECRET', '...' );
define( 'BIZGROWTH360_RAZORPAY_WEBHOOK_SECRET', '...' );
```

- Order creation: `POST /v1/orders` server-side.
- Checkout: Razorpay Checkout with the server-created `order_id`.
- Verification: server verifies `HMAC-SHA256(order_id|payment_id)` and fetches
  the payment from Razorpay to confirm status, order and amount.
- Webhooks: `payment.captured`, `payment.failed`, `refund.processed`,
  `subscription.charged`, `subscription.halted`, `subscription.cancelled`.

### Stripe (optional)

```php
define( 'BIZGROWTH360_STRIPE_SECRET_KEY', 'sk_...' );
define( 'BIZGROWTH360_STRIPE_PUBLISHABLE_KEY', 'pk_...' );
define( 'BIZGROWTH360_STRIPE_WEBHOOK_SECRET', 'whsec_...' );
```

- Checkout Session creation server-side.
- Return page verifies the session server-side (`payment_status=paid`).
- Webhooks: `checkout.session.completed`, `invoice.paid`,
  `invoice.payment_failed`, `customer.subscription.deleted`.
- Stripe webhook signatures are verified with timestamp tolerance.

### Security rules

- Secret keys are read only from constants/environment. They are never stored in
  WordPress options, returned by APIs, rendered in HTML/JS, or written to logs.
- The frontend success callback is **never** trusted. A subscription is
  activated only after server-side verification or a signed webhook.
- Raw card data is never handled or stored.
- Gateway order/payment IDs and non-sensitive metadata are stored.

### Webhook endpoints

```
POST /wp-json/bizgrowth360/v1/billing/webhook/razorpay
POST /wp-json/bizgrowth360/v1/billing/webhook/stripe
```

Duplicate events are recorded once in `bg360_webhook_events`; repeated delivery
returns success without re-activating or re-invoicing.

## 6. Entitlement Enforcement

Central service: `Billing\Entitlements`.

Enforced server-side for:

- App creation and application count
- Build initiation
- Monthly build quota
- Concurrent builds
- Storage
- APK / AAB / IPA access
- Native features and push notifications
- Design Studio customizations
- Custom branding (custom icon/splash)

Build enforcement runs in both the Super Admin build flow (`BuildManager`) and
the customer portal build flow. Frontend restrictions are only a convenience.

### Quota policy

- A build reserves one `build_started` unit after the build record is created and
  before GitHub dispatch.
- Validation and dispatch failures release the reservation.
- Cancelled builds **do count** toward the monthly quota.
- Success/failure/cancellation and APK/AAB/IPA metrics are recorded separately,
  idempotently.
- Duplicate build IDs or repeated status polling cannot double-count.
- Concurrent builds are counted from active `bg360_builds` rows per workspace.
- Storage is estimated from stored artifact sizes and cached for five minutes.

Usage periods follow the subscription period; without a subscription they fall
back to the calendar month.

## 7. Coupons

Super Admin: **Billing & Plans → Coupons**.

Supports fixed/percentage discounts, maximum discount, applicable plans, start
and expiry dates, total usage limit, per-customer limit, first-time-customer
restriction and active/inactive state. Eligibility and discount calculation are
server-side. Redemption is recorded only after a verified payment and is unique
per transaction.

## 8. Invoices and Tax

Invoices are generated from successful transactions:

- Sequential unique number (`BG360/YYYY/NNNNNN`) using a transaction-protected
  counter.
- Business name, customer name/email, address, optional GSTIN, plan, billing
  period, subtotal, discount, taxable amount, tax rate/amount, total, payment
  status and gateway reference.
- Secure HTML download with capability/ownership and nonce checks. The document
  can be printed to PDF by the browser.
- Refunds update the invoice to `refunded`/`partially_refunded`.

Tax configuration: **Billing & Plans → Tax**.

- Enable/disable tax
- Configurable label and rate (not hardcoded)
- Inclusive or exclusive pricing
- Country/state fields
- GSTIN capture
- Invoice tax breakdown

Tax is calculated server-side. The module does **not** claim legal GST
compliance; rates, place of supply and invoice requirements must be validated by
the merchant/tax adviser.

## 9. Upgrade and Downgrade

- Upgrade quotes the new plan, optional proration credit (setting), coupon and
  tax. Payment is verified before entitlements change.
- Downgrade is scheduled for the next billing period. The UI reports usage that
  exceeds the target plan (apps, monthly builds, storage).
- Existing apps are never silently deleted.
- After the downgrade takes effect, new operations are blocked when they exceed
  the lower plan.

## 10. Renewals, Grace and Cron

WP-Cron hook: `bizgrowth360_billing_cron` (hourly). It:

- Expires ended trials.
- Marks ended paid periods past due and starts grace.
- Suspends after grace (configurable).
- Sends renewal reminders at configured days (default 7, 3, 1).
- Processes scheduled downgrades on renewal.
- Records all actions in the audit log.

Production sites should run a real system cron every 5–15 minutes:

```bash
*/10 * * * * cd /path/to/wordpress && wp cron event run --due-now
```

A **Run billing lifecycle now** button is available in the admin dashboard for
manual checks.

## 11. Notifications

Configurable templates (Billing & Plans → Notifications):

Trial started, subscription activated, payment success/failure, renewal reminder,
expiry, grace period, upgrade, downgrade, cancellation, refund, quota warning and
quota exhausted.

Placeholders: `{site_name}`, `{customer_name}`, `{plan}`, `{amount}`,
`{currency}`, `{renewal_date}`, `{invoice}`, `{days}`, `{status}`, `{used}`,
`{limit}`, `{billing_url}`.

Delivery uses `wp_mail`; configure SMTP for production deliverability.

## 12. Admin and Customer UI

### Super Admin

**App Builder → Billing & Plans** tabs:

- Dashboard: gross revenue, refunds, net revenue, MRR, ARR run rate, active/trial/
  past-due/grace/suspended/cancelled counts, payment failures, plan distribution,
  date filters.
- Plans, Subscriptions, Payments, Invoices, Coupons, Tax, Notifications,
  Settings, Webhooks & Audit.

Metric rules: gross revenue is successful payments in the range; net revenue is
gross minus refunds; MRR is the normalized monthly value of paid active/grace/
past-due subscriptions (annual/12); manually comped subscriptions are excluded;
ARR is MRR × 12 (run rate, not contracted annual value).

### Customers

- Pricing page: `[bizgrowth360_pricing]` and `/app-builder-pricing/`.
- Billing dashboard: `[bizgrowth360_billing_dashboard]` and
  `/app-builder-billing/`.
- Customer portal navigation includes **Billing & Invoices** and **View plans &
  upgrade**.
- Shows current plan, status, renewal, usage bars, remaining quotas, plan
  features, billing profile, invoices, payments and subscription history.

Standalone fallback routes render even when the active theme does not render
page shortcode content.

## 13. Security

- Capability checks (`manage_options` for platform admin; workspace ownership for
  customers).
- Nonces for admin-post, AJAX and invoice downloads.
- Signed webhook verification with `hash_equals`.
- Server-side amount/currency verification.
- Idempotency keys and unique constraints for payments, webhooks, usage and
  coupons.
- Tenant isolation on transactions, invoices, builds, apps and usage.
- Prepared SQL and escaped output.
- Rate limiting on customer billing AJAX.
- Secrets only from constants/environment.
- Audit log for billing actions.

## 14. Testing

Local WordPress test install (`bg360-test`) with the plugin activated:

- **Billing schema/integration:** 50/50 checks passed — tables, plan columns,
  workspace attribution, subscription provisioning, app limit, build reservation,
  duplicate reservation, outcomes, release, cancel/resume/past-due/reactivation,
  history, tax, coupons, transactions, invoice numbering/idempotency, metrics.
- **Build enforcement:** 12/12 checks passed — workspace/app attribution, concurrent
  limit, app limit, push entitlement, monthly build quota.
- **Payments/webhooks/refunds:** 11/11 checks passed — pending transaction, signed
  Razorpay webhook, activation, invoice, duplicate webhook, no duplicate invoice,
  bad signature rejection, refund invoice status and refund transaction.
- **UI rendering:** 17/17 checks passed — pricing shortcode, dashboard shortcode,
  all 10 admin tabs.
- **Pure PHP unit tests:** 19/19 passed — tax math, coupons, plan sanitization,
  design entitlement, usage remaining, Razorpay/Stripe signature verification.

No real Razorpay/Stripe sandbox transaction was performed because no gateway
credentials were available in this environment. The gateway adapters and webhook
signature paths were exercised with locally computed HMAC signatures.

## 15. Screenshots

- `docs/screenshots/billing-pricing.png` — public pricing page.
- `docs/screenshots/billing-dashboard.png` — customer subscription dashboard.
- `docs/screenshots/billing-admin.png` — Super Admin billing dashboard.

## 16. Known Limitations

- No real Razorpay/Stripe sandbox checkout, refund or webhook delivery was run.
- No automatic recurring charge is created through Razorpay Subscriptions or
  Stripe Billing; renewals are driven by verified webhooks/admin action plus the
  lifecycle cron. A gateway subscription ID can be stored for future automation.
- Proration is a simple remaining-period credit, not a full tax/invoice
  recalculation engine.
- Invoices are downloadable HTML, not server-generated PDF.
- Tax configuration is not a certified GST filing/compliance product.
- The existing plugin still has one app configuration per workspace; the app
  registry enforces distinct package IDs but is not a full multi-app config CRUD.
- The local test used a fake GitHub client for build enforcement; real GitHub
  dispatch is not part of the billing tests.

## 17. Setup Checklist

1. Configure Razorpay/Stripe constants in `wp-config.php`.
2. Add webhook endpoints in the gateway dashboard with the matching secrets.
3. Configure tax settings and invoice details.
4. Review seeded plans/prices and edit them in Billing & Plans → Plans.
5. Configure SMTP and notification templates.
6. Configure a real system cron for `bizgrowth360_billing_cron`.
7. Run a sandbox transaction and webhook test before enabling live keys.
8. Verify invoice download and refund flow in sandbox.

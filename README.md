# GPS Product Hub

GPS Product Hub is planned as a Laravel modular monolith for central product intake, catalog operations, readiness, and safe migration away from the current plugin-driven WooCommerce/marketplace chain.

Current implementation status: **MVP Ticket 1 foundation only**.

Implemented foundation scope:

- Laravel application skeleton.
- Filament admin panel provider and placeholder navigation.
- Basic authentication user model.
- MVP role enum and role seeder.
- Integration safety feature flags disabled by default.
- Placeholder admin pages only; no product, staging, sync, or marketplace workflows are implemented yet.

Planning documents:

- `docs/gps-product-hub-blueprint.md`
- `docs/gps-product-hub-implementation-plan.md`
- `docs/gps-product-hub-mvp-specification.md`

## PayU Checkout REST integration

Required environment variables (do not commit real credentials):

```dotenv
PAYU_ENV=sandbox # or production
PAYU_CLIENT_ID=
PAYU_CLIENT_SECRET=
PAYU_MERCHANT_POS_ID=
PAYU_SECOND_KEY=
PAYU_CURRENCY=PLN
PAYU_CONTINUE_URL=https://gpswiss.pl/zamowienie/payu/powrot
PAYU_NOTIFY_URL=https://gpswiss.pl/payu/notify
```

PayU endpoints are selected from `PAYU_ENV`: production uses `https://secure.payu.com`, sandbox uses `https://secure.snd.payu.com`.

Customer flow: `/zamowienie` creates a local order, creates a PayU Checkout REST order, redirects to PayU, and returns to `/zamowienie/payu/powrot`. The local order is marked paid only by a verified `/payu/notify` notification with status `COMPLETED`.

Admin diagnostics (authenticated): `/admin/tools/payu-diagnostics` shows redacted config. Add `?oauth=1` to test OAuth, `?dry_order={id}` to show a create-order payload, or `?payu_order_id={PayU orderId}` to fetch PayU order status.

# OC ValueCard

Plug-and-play **ValueCard loyalty** integration for WooCommerce. Members pull their
points/balance, redeem them at checkout, and usage is reported back to ValueCard —
credentials and behaviour are fully configurable per site, so the same plugin drops
onto any WordPress + WooCommerce store.

Built as a clean replacement for the legacy `valuecard-loyalty` plugin, whose core
defect was that it never fired the *commit* step (in a 332 KB production log: 92
benefit *quotes*, **0 commits**) — so ValueCard never learned that points were spent.

---

## Highlights

- **Pull points** on login (account phone) and at checkout; guests identify via **phone + OTP**.
- **Auto club benefits** (e.g. "5% off") applied automatically in the cart, independent of points.
- **Redeem points** as a separate, explicit action — shown as its own cart line.
- **Commit to ValueCard** on the order status you choose in settings (default *Processing*) — the bug the old plugin had.
- **Void** on cancel/refund.
- **Join the club** for non-members (toggle + info popup) → enrolment at order completion.
- **HPOS-compatible** (uses the order CRUD API, not `get_post_meta`).
- **Themeable** primary colour, full Hebrew translation + `.pot` for more languages.
- **Safe, redacted, opt-in logging** in a protected folder.
- **Self-updating** from GitHub Releases.

---

## Requirements

- WordPress 5.8+ (tested on 7.0), PHP 7.4+
- WooCommerce 6.0+
- ValueCard POS credentials (per store)

---

## Architecture

```
oc-valuecard/
├── oc-valuecard.php              Bootstrap: constants, HPOS declaration, WooCommerce guard, updater
├── uninstall.php                 Removes the option + protected log dir
├── includes/
│   ├── class-ocvc-plugin.php     Singleton loader; wires the components
│   ├── class-ocvc-settings.php   Settings page + storage (single option `ocvc_settings`)
│   ├── class-ocvc-api.php        ValueCard client: SOAP V5 (points/benefits) + REST (token/OTP)
│   ├── class-ocvc-member.php     Identity (login/OTP) + balance; per-session state in the WC session
│   ├── class-ocvc-checkout.php   Loyalty box, auto benefit + points redemption, live fragments, shortcode
│   ├── class-ocvc-order.php      Persist → enrol → commit (on chosen status) → void
│   ├── class-ocvc-logger.php     Redacted, protected, opt-in logging
│   └── class-ocvc-updater.php    GitHub-Releases auto-updater (no external library)
├── assets/{css,js}/              Front-end (checkout box + modals)
└── languages/                    he_IL (.l10n.php + .mo + .po) and the .pot template
```

Class prefix `OCVC_`, text domain `oc-valuecard`, single option `ocvc_settings`.

---

## ValueCard API (two generations, both wrapped by `OCVC_API`)

**Legacy SOAP V5** — `https://ws.valuecard.co.il/pos/V5/Default.asmx`
Every request carries a `<Common>` credential block (VCToken, POSId, POSPassword, CashiersPassword).

| Method | Op | Purpose |
|--------|----|---------|
| `card_information()` | `CardInformationEx` | Pull balance (`PrepaidBalance`), member details, active benefits |
| `get_benefits_query()` | `GetBenefitsQuery` | Quote a transaction → returns `TransactionId`, `Discount`, `GivenPointsRedemption`, `MoneyBack` (accrual), `GivenPromoIDs` |
| `commit_benefits()` | `GetBenefitsCommitQuery` | **Finalise** the redemption (uses the `TransactionId`) |
| `void_transaction()` | `VoidTransaction` | Cancel a transaction |
| `register_member()` | `RegisterClubMemberEx` | Enrol a new member |

**Newer REST** — `https://valuecard.co.il/api/Woocommerce/`
`GetAuthToken` → bearer (a bare JWT), then `SendAuthOtp` / `VerifyAuthOtp`.

Key behaviours learned from production:
- The club benefit (promo) is applied on **every** `GetBenefitsQuery`, even with `CouponNum=-1` (no points). So the plugin auto-quotes with `-1` to surface the benefit, and re-quotes with the amount when the member redeems points.
- `GetBenefitsQuery` **requires** a populated `<JsonItems>` (cart lines) — an empty payload returns `-10101 "parameter missing or invalid"`.
- `GetAuthToken` returns a **bare JWT string** (not JSON) — the token extractor handles bare / quoted / object shapes.
- `MoneyBack` = points the order will earn (used for the "you'll earn X points" line).

---

## Checkout flow

1. **Identify** — logged-in members use the account phone; guests tap **"I have a membership"** → phone + OTP.
2. **Auto benefit** — once identified, `ensure_benefit_query()` runs on `woocommerce_cart_calculate_fees` (checkout context) with `points_to_consume = -1`, applying the club benefit as a **"Club benefit"** cart fee.
3. **Redeem points** — the member opens the redeem field (defaults to their full balance), confirms, and the quote re-runs with that amount; the points portion appears as a **"Points redemption"** cart fee. The `TransactionId` is stored in the session.
4. **Order** — on `woocommerce_checkout_create_order` the card, `TransactionId`, points and join flag are copied to order meta (HPOS-safe).
5. **Commit** — on the configured status (`woocommerce_order_status_changed`), `commit_benefits()` finalises usage at ValueCard (idempotent). Non-member "join" opt-ins are enrolled here.
6. **Void** — on cancel/refund, the committed transaction is voided.

The box is registered as an order-review **fragment** (`#ocvc-box-wrap`) so it refreshes live on every `update_checkout`.

---

## Settings (WooCommerce → OC ValueCard)

- **Credentials:** VC Token, POS ID, POS Password, Cashier's Password
- **Behaviour:** commit status (dropdown), pull on login/checkout, void on cancel/refund, debug logging
- **Appearance:** primary colour
- **Guest redemption (OTP):** redeem button label, member-login label, sign-in title + explanation, send-code label
- **Join the club:** enable, checkbox label (text in `[brackets]` becomes the info-popup link), popup content (WYSIWYG)

> Robustness: settings sanitisation preserves fields absent from a stale/partial form (a hidden `__submitted` marker + merge with the stored option), so an old admin tab can't wipe values.

---

## Custom / non-standard checkouts

The box hooks `woocommerce_review_order_before_payment` (standard) and `woocommerce_checkout_payment` (early), with a render-once guard. For fully custom checkouts, a shortcode is available:

```
[oc_valuecard]
```

Place it wherever you want the loyalty area. If you place it manually, also remove the auto hooks in that template so it isn't rendered twice:

```php
if ( class_exists( 'OCVC_Checkout' ) ) {
    remove_action( 'woocommerce_checkout_payment', array( 'OCVC_Checkout', 'render_box' ), 5 );
    remove_action( 'woocommerce_review_order_before_payment', array( 'OCVC_Checkout', 'render_box' ), 10 );
}
```

Custom themes that render the order **total** outside the standard review fragment can register their element via the `ocvc_order_total_mirror_selectors` filter so the discount reflects live:

```php
add_filter( 'ocvc_order_total_mirror_selectors', function ( $m ) {
    $m['.my-custom-total'] = 'span';
    return $m;
} );
```

---

## Auto-updates (GitHub Releases)

`OCVC_Updater` gives one-click updates from a GitHub repo — no external library.

**Configure** the repo (defaults to `OriginalConcepts/oc-valuecard`). Override in `wp-config.php`:

```php
define( 'OCVC_UPDATE_REPO', 'OriginalConcepts/oc-valuecard' );
// Private repo only:
define( 'OCVC_UPDATE_TOKEN', 'ghp_xxx' ); // GitHub PAT with repo read
```

**Release flow:**
1. Bump the `Version:` header in `oc-valuecard.php`.
2. Commit and push.
3. Create a GitHub **release** with a tag like `v0.2.0`.

Within a few hours (or after "Check again" on the Updates screen) every site sees the update and can one-click install. The updater renames GitHub's zipball folder to `oc-valuecard` automatically.

---

## Development / deploy notes

- Built locally; deployed to sites by uploading the folder to `wp-content/plugins/oc-valuecard/`.
- **Always confirm the active theme** (`stylesheet` option) before editing theme templates — a site can run a renamed backup folder (e.g. `theme.bak-YYYYMMDD`).
- Debug logging writes to `wp-content/uploads/oc-valuecard/` (protected, credentials redacted). Turn it off in production.

## Changelog

### 0.1.0
Initial release — pull/redeem/commit, OTP, join-the-club, auto benefit, points UI, themeable colour, i18n (he_IL), auto-updater.

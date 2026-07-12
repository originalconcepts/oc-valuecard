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
- **Reserve early, settle late** — points commit on the status your orders land in (locking the redemption so it can't be spent on a second order), re-sync automatically when staff edit the order, and lock on completion.
- **Void** on cancel/refund — fully reverses the spend *and* the accrual (works even after completion).
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
5. **Reserve → re-sync → settle** — see the commit lifecycle below.
6. **Void** — on cancel/refund, the committed transaction is voided (returns the spent points *and* cancels the accrual), even after the order was completed and locked.

### Commit lifecycle (why a single "commit on status" isn't enough)

ValueCard has **no** reserve-vs-capture and **no** amend: one `GetBenefitsCommitQuery` atomically settles **both** the redemption (spend) and the accrual (`MoneyBack` earn) on one quote `TransactionId`; the only way to change a committed transaction is `VoidTransaction` (a full reversal — verified against a live account) then re-quote + re-commit. Because an order is almost always edited after checkout (weight/qty/out-of-stock), the plugin uses three phases, all reading the **order** (not the cart, which is gone):

1. **Reserve** — on the first ticked *reserve status* the order reaches (`woocommerce_order_status_changed`), it commits a **fresh quote built from the order** (`build_json_items_from_order()`), enrolling any "join" opt-in first. This locks the redemption early so the same points can't be spent on a second order, and reports the accrual. It stores an order **signature** (`_ocvc_signature`).
2. **Re-sync on edit** — on `woocommerce_saved_order_items`, if the signature changed it runs **void → re-quote-from-order → commit**, keeping both redemption and accrual matched to the edited order. Guarded so it only fires on a real change; a `_ocvc_resync_state` marker and loud order notes surface any interruption; a dropped-redemption or a post-void failure is flagged for manual review rather than silently corrupting the balance.
3. **Settle & lock** — on the *settle status* (default *Completed*), a final re-sync-if-changed then `_ocvc_locked` freezes the member's points at the shipped-order amount. An order that skipped the reserve status self-heals here.

The box is registered as an order-review **fragment** (`#ocvc-box-wrap`) so it refreshes live on every `update_checkout`.

---

## Settings (WooCommerce → OC ValueCard)

- **Credentials:** VC Token, POS ID, POS Password, Cashier's Password
- **Behaviour:** reserve statuses (multi-select — where points commit; default *On hold* + *Processing*), settle status (where points lock; default *Completed*), re-sync on edit, pull on login/checkout, void on cancel/refund, debug logging
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

### 0.4.1
Club tab title is admin-managed (new *"Club tab title (My Account)"* setting, default "Customers club"). Non-members visiting the club tab now get the club pitch + a pre-filled enrolment form and can join directly from My Account (registration fires immediately — no order needed); the membership is linked to the billing phone.

### 0.4.0
My Account "Club" tab (`/my-account/valuecard-club/`): points balance, member-since date, profile details and a **History** tab of recent accruals/redemptions (RecentCardActivity). Profile editing (UpdateClubMember, full form incl. birthday/anniversary/gender + marketing consent) is built in but ships behind the *"Let members edit their club details"* setting — the operation currently returns a server error for web-POS credentials and must first be enabled by ValueCard; until then the details show read-only. New API methods: `club_member_details()` (dormant — empty response for web-POS), `update_club_member()`, `recent_card_activity()`.

### 0.3.3
Join form: the birthday and anniversary date pickers now open in English (`lang="en"`, LTR) instead of following the site language.

### 0.3.2
Durable checkout placement: the loyalty box now relocates itself (JS) to sit as its own section above the payment card when a theme's only render hook places it inside the payment area (deliz-short). Theme-template edits are no longer required — theme deploys can't break the placement anymore.

### 0.3.1
Stability release — the full v0.2.0/v0.3.0 feature set (reserve → re-sync → settle lifecycle, enrolment form, redemption fixes) verified working on a live store.

### 0.3.0
Join-the-club enrolment form: turning the join toggle on opens a popup form pre-filled from the billing fields (first/last name, phone, email) with optional birthday, anniversary and gender, plus an email/SMS marketing-consent checkbox. The confirmed details are stored on the order and sent in full to `RegisterClubMemberEx` (schema-ordered, incl. `IsMale`/`GenderId`, `BirthDate`, `AnniversaryDate`, separate `MessageAccept`/`TermsConsent`). Member details popup now also shows the anniversary and "member since" dates. Checkout box matches the deliz card style on that theme; mobile layout tightened.

### 0.2.0
Commit lifecycle reworked for order edits: **reserve** on configurable status(es) (multi-select, default On hold + Processing), **re-sync** to ValueCard when an order is edited (`void → re-quote-from-order → commit`, verified-safe), **settle & lock** on the final status, and full **void** on cancel/refund (reverses spend + accrual). New order-based quoting (`build_json_items_from_order`, `transaction_sum_from_order`, `order_signature`) so quotes can run from a saved order with no cart/session. Fixes the "commit never fired" case where the order's landing status didn't match the single configured status.

### 0.1.0
Initial release — pull/redeem/commit, OTP, join-the-club, auto benefit, points UI, themeable colour, i18n (he_IL), auto-updater.

# UI checklist (Phase 9b)

Screens to review before/after screenshots:

| # | Screen | URL |
| --- | --- | --- |
| 1 | Overview (KPIs, attention, activity timeline) | `wp-admin/admin.php?page=ratebridge-woo` |
| 2 | Overview — timeline with a long error expanded | same tab → «بیشتر» on an error row |
| 3 | Currencies (list + filters) | `…&tab=currencies` |
| 4 | Currencies — row with an error badge | same tab (a row whose source was rejected) |
| 5 | Currency add/edit dialog | same tab → «افزودن ارز» |
| 6 | Formulas list (badge / switch / kebab) | `…&tab=formulas` |
| 7 | Formulas — kebab menu open | same tab → «⋮» |
| 8 | Formula editor + test panel | same tab → «افزودن فرمول» |
| 9 | Price sources (4 provider cards, equal heights) | `…&tab=sources` |
| 10 | Price sources — key revealed + «بیشتر» open | same tab |
| 11 | Price sources — auth-error state | same tab (wrong key saved) |
| 12 | General settings (incl. «نمایش تاریخ شمسی») | `…&tab=general` |
| 13 | Import / Export — step ۱ empty | `…&tab=import-export` |
| 14 | Import / Export — step ۲ with a file dropped | same tab → drag a file onto the zone |
| 15 | Import / Export — step ۳ executed + backups table | same tab |
| 16 | Product editor panel (simple) | `wp-admin/post.php?post=<id>&action=edit` |
| 17 | Product editor preview card | same → «پیش‌نمایش قیمت» |
| 18 | Products list column | `wp-admin/edit.php?post_type=product` |
| 19 | Bulk edit panel | products list → select → Bulk actions → Edit |
| 20 | Frontend: rates table (3 styles) | a page with `[rbw_rates style="cards"]` |
| 21 | Frontend: ticker / product notice (Jalali `%updated_date%`) | single product page |

## Widths

Check every screen at **360, 768, 1280, 1600** px with WordPress' admin menu both
**folded** and **expanded**, in `fa_IR` (RTL) and `en_US` (LTR).

- At 360 px: no horizontal page scroll; the tab strip scrolls horizontally; the
  dialog fills the screen; buttons are ≥ 40 px tall; the currencies table becomes
  stacked cards with a visible separator between currencies.
- At 768 px: 2 KPI columns; cards stack; the dialog is 100 % wide.
- 1280/1600 px: KPI row of 4; the two-column card grid; centred dialog.

### Phase 9b specifics per width

- **360 px** — provider cards are a single column (`grid-template-columns: 1fr`,
  no fixed 360px track); the API-key field plus its eye toggle still fit on one
  line; the import stepper wraps onto two lines instead of scrolling; the
  drop zone keeps a ≥ 40 px tap height.
- **768 px** — cards are 1–2 columns; the status cell expands from 22ch to full
  width and the error clamp goes from 2 to 3 lines.
- **1280/1600 px** — all provider cards in a row are exactly the same height and
  their «ذخیره / تست اتصال / بازنشانی شمارنده‌ها» rows are aligned.

## Bidi (important for fa_IR)

1. Overview KPI «آخرین به‌روزرسانی موفق» shows `1405-08-07 20:53` with the year
   first and the time last — not `20:53 1405-08-07`.
2. General → rounding preview reads `پیش‌نمایش (1,234,567): <value>` with the
   opening bracket on the right of the digits (Persian reads right-to-left, so
   the bracket must appear before the number in logical order).
3. Currencies toolbar «بعدی: …» and the update log keep the timestamp in one
   piece; it never splits across the line.
4. Activity messages stay in place when the source error contains Latin
   fragments (`(HTTP 401)`, `IP`, `fx-ntz-…`).
5. Nothing renders as `2026-10-29` reversed, and no `[U+2068]` boxes appear.

## Jalali dates

1. With the site locale `fa_IR`, General → «نمایش تاریخ شمسی» is **on** by
   default; with `en_US` it is **off**. Unticking it switches every screen back
   to Gregorian without a reload of any other setting.
2. The same setting controls: overview KPIs, timeline, currencies (last updated,
   log), provider «next allowed», backups, `%updated_date%`, the product notice
   and every shortcode.
3. Time stays 24-hour in the site timezone (Tehran = `+03:30`), so a UTC
   `17:23` renders as `20:53`.
4. «ارقام فارسی» still governs the digits: `۱۴۰۵-۰۸-۰۷` with it on,
   `1405-08-07` with it off — including the clock.

## Fonts (Phase 9c)

The admin UI loads **Vazirmatn** from `assets/fonts/`. This regressed once, so
check it explicitly on every page:

1. DevTools → **Network** → filter `woff2`: exactly three requests
   (`Vazirmatn-Regular`, `-Medium`, `-Bold`) must return **200**, none **404**.
2. `http://<site>/wp-content/plugins/ratebridge-woo/assets/fonts/Vazirmatn-Bold.woff2`
   opens the file (it is a binary, not an HTML error page).
3. The panel headings render in Vazirmatn, not the WordPress system font stack —
   easiest to see on a Persian letter with a distinctive «گ» or «ژ».
4. `assets/fonts/OFL.txt` exists and mentions Vazirmatn and the SIL Open Font
   License 1.1.

`php tests/css-assets.php` guards all of this: it resolves every `url(...)` in
every stylesheet against its own file and fails on a missing target.

## Kebab menu portal (Phase 9c)

`.rbw-table` keeps `overflow: hidden` (rounded corners) and `overflow-x: auto`
on mobile, both of which clip an in-place popup. The menu is therefore moved to
`<body>` while open. Verify:

1. **Last row of a short list (2 rows)** — currencies and formulas: open the kebab
   on the *last* row. The menu must be fully visible, not cut off at the table
   edge, and every item must be clickable.
2. **First and middle rows**, long list (10+ currencies) — same check.
3. **Flip** — scroll so a last row sits near the bottom of the viewport (or zoom
   to 200 %): the menu opens **above** the toggle instead of being clipped.
4. **No scrollbar** — opening a menu must not add a page scrollbar or shift the
   layout.
5. **Widths 360 / 768 / 1280**, RTL and LTR: the menu never leaves the viewport,
   keeps an 8 px margin, and its inline-end edge lines up with the toggle's
   inline-end edge (right edge in fa_IR, left edge in en_US).
6. **Stacking** — the open menu paints **over** the WP admin bar and the table,
   but **under** the confirm dialog and toasts. Open a menu, then trigger a
   delete: the dialog must be on top.
7. **Close on** — click outside; `Esc` (focus returns to the toggle); resizing the
   window; scrolling any ancestor (the page, the table wrapper, the mobile card
   list); `Tab`bing out of the menu; and opening another row's menu (only one is
   ever open).
8. **No orphans** — open a menu, then delete/duplicate a formula so the list
   re-renders: no stray `.rbw-menu` may remain a child of `<body>` (inspect
   `document.body` in the console).
9. **The actions still work** from inside the portalled menu: ویرایش / تکثیر /
   حذف (formulas) and ویرایش / حذف plus the delete confirmation (currencies).

## Console must be clean (Phase 9d)

On **every** plugin tab, the **product editor** and the **products list**, the
DevTools console must show no errors and no 404s:

1. Open DevTools → Console, then visit each tab: Overview, Currencies, Formulas,
   Price sources, General settings, Import / Export.
2. Repeat on `wp-admin/post.php?post=<id>&action=edit` and
   `wp-admin/edit.php?post_type=product`.
3. Expected: zero red lines. In particular no `Failed to load resource: 404`
   for the three Vazirmatn files (see the *Fonts* section for the URLs).
4. Network tab, filter `CSS`: every plugin stylesheet must carry a version that
   matches the request. With `WP_DEBUG` or `SCRIPT_DEBUG` on, expect
   `?ver=dev-<unix mtime>`; in production expect `?ver=<plugin version>`.
5. After editing any plugin `.css`/`.js` in debug mode, a plain reload must pick
   the change up (no hard refresh needed). That is what `AssetVersion` is for.

## Body-level UI rule (Phase 9d)

Design tokens live **only** on `.rbw-admin`. Anything attached outside it must be
appended to `#rbw-portal`, which carries `rbw-admin` + `rbw-typography`.

1. Open a kebab menu → inspect it in DevTools: its parent must be
   `<div id="rbw-portal" class="rbw-admin rbw-portal rbw-typography">`, never
   `<body>` directly.
2. `#rbw-portal` itself must have **no layout box** — `position: static`, zero
   size, `overflow: visible`, `pointer-events: none`. A `position: relative`
   there would re-anchor every `position: fixed` child and break menu placement.
3. The portal host exists on the page **before** any JS runs (view source):
   `<div id="rbw-portal" class="rbw-admin rbw-portal rbw-typography">`.
4. The toast stack (`#rbw-toasts`) must also be a child of `#rbw-portal`.
5. Toggling a menu and re-reading `document.body.children` must never show a
   stray `.rbw-menu`.

### WooCommerce screens (tokens outside `.rbw-admin`)

These surfaces render inside WooCommerce markup, so each one needs the scope:

| Surface | Element | Scope class | Note |
| --- | --- | --- | --- |
| Product editor panel | `.rbw-product-panel` | `rbw-admin rbw-typography` | Full plugin look inside the WC form |
| Products list «ارز» column | `.rbw-col-currency` | `rbw-admin` only | Token-only: native font/size must not change |
| Bulk edit result | `.notice` | none | Plain WordPress notice, no tokens used |
| Frontend shortcodes/widgets | `.rbw-rates-wrap` etc. | own token subset | `frontend.css` defines the tokens it uses |

Check the products list column specifically: the formula slug line (`.rbw-muted`)
must render in the muted grey `#646970`, not in the inherited body colour. Before
this pass it resolved `--rbw-text-faint` to nothing and fell back to inherited
black.

`php tests/ui-scope.php` asserts all of the above statically.

## Product editor: WooCommerce CSS collisions (Phase 9c-fix)

The plugin's «قیمت‌گذاری با نرخ ارز» panel lives inside WooCommerce's product data
metabox, so Woo's own form-field CSS applies to it. It broke once — check it:

1. Open a product → **قیمت‌گذاری با نرخ ارز** → the segmented control
   «قیمت پایه | فرمول‌بندی» must sit **inside** the meta box, aligned with the
   other fields, and must not overlap the right-hand sidebar.
2. In `fa_IR` (RTL) and `en_US` (LTR): the pill keeps its rounded background,
   the two options are side by side, and the description line sits **below** the
   pill (full width), not inline beside it.
3. The pill must not be pulled sideways: WooCommerce floats panel labels with a
   `-150px` negative margin (`float:right` in RTL), which is what pushed the
   control out of the box. `product.css` cancels that with
   `float:none; width:auto; margin:0` on `.rbw-segmented label`.
4. Narrow the window: the pill wraps its description instead of overflowing.
5. Variable products: the same panel in the variation editor behaves the same.

`php tests/css-assets.php` asserts these overrides exist (and that we do not
copy WooCommerce's `162px` label gutter).

## Translations must be recompiled

The Persian UI reads `languages/ratebridge-woo-fa_IR.mo`, which is **generated**
from the `.po`. Editing only the `.po` leaves the site showing the old text —
this shipped a wrong provider name («نواسان» instead of «نوسان») for days.

1. After any `.po` edit: `php bin/build-mo.php`.
2. `php tests/i18n-sync.php` must pass; it fails when the `.mo` content differs
   from the `.po`, when the `.mo` is older than the `.po`, or when a known
   misspelling reappears.
3. Spot-check the label: **منابع قیمت** must read «نوسان», and the provider
   picker in the currency dialog too.

## Keyboard only

1. `Tab` from the admin menu → header actions → tab strip. Arrow keys move
   between tabs; `Home`/`End` jump to the ends.
2. On the currencies list: `Tab` to the switch (space toggles it), then the
   refresh button, then the kebab. `Enter`, `Space` or `↓` opens it and focuses
   the first item; `↑` opens at the **last** item; `↑`/`↓` wrap around,
   `Home`/`End` jump to the ends; `Esc` closes it and returns focus to the
   trigger; `Tab` out of the menu closes it without stealing focus.
3. On the formulas list the same pattern applies: switch → edit icon → kebab
   (ویرایش / تکثیر / حذف).
4. Overview timeline: `Tab` reaches the «بیشتر» summary of a clamped message;
   `Enter`/`Space` expands it and the label changes to «کمتر».
5. Import / Export: `Tab` to the drop zone, `Enter` or `Space` opens the OS
   picker; after choosing a file the dry-run button becomes enabled and is the
   next `Tab` stop. The native input stays reachable for screen readers.
6. Price sources: the eye button toggles the key with `aria-pressed`; the
   disclosure opens with `Enter`.
7. Open the currency dialog: focus moves into the first field; `Esc` closes it;
   editing a field and pressing the backdrop asks «تغییرات ذخیره نشده…» before
   closing; cancel returns focus to «افزودن ارز».
8. Delete a currency: the confirm dialog traps focus between its two buttons and
   `Esc` cancels.
9. Every focused control shows a visible ring (`:focus-visible`), also on the
   segmented control, the drop zone and inside the formula editor.

## Contrast and motion

- Body text on card background is `#1d2327` on `#fff` (≈ 16:1); muted text is
  `#50575e` on `#fff` (≈ 8:1); badges use dark text on the soft tint (≥ 4.5:1).
- «بازنشانی شمارنده‌ها» is a link-styled action in `--rbw-danger-text` on the
  card surface (≥ 4.5:1) and must never look like a primary button.
- Enable «Reduce motion» in the OS: toasts appear without the slide, the row
  flash and the skeleton animation stop, and the ticker becomes a wrapped list.

## Toast + confirm behaviour

- Success, error and warning toasts must each render **styled**: card background,
  left/inline-start accent border, padding, shadow, and stacked bottom-start.
  An unstyled toast (plain text, no box) means it fell outside the token scope —
  check it is inside `#rbw-portal`.
- Toasts must paint **above** the page and the portalled menu, and **below** the
  confirm dialog. Open a toast and a confirm dialog together to verify.

- Every AJAX action reports through a toast: success auto-dismisses after ~5 s,
  errors stay until closed (or a fifth toast pushes the oldest out).
- `prefers-reduced-motion` also disables toast transitions.
- Dropping the wrong file type or an oversized file shows an error toast and
  outlines the drop zone in red; nothing is uploaded.

## Regression guards (must not change)

- Exactly **one** «به‌روزرسانی همه» button exists, and it lives in the page
  header (`#rbw-update-all`). The currencies toolbar no longer has one.
- Every tab has its own icon: chart, coin, formula, link, gear, swap — no two
  tabs share a glyph.
- No English browser chrome («Choose File / No file chosen») appears anywhere in
  the import cards.
- No AJAX action name, payload field or capability changed in these phases.
- Every kebab toggle is a `<button aria-haspopup="menu" aria-expanded>`, points at
  its menu with `aria-controls`, and its items are real `<button role="menuitem">`
  elements — no `<div>`/`<a>` pseudo-buttons.

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

## Keyboard only

1. `Tab` from the admin menu → header actions → tab strip. Arrow keys move
   between tabs; `Home`/`End` jump to the ends.
2. On the currencies list: `Tab` to the switch (space toggles it), then the
   refresh button, then the kebab. Inside the kebab, `↓`/`↑` walk the items and
   `Esc` closes it and returns focus to the trigger.
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
- No AJAX action name, payload field or capability changed in this phase.

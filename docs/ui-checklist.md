# UI checklist (Phase 8b)

Screens to review before/after screenshots:

| # | Screen | URL |
| --- | --- | --- |
| 1 | Overview | `wp-admin/admin.php?page=ratebridge-woo` |
| 2 | Currencies (list + filters) | `…&tab=currencies` |
| 3 | Currency add/edit dialog | same tab → «افزودن ارز» |
| 4 | Formulas list | `…&tab=formulas` |
| 5 | Formula editor + test panel | same tab → «افزودن فرمول» |
| 6 | Price sources | `…&tab=sources` |
| 7 | General settings | `…&tab=general` |
| 8 | Import / Export (3 cards) | `…&tab=import-export` |
| 9 | Product editor panel (simple) | `wp-admin/post.php?post=<id>&action=edit` |
| 10 | Product editor preview card | same → «پیش‌نمایش قیمت» |
| 11 | Products list column | `wp-admin/edit.php?post_type=product` |
| 12 | Bulk edit panel | products list → select → Bulk actions → Edit |
| 13 | Frontend: rates table (3 styles) | a page with `[rbw_rates style="cards"]` |
| 14 | Frontend: ticker / product notice | single product page |

## Widths

Check every screen at **360, 768, 1280, 1600** px with WordPress' admin menu both
**folded** and **expanded**, in `fa_IR` (RTL) and `en_US` (LTR).

- At 360 px: no horizontal page scroll; the tab strip scrolls horizontally; the
  dialog fills the screen; buttons are ≥ 40 px tall; the currencies table becomes
  stacked cards with a visible separator between currencies.
- At 768 px: 2 KPI columns; cards stack; the dialog is 100 % wide.
- 1280/1600 px: KPI row of 4; the two-column card grid; centred dialog.

## Keyboard only

1. `Tab` from the admin menu → header actions → tab strip. Arrow keys move
   between tabs; `Home`/`End` jump to the ends.
2. On the currencies list: `Tab` to the switch (space toggles it), then the
   refresh button, then the kebab. Inside the kebab, `↓`/`↑` walk the items and
   `Esc` closes it and returns focus to the trigger.
3. Open the currency dialog: focus moves into the first field; `Esc` closes it;
   editing a field and pressing the backdrop asks «تغییرات ذخیره نشده…» before
   closing; cancel returns focus to «افزودن ارز».
4. Delete a currency: the confirm dialog traps focus between its two buttons and
   `Esc` cancels.
5. Every focused control shows a visible ring (`:focus-visible`), also on the
   segmented control and inside the formula editor.

## Contrast and motion

- Body text on card background is `#1d2327` on `#fff` (≈ 16:1); muted text is
  `#50575e` on `#fff` (≈ 8:1); badges use dark text on the soft tint (≥ 4.5:1).
- Enable «Reduce motion» in the OS: toasts appear without the slide, the row
  flash and the skeleton animation stop, and the ticker becomes a wrapped list.

## Toast + confirm behaviour

- Every AJAX action reports through a toast: success auto-dismisses after ~5 s,
  errors stay until closed (or a fifth toast pushes the oldest out).
- `prefers-reduced-motion` also disables toast transitions.

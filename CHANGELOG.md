# Changelog

All notable changes to Velk are documented in this file. Format loosely
follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## v3.10.1 - 2026-10-30

> **No database migration, and nothing to change in `site.json` or the
> theme.**

### Added

- **Copy the public link from the top of an item, a page or a term.** Next
  to **View on site**, a button with just a copy icon puts the full address
  on the clipboard and shows a check for a moment. The term screen
  (categories, tags) gains both buttons, as long as the taxonomy has public
  pages.

### Changed

- **A selected image in the media library has a 2px black border.** It
  was 1px and easy to miss next to the unselected ones; the check mark
  stays the same.
- **The top of the item screen: title on its own line, slug and status
  below.** The title shared the first line with the slug, and the status
  sat alone on a second line under a divider. Now the title takes the
  whole first line, and the slug, the status (and the template, for types
  that have one) share the line below, with no divider. On a translation
  tab, the title is on top and the slug below it.

### Fixed

- **"View on site" also shows on a draft.** The item screen only offered
  the button once the item was published, although the site already shows
  a draft to whoever is signed in to the panel — the preview before
  publishing. A draft now has the button too; visitors who aren't signed in
  still get a 404 on that address.

## v3.10.0 - 2026-10-30

> **No database migration, and nothing to change in `site.json` or the
> theme. Everyone is logged out once.** Sessions now live in
> `storage/sessions`, so the sessions saved in the host's folder before the
> upgrade are no longer found: every admin and every site member signs in
> again after the first request, once. The folder is created on demand by
> the PHP that serves the site; if `storage/` is not writable by it, Velk
> keeps using the host's folder, as before. `SESSION_PATH=` (empty) in `.env`
> asks for that on purpose.
>
> **`SESSION_LIFETIME` is no longer read.** A login now lasts 2 days without
> using the panel (admin) and 30 days without using the site (member),
> counted from the last use — see Changed. A site that set
> `SESSION_LIFETIME` for a reason moves it to `ADMIN_SESSION_LIFETIME` and/or
> `MEMBER_SESSION_LIFETIME` (seconds); otherwise the line can be deleted. A
> value that is empty or not a whole number of seconds (`2d`) uses the
> default.

### Added

- **Search in the media library.** The library (`/admin/media`) and the
  window that image, gallery and file fields open to pick a file now have a
  search box. It filters by file name — the original one the grid shows,
  and the one stored on disk — and by the alt text of the default language,
  word by word ("camiseta roxa" finds the alt "Camiseta Velk roxa"), as you
  type, with no Enter: 250 ms after the last key the grid, the
  pagination and the count are swapped without reloading the page. The
  search runs on the server, so it finds files on every page of the library,
  not only on the one open, and in the picker it respects the field's file
  types (`accept`). Typed `%` and `_` are literal. With no match, the window
  says so with the term, instead of "no files in the library yet".

### Changed

- **A login lasts from the last use, not from sign-in — 2 days for the
  admin, 30 days for site members.** One number, `SESSION_LIFETIME` (7
  days), used to set the session cookie, and PHP sends that cookie only at
  sign-in: an admin who used the panel every day was still cut off on the
  7th day, often in the middle of an edit. Now each login has its own limit,
  counted from its last use and adjustable in `.env`, in seconds:
  `ADMIN_SESSION_LIFETIME` (default 172800, 2 days without opening a screen
  of the panel — the public site and the draft preview don't count) and
  `MEMBER_SESSION_LIFETIME` (default 2592000, 30 days without a page that
  reads the member's login). Admin and member share one session, so past
  its limit only that login drops and the other stays. An admin who comes
  back after the limit lands on the login with "Your session expired after
  a period of inactivity" (`error_code: session_idle`). The session cookie
  is resent on every request that opens the session, valid for the longer of
  the two limits, so it no longer expires a fixed time after sign-in. The
  last use is rewritten at most once a minute. No "remember me" and no
  keepalive: there are no background requests while a tab is open.
- **Blocks in the text editor have a drag grip and a remove button.** The
  card a theme block becomes inside a rich text field now shows, like a
  repeater row, a grip on the left — the card could always be dragged, but
  nothing said so — and a × on the right, after **Edit**, that takes the
  block out of the text. No confirmation: Ctrl+Z brings it back. The ×
  also shows on an unavailable block, which until now could only be deleted
  from the keyboard.
- **Theme blocks are picked in the toolbar, not in a window.** **+ Block**
  in the text editor's toolbar used to open an "Insert block" window with
  the list of blocks, and only then the block's form. Now **+ Blocks**
  opens a row below the toolbar with the blocks the field accepts — one
  button each, even when there is only one — and picking one opens the
  window straight on that block's fields. The button stays highlighted
  while the row is open and closes it on a second click.
- **The block window says its title once.** The window of a theme block
  showed the block's name in its header and again inside, above a line.
  Only the header remains.
- **Table controls get their own row in the text editor's toolbar.** The
  five table operations used to sit in the middle of the toolbar as +R,
  −R, +C, −C and ×, greyed out whenever the cursor wasn't in a table —
  nearly always — and named only by their tooltips. They now live in a
  row below the toolbar that appears only with the cursor in a table, with
  their full names: Add row, Remove row, Add column, Remove column, Remove
  table. The **Table** button that creates one stays in the toolbar.
- **Picking a gallery's images: what's selected is the gallery, confirmed
  with Select.** In the media library opened by a gallery field, each
  click used to add the image right away, a second click added it again,
  images already in the gallery wore a separate "already in the gallery"
  mark, and the only way out was ×, which looked like it discarded the
  choice. Now the library opens with the gallery's images selected, and a
  selection looks the same whether it was there before or not: a black
  border and a check mark. A click selects or unselects any
  image; a bar at the bottom counts what is selected — "3 items selected",
  the gallery's included — and **Select** makes the gallery match: what was
  unselected leaves, the rest keeps its place (repeated images included),
  and what was newly selected joins at the end. × and Esc cancel
  without changing anything — Esc also when the focus is inside the
  library. The selection survives the search and the
  pages of the library, and a file uploaded there comes in selected. Fields
  that take a single image or file still pick on the first click, and now
  open the library with their current file marked the same way. The media
  library page no longer reads the `selected` query parameter.
- **The upload area of the media library is compact.** On the library and in
  the picker it took about 180px of height to say "drop files here"; it now
  fits in two short lines — the call and the button on one, the limits
  below — and the grid starts higher, a whole row more of files on the
  first screen of the picker. The whole area still takes the drop.

### Fixed

- **The media library opens over the block window, not inside it.** Picking
  an image, a gallery or a file in a block of the text editor opened the
  library inside the block window — a window within a window, stretched to
  the window's size. It now opens over everything, like anywhere else in the
  panel, and what you pick goes into the block's field. While the library
  is open the block window is hidden — no double shadow, no edge of it
  showing around a smaller library — and comes back as it was, scroll
  included. Esc closes only the library, and closing it keeps the page
  behind from scrolling while the block window is still open.
- **A selected block card keeps its colours.** Clicking a theme block's
  card in the text editor selected it, and the panel's text-selection colour
  painted what was inside: the name and the summary turned green and the
  **Edit** label went dark on the black button. The selected card is now
  shown only by its dark border.
- **Admins no longer get logged out after half an hour with the panel
  open.** Only the session COOKIE had a lifetime (`SESSION_LIFETIME`, 7
  days); the session DATA lived in the host's folder under php.ini's
  `gc_maxlifetime`. On Debian/Ubuntu with PHP-FPM — Cloudways, among others —
  that folder is cleaned by the `sessionclean` cron job every 30 minutes,
  which reads php.ini (24 minutes by default) and ignores `ini_set` and the
  pool, so the cookie promised 7 days and the data died 24 to 54 minutes
  after the last click. The folder is shared by every site on the server, so
  a neighbour with a shorter value deleted Velk's sessions too. Sessions now
  live in `storage/sessions` (`SESSION_PATH`), created with `0700`, out of
  reach of the cron job and of the neighbours, and `gc_maxlifetime` follows
  the session's lifetime (see Changed); PHP's own garbage collector is
  turned on there (1 in 100 requests), since nothing else cleans that
  folder. Only for the files handler: a php.ini that stores sessions in
  Redis or Memcached is left as it is.
- **An expired session on a form no longer ends on a raw "CSRF token
  mismatch." page.** A normal POST of the panel with a token that no longer
  matches — almost always the session that died with the form open: the
  login screen left open, the editor of a page — now goes back where it came
  from, with a "your session expired, submit again" notice and a fresh
  token: to the login when nobody is signed in, to the same screen when the
  Referer is the site's own, to the dashboard otherwise. A Referer from
  another site is never followed. Requests from scripts (`X-Requested-With`,
  `Accept: application/json`, or `Sec-Fetch-Dest: empty` from a plain
  `fetch`) keep getting the JSON 403; a form sent inside an iframe — the
  block window of the text editor — gets a short page there asking to close
  it and reload, instead of the whole editor loaded inside the window. The
  notice is stored as a code (`error_code: session_expired`) and worded by
  the screen that shows it, in that screen's language.

## v3.9.1 - 2026-10-19

> **No database migration, and nothing to change in `site.json`.** On a
> multilingual site, a theme that reads an options page with `options()` now
> prints its translations on the translated pages — check those pages after
> the upgrade (see Changed). Field names and saved values in the admin
> don't change.

### Changed

- **`options($key)` follows the active language, like `option($path)`.** It
  used to return the default-language values on every page, so on `/en`
  `option('footer.tagline')` printed the English text and
  `options('footer')['tagline']` the Portuguese one — the same field, two
  answers, and nothing told the theme author. It now returns the group with
  each translated field over the default one, by the rule `option()` already
  used: an empty value, `null` or a richtext with nothing written (`<p></p>`)
  counts as untranslated and shows the default language. The merge is
  shallow, as in `fieldsIn()` on items and pages: a translated repeater
  replaces the default one as a whole. On the default language, and on a
  site with one language, it reads exactly what it read before, with no
  extra query. New `Option::getGroupIn($key, $language)` behind it.
- **The block window of the text editor is compact.** It used to open at
  the size of the media library — up to 1400×900 — with the list of blocks
  or the block's form in the top corner and the rest empty. It is now
  720px wide and as tall as what is inside, growing as rows are added to a
  repeater, up to the same ceiling as before; past that, the window
  scrolls. While an image field of the block has the media library open, the
  window goes back to the full size, so the library has room for its grid.
- **The block window always starts with the choice of block**, also when the
  field accepts a single one. It used to go straight to that block's form,
  so the window opened on a form without saying what it was for, and the
  path changed with `site.json`.

### Fixed

- **The "Plans & Subscriptions" screen follows the admin's language.** Its
  title, description, labels and help (`/admin/options/subscriptions`, and the
  sidebar link) were written in Portuguese inside the engine, outside the
  admin catalog, so an English or Spanish admin read them in Portuguese. They
  are now `subscriptions.*` keys in `core/Admin/lang/{en,pt,es}.php`. The
  screen is built by `Velk\Admin\SubscriptionOptions`, no longer in `Config`.
- **No language tabs on that screen.** On a multilingual site it showed one
  tab per language, but price, active toggle and currency have no
  translation and the engine reads only the default-language values: what was
  typed in another tab was saved and never used. An option group can now say
  `translatable: false`, and this one does.
- **The price help no longer says "in reais"** when the currency field holds
  USD or EUR. It points at the currency field, and that field's help says
  that subscriptions and the store (`store.currency` in `site.json`) have
  separate currencies — now documented in AGENTS.md.
- **The store's price field follows the admin's language and names the
  store's currency.** The `price` field the engine attaches to every
  `sellable` item type had its label and help written in Portuguese inside
  the engine, and the help said "in reais" whatever `store.currency` held.
  They now come from the admin catalog (`items.sale_price_*`, built by
  `Velk\Admin\SaleFields`), and the help names the currency in
  `store.currency` (`BRL` when absent). The field is still `price`.
- **The built-in SEO fields follow the admin's language.** The "SEO &
  sharing" section already did, but its three fields — `meta_title`,
  `meta_description`, `og_image`, on every page and on every item type with
  a page — had their labels and help written in Portuguese inside the
  engine, so an English admin read an English heading over Portuguese
  fields. They now come from the admin catalog (`items.seo_*`, built by
  `Velk\Admin\SeoFields`); `item:schema` and `page:schema` print them in
  `ADMIN_LOCALE`. Field names and stored values are unchanged.

## v3.9.0 - 2026-10-16

> **No database migration, and nothing to change in `site.json` or the
> theme unless you use blocks.** Two things change what a multilingual site
> prints. A translated richtext that was saved blank (an empty `<p></p>`,
> which the admin used to store for every untouched translation tab) now
> shows the default language's text again — check the translated pages after
> the upgrade (see Fixed, "A translation tab left blank…"). And content
> without a translated slug now has a URL in every language, at its default
> slug: `/en/sobre` used to be a 404 and now opens, and the sitemap, the
> language switcher and `hreflang` list those URLs too (see Changed). The
> canonical and `hreflang` tags also lose a repeated language prefix (see
> Fixed). One thing to check before the upgrade: a theme function named
> `richtext`. The core now declares one. A second declaration is a fatal error
> on every page, and one behind a `function_exists` guard is silently replaced
> by the core's — `grep -rniE "function\s+richtext\s*\(" theme/`, and rename
> it.
>
> **Don't roll back once a text has blocks.** The editor before v3.9.0 doesn't
> know the block marker and drops it when the text is saved again.

### Changed

- **Without a translated slug, content opens in that language at its
  default slug.** On a multilingual site, a post with no English slug used
  to have no English URL at all: `/en/post/meu-post` was a 404, `url()`
  returned `''` while the visitor browsed in English, the language switcher
  sent them to the home page, and the sitemap and `hreflang` left the
  language out. It now opens at `/en/post/meu-post`. Once the English slug
  is filled — `my-post` —, the post answers at `/en/post/my-post` and
  `/en/post/meu-post` goes back to a 404: still one URL per language. The
  same goes for pages (from `site.json` or created in the admin) and terms,
  level by level in a hierarchy (`/en/services/consultoria` when only the
  parent page is translated) — the rule the item type and taxonomy prefixes
  already followed. When one post's translated slug is another post's
  default slug, the translated one wins — level by level in a hierarchy,
  without hiding a child that only exists under the one that lost —,
  unless it's a draft: a draft never takes a published post's URL. The
  one that lost has no URL in that language: `hreflang` and the sitemap
  leave the language out for it, the language switcher sends the visitor
  to that language's home, and the admin warns when the save creates such a
  conflict (see Added). Templates that hid a link when `url()` came back
  empty in another language now show it. The sitemap builds these paths in
  memory, once per item type and taxonomy: the number of queries doesn't
  grow with the number of posts.
- **For theme and engine code: three strict-routing helpers are gone.**
  `Page::findByTranslatedSlug()`, `Page::findAllByTranslatedSlug()` and
  `PathChain::buildStrict()` had no use left in the core. In their place:
  `Page::findAllBySlugIn()`, `Item::findByPath()` / `Term::findByPath()`,
  and `ownsUrlIn()` on `Item`, `Term` and `Page` — whether a record is the
  one that answers at its URL in a language. `Page::fullPath()`,
  `Item::fullPath()` and `Term::fullPath()` now always return a string.

### Added

- **The admin warns when a slug collides in a language.** Saving a post,
  term or page whose translated slug is another one's slug in that language
  (or the other way around) shows a warning under "Saved": which address,
  in which language, and who is left without it. The save goes through.
- **`schema:validate` lists the templates the theme is missing.** A
  template declared in `site.json` whose file isn't in `theme/templates/`,
  with no `page.php`, `single.php` or `taxonomy.php` to fall back on, makes
  its URLs a 404. The command lists it under "Warnings:" — `site.json` is
  still valid and the exit code is still 0, so a deploy that runs the
  validation isn't stopped by it.
- **Theme blocks inside the richtext editor.** A theme declares its own
  content blocks in `site.json` (`richtext_blocks`: a label, fields like a
  `flexible` layout, and the file of its partial) and lists them on the
  richtext fields that accept them.
  The author presses **Block** in the toolbar, fills the block's fields in a
  window, and a card lands in the text where the cursor is — between two
  paragraphs of a blog post, say. On the site the theme prints the field
  through the new `richtext()` helper, which renders each block with the
  partial declared for it, in `theme/partials/richtext/` — bound by file name
  in `site.json`, like a template, never by the block's key: the data is the
  author's, the HTML and the CSS are the theme's. A block takes any field
  type, images included. The field is still one HTML string — the block is
  stored in it as an empty marker tag —, so there is no new table.
  `schema:validate` checks the declaration, the partials and that the theme
  calls `richtext()`. A marker written outside the admin that the block
  window can't show — data that isn't a JSON object, or content inside the
  tag — shows as a card without **Edit**, and saving the text keeps its data
  and what was inside the tag (a pasted marker enters empty);
  `item:create`, `item:update` and `page:update` refuse it in what
  they receive, naming the field and the block. See AGENTS.md → "Richtext
  blocks".
- **The richtext editor has a quote button.** The editor always accepted a
  quote (`<blockquote>`) — typed as `> ` at the start of a line, with
  Ctrl/Cmd+Shift+B, or pasted — but nothing in the toolbar said so. The
  button wraps the current paragraph in a quote and, pressed again, takes it
  out. The saved HTML is a plain `<blockquote>`; styling it on the site is
  the theme's job, as before.

### Fixed

- **The canonical and `hreflang` tags no longer repeat the language
  prefix.** `the_canonical()` and `the_hreflangs()` ran the URL through
  `url()` twice: on `/en/post/my-post` the canonical came out as
  `/en/en/post/my-post`, a 404, the Spanish alternate as `/en/es/…`, and the
  default language's under `/en/` (`/en/post/meu-post`, also a 404 once the
  post has an English slug). On an install in a subfolder the
  base path repeated too, in every language. Broken since v1.4.0. Without
  `APP_URL`, both now take the origin from the request instead of coming
  out relative, and the 404 page no longer announces other-language
  versions of an address that doesn't exist.
- **The language switcher works on nested posts and terms.** On a post of a
  hierarchical type, or a term of a hierarchical taxonomy, three levels deep
  or more, the switcher and `hreflang` kept the current language's slugs
  and led to a 404 in the other language, even with every level
  translated. Broken since v3.0.0.
- **A template missing from the theme is a 404, not an error page.** A
  template declared in `site.json` whose file doesn't exist, with no
  `page.php`, `single.php` or `taxonomy.php` to fall back on, made the
  visitor see a 500 ("Template not found"). It is now a 404, with the
  theme's 404 page when there is one (see Added for the `schema:validate`
  warning).
- **The admin shows a subpage's real address.** The "View" link of a
  subpage, in the page list and in its editor, dropped the parent pages —
  `/consultoria` instead of `/servicos/consultoria`, a 404 —, and the "URL"
  hint of a translation tab showed `…` when the slug wasn't translated and
  ignored the parents. Both now show the address the page answers at.
- **Admin polish.** The trash button of the lists has room on the sides,
  the save bar has no shadow, the side menu's sub-items line up with the
  icon of their parent link, and the languages screen no longer labels the
  default language "not editable".
- **A field `name` that isn't a string no longer raises a PHP warning** in
  `schema:validate` or in the admin form ("Array to string conversion");
  the validation still points at the field.
- **Typing and Tab no longer reach the page behind an open window in the
  admin.** With the media library open, a key typed before clicking inside
  the window, or a Tab, used to land on the form behind — in the richtext
  editor it typed into the text, or replaced whatever was selected there.
  The page behind a window is now inert until the window closes, and the
  focus then goes back to where it was.
- **`item:update` and `page:update` no longer refuse content saved in the
  admin with an empty image or file field.** The admin stores `"0"` in a
  media field nobody filled, and `""` right after **Remove**; the CLI's
  validator only took a media id or `null`. Both commands validate the whole
  item — what is stored plus what the JSON brings —, so a post with an empty
  share image failed with `Field 'og_image' has wrong type for 'image'` even
  when the update only changed its text, and the way around was to send
  every empty image as `null` in the same call. `item:create` refused the
  same values. In an image or file field, `"0"`, `""` and `0` now mean "no
  media" — kept as they came, nothing stored is rewritten —, and on a field
  marked `required` they fail as a missing field instead of a wrong type.
  Broken since v3.2.0.
- **The richtext toolbar stays at the top of the screen while you scroll a
  long text.** The stylesheet always asked for it (`position: sticky`), but
  the editor's box cut its rounded corners with `overflow: hidden`, and that
  makes the box the toolbar's scroll container: the toolbar went away with
  the top of the text, and the author had to scroll back up for every
  button. The editor's box — and the ones an editor can sit in: a repeater
  row, a flexible row, a set of tabs — now cuts with `overflow: clip`, same
  corners, no scroll container. On a narrow screen the toolbar sticks below
  the admin's top bar, and on a phone it is one row that scrolls sideways
  instead of four or five rows. A browser without `overflow: clip` (Safari
  before 16) keeps the corners and the old behaviour.
- **The screen follows what the richtext toolbar inserts.** An image or a
  theme block inserted while the cursor was off screen stayed off screen —
  inserting didn't scroll —, and the first row of a new table landed behind
  the save bar. The screen now goes to the cursor after an image goes in
  (and again once it loads and takes its height) and after a block does.
  And wherever the editor brings the cursor into view, typing included, it
  keeps it clear of the toolbar above and of the save bar below.
- **The Image and Table buttons of the richtext editor no longer replace a
  selected image or block.** Right after an image goes in, the cursor sits in its
  empty caption, and a second image picked from there used to take the place
  of the first. With an image or a block card selected, Image and Table
  swapped it for the new one. And with the cursor inside a caption that had
  text, the image came out twice, one copy on each side of the new image or
  table. The new one now goes right after the selected one, or after the
  image whose caption holds the cursor. Anywhere else it lands where it
  always did — a selected piece of text, or selected table cells, is still
  replaced, as any insertion does.
- **A translation tab left blank no longer blanks the text on the translated
  page.** On a multilingual site, saving an entry in the admin stored an empty
  paragraph (`<p></p>`) as the translation of every richtext field whose
  language tab nobody filled: the text editor never sends an empty value, and
  only an empty value counted as "not translated". The translated page then
  printed that empty paragraph instead of the default language's text —
  against the note on the tab itself, "Blank fields use the default language's
  value". It showed when the entry exists in the other language — a post or a
  page with a translated address, a term, an options page — and its text was
  left for later. A richtext with no text and no media is now an untranslated
  field, when saving and when reading, so what is already stored needs no
  migration and no second save: the default language's text shows again as
  soon as the engine is updated, and the stored empty paragraph goes away the
  next time the entry is saved. A translation with only an image, a table, a
  rule or a theme block is still a translation. Check a multilingual site
  after the upgrade: a translated page that had an empty body now shows the
  default language's. Broken since v1.4.0, when content translations
  arrived.
- **Translations typed before the first save of a new entry are no longer
  dropped.** On a multilingual site, a new post — an entry of any item type —
  created in the admin lost everything typed in the other languages' tabs:
  title, address (slug) and fields. The form sent them, the entry was created
  with the default language only, and the tabs came back empty after the
  save, with no warning. Only a second save, on the entry that by then
  existed, kept them. The translations now go in with the entry. Terms were
  not affected, a new page only gets its language tabs once it exists, and
  `item:create` takes no translations and creates the same entry as before.
  What was lost that way is not stored anywhere: it has to be typed again.
  Broken since v1.4.0, when content translations arrived.

## v3.8.4 - 2026-09-29

> **No database migration — but a site that set its CSP in `.env` has to
> move it.** The public site's policy now lives in `site.json`
> (`security.csp`), and the `.env` variables of v3.8.1–v3.8.3 (`CSP_MODE`,
> `CSP_ALLOW_EVAL`, `CSP_FRAME_ANCESTORS`, `CSP_SCRIPT_SRC`,
> `CSP_CONNECT_SRC`, `CSP_IMG_SRC`, `CSP_FRAME_SRC`, `CSP_FORM_ACTION`) are
> no longer read. Until the block is in `site.json`, a site that set them
> changes, in any mode: `strict` and `report` fall back to `open`, `off`
> starts sending the `open` header (on top of a CDN's own policy), and the
> domains in `CSP_FRAME_ANCESTORS` can no longer frame the site. So write the
> block and deploy the theme **before** the upgrade — older versions ignore
> everything in it except the lists they already read —, upgrade, then
> remove the variables. Each list goes into what the theme already declares
> under `security.csp.allow`: on the old engine the list in force was both.
> While one is still set to something other than the old default, the PHP
> error log names it and its new place on every page, and
> `bin/velk csp:check` reports it. Only `CSP_REPORT_URI` stays in `.env`.
> Nothing changes on a site that never set them.
>
> A list in `security.csp.allow` now restricts its resource by itself;
> before, it only added to a list turned on in `.env`. On a site in
> `strict`, a list the theme declared while the server's stayed empty starts
> to count: make sure it names everything the site loads, or rehearse it on
> the new engine — write the block with `"mode": "report"`, read the reports
> after the upgrade, then set `"mode": "strict"` (a `report` run on the old
> engine rehearses the old policy). A site that used `CSP_SCRIPT_SRC`
> rehearses the same way (see below). And `schema:validate` rejects
> `security.csp.allow.script` from now on: delete it from the theme with the
> block, before the upgrade — with `CSP_SCRIPT_SRC` in `.env`, add the key's
> domains to it first, so the old engine's list stays the same.

### Changed

- **The public site's Content Security Policy moved from `.env` to
  `site.json`** (`security.csp`). It travels with the theme between
  environments, and a domain list no longer needs a variable on the server
  to count: a list declared in `security.csp.allow` (`connect`, `img`,
  `frame`) restricts its resource by itself, to the site and the list, and
  an absent or empty one leaves it free, as before. `allow.form` still adds
  to the `form-action` that strict always sends. The mode, `allow_eval` (now
  a JSON boolean) and `frame_ancestors` (now a list) sit in the same block.
  `CSP_REPORT_URI`, which differs per environment, stays in `.env`.
- **No more script domain list.** `CSP_SCRIPT_SRC` has no counterpart in
  `site.json`: strict always runs on the page's nonce plus
  `'strict-dynamic'`, which covers what the page's scripts load and keeps
  the tags pasted in the admin working. A site that used the list loses its
  host restriction on scripts, and two things the list's `'self'` and hosts
  let through are now blocked: a same-origin script inserted without the
  nonce (Cloudflare's Email Address Obfuscation, under `/cdn-cgi/`) and a
  script a pasted snippet writes with `document.write`. A
  `security.csp.allow.script` in the theme is ignored, and reported.
- **The *Scripts & tracking* notice** names `security.csp.mode` and, for a
  pasted form that sends to another site, `security.csp.allow.form`.

### Added

- **`schema:validate` checks `security.csp`**: a mode or key it doesn't
  know, a block that isn't an object, a list given as an object, an invalid
  source — and the block in the wrong place (`security.mode`, a top-level
  `csp`), which would otherwise be ignored without a line in the log. A
  mistyped mode still falls back to `open` at runtime, with a line in the
  log; now it is caught before the deploy.

## v3.8.3 - 2026-09-29

> **No database migration.** Nothing changes on a site in `CSP_MODE=open`
> (the default). On a site in `strict`, a form can now only send to the site
> itself, to Stripe when subscriptions or the store are on, and to what
> `CSP_FORM_ACTION` or the theme allows; in `report` the same rule is only
> reported. `bin/velk csp:check` points out a theme form whose address is
> written in the template and posts anywhere else — but it reads templates
> as text. An address built in PHP, a form pasted in the admin or built by a
> script, and a form the site answers with a redirect to another site only
> show up in the browser: on a site in `strict`, spend some time in `report`
> (or watch the console) around the upgrade. A violation that names the
> site's own address means the form was redirected somewhere not allowed.

### Changed

- **The strict policy restricts where a form may send (`form-action`).**
  Like `base-uri`, it is always part of the strict policy — enforced in
  `strict`, reported in `report`: to the site itself, so an injected fake
  form — a member login, say — can't send what the visitor types to another
  site. Three things widen it, and they add up: `CSP_FORM_ACTION` in `.env`;
  `security.csp.allow.form` in `site.json`, the destinations the theme's own
  forms post to, counted even with `CSP_FORM_ACTION` empty, since the theme
  only opens an exception in a restriction that already exists; and the core
  itself, which adds `https://checkout.stripe.com` (plus
  `https://billing.stripe.com` for the member portal) when subscriptions or
  the store are on. Chrome applies `form-action` to every redirect after a
  form is sent, not only to its `action` (engines have differed here; list
  the destinations either way): those Stripe buttons post to the site and
  are redirected to Stripe, and so is anything else the site answers with a
  redirect to another site — a `member_require_plan()` fallback pointing at
  an external checkout, a route of the theme's. List those destinations, and
  a Stripe custom domain for Checkout or the portal, in `CSP_FORM_ACTION`.
  `open` sends no `form-action`.

### Added

- **`bin/velk csp:check` points out a theme form posting to another site**
  that nothing allows — the `action` of a `<form>` and the `formaction` of a
  button — and an unknown key under `security.csp.allow` (a typo like
  `frames` used to be ignored in silence). It reads `APP_URL`, so a form
  with the site's own full address isn't flagged. The *Scripts & tracking*
  notice in strict mode mentions forms too: a form pasted there that sends
  to another site needs that address in `CSP_FORM_ACTION`.

## v3.8.2 - 2026-09-28

> **No database migration, nothing to change.** A file swap.

### Changed

- **An admin form only sends data to the site itself.** The admin's Content
  Security Policy gains `form-action 'self'`. With injected scripts already
  refused since v3.8.1, the way left to capture a typed password through
  injected HTML was a fake form — "your session expired, sign in again" —
  posting it to another site. The browser now refuses that submission. `form-action` doesn't fall back to `default-src`, which is why
  the existing `default-src 'self'` didn't cover it. Every admin form already
  posts to the admin, so nothing visible changes. The public site's policy is
  unchanged: there the Stripe checkout and the portal buttons post to the site
  and are redirected to Stripe, a redirect `form-action` also governs, so it
  needs its own list — a later release.

## v3.8.1 - 2026-09-28

The public site gets a Content Security Policy chosen in `.env`: by default
only the locks that break nothing, and a strict mode without
`'unsafe-inline'` — the finding security scanners report — for the client who
needs it. The admin drops `'unsafe-inline'` from its own policy and tells
search engines not to index it.

> **No database migration.** After the upgrade every public HTML page carries
> `Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'` (plus
> `upgrade-insecure-requests` over HTTPS), and every admin response carries
> `X-Robots-Tag: noindex, nofollow`. Check before upgrading:
>
> - A site that another domain shows in an iframe needs that domain in
>   `CSP_FRAME_ANCESTORS`.
> - A theme that declares its own `json_script()`, `csp_nonce()` or
>   `csp_nonce_scripts()` must rename it. The core declares them first, and a
>   second declaration is a fatal error on every page and in the `bin/velk`
>   commands that load the theme, `migrate` included.
> - A theme that sends its own `Content-Security-Policy` with `header()` keeps
>   it, and the core then sends none; drop it and use `CSP_MODE`. One set in a
>   `<meta>` tag applies together with the core's, so remove it too.
> - A policy the hosting panel or the CDN already sends applies together with
>   the core's; if they disagree, set `CSP_MODE=off`.

### Changed

- **The admin no longer allows inline JavaScript.** Its Content Security
  Policy drops `'unsafe-inline'` from `script-src`, so a script injected
  through an escaping bug doesn't run — the finding security scanners report.
  The few inline scripts moved into `admin.js`: the language tabs, the inline
  translation editing and the filters that submit on change (now
  `data-autosubmit`). The JavaScript translations travel in a JSON data block
  — the new `json_script()` helper, which themes can use too — instead of a
  script that set a global, and the panel and the media picker share one list
  of them. Each part of the admin now starts on its own: an error in one — a
  field in an unexpected shape on one screen — goes to the browser console
  instead of stopping every part after it (the language tabs, the translations
  table, the mobile menu). `style-src` keeps `'unsafe-inline'`: the risk of
  injected CSS is small, and removing it would mean rewriting some 170 inline
  styles. Behind Cloudflare with JavaScript detections on, the admin's console
  shows Cloudflare's injected script refused; nothing is enforced unless a WAF
  rule uses the detection's result.

### Added

- **A Content Security Policy for the public site, chosen in `.env`.**
  `CSP_MODE=open`, the default, sends only what breaks nothing:
  `frame-ancestors 'self'` (the same rule as the `X-Frame-Options:
  SAMEORIGIN` the server examples already send), `base-uri 'self'` and, over
  HTTPS, `upgrade-insecure-requests`. `CSP_MODE=strict` runs without
  `'unsafe-inline'`, with a nonce per page plus `'strict-dynamic'`.
  `CSP_MODE=report` keeps the `open` policy enforced and sends the strict one
  as Report-Only, as a rehearsal; `off` sends none.

- **Strict mode keeps the scripts pasted in the admin working.** The core puts
  the page's nonce on every script it prints — antispam, Turnstile, the
  consent runtime, the scripts registered in the admin, conversion events —
  and the theme puts `nonce="<?= csp_nonce() ?>"` on its own, or runs
  third-party HTML it prints through `csp_nonce_scripts()`. Whatever those
  scripts load, GTM's tags included, inherits the trust, so nobody has to list
  GTM's domains. What no nonce unlocks: `on…=` attributes, `javascript:`
  links, scripts written with `document.write`, and `eval()` unless
  `CSP_ALLOW_EVAL=1`. Don't turn strict on with
  edge caching (`CACHE_TTL` above 0): a cached page hands every visitor the
  same nonce. A script a CDN inserts at the edge carries no nonce unless the
  CDN adds it, so run `report` first.

- **Optional domain lists for an IT team**, in `strict` and `report`.
  `CSP_SCRIPT_SRC`, `CSP_CONNECT_SRC`, `CSP_IMG_SRC` and `CSP_FRAME_SRC`
  limit each resource to the site plus the listed domains, and
  `CSP_SCRIPT_SRC` also turns the inherited trust off. The theme declares the domains its templates need in
  `site.json` under `security.csp.allow`; they are added only to lists that
  are on. With Turnstile configured, the script and iframe lists already allow
  Cloudflare when they are on. `CSP_FRAME_ANCESTORS` names who else may show
  the site in an iframe, and `CSP_REPORT_URI` where violation reports go.

- **`bin/velk csp:check`** lists what strict would block in the theme —
  scripts without a nonce, `on…=` and `javascript:` in the templates, a
  policy of the theme's own, `eval` in its JavaScript — and invalid CSP
  settings, exiting with 1 when there is an error. The *Scripts & tracking*
  screen warns in `strict` and `report`.

- **The admin tells search engines not to index it.** Every response under
  the admin prefix — the login, the screens, JSON, downloads, the export and
  the panel's own assets — carries `X-Robots-Tag: noindex, nofollow`, and
  the admin layouts add the matching `<meta name="robots">`. It's the header,
  not `robots.txt`, that keeps a page out of search results: `Disallow` only
  stops the visit, the address can still be indexed from a link, and
  `robots.txt` is public — listing the admin there gives away the path of a
  site that changed it. So `robots.txt.example` no longer mentions the
  admin. Existing sites keep their own `robots.txt`; its `Disallow: /admin`
  lines can go — with them in place, a crawler never fetches the page and
  never sees the `noindex`.

## v3.8.0 - 2026-09-24

A form's submissions can now leave the admin as a CSV — from the panel or
from `bin/velk form:export`, with the columns, the period and the separator
to choose. The submission cards can show a second field under the title, and
the admin works on a phone.

> **No database migration.** Two things look different after the upgrade:
> the closed submission card no longer shows the IP (it's in the export's
> metadata columns), and the admin's single-line fields and buttons share
> one height, so a button at the end of a form row lines up with the fields.

### Added

- **Export a form's submissions as CSV — from the panel and from the CLI.**
  The form screen gains an **Export** block: pick the columns (each form
  field, plus a metadata block and a tracking block for the UTM and click-ID
  columns, both off by default), a period, and the separator, and download.
  `bin/velk form:export <form-id>` writes the same file to stdout, with the
  same defaults — the form's fields, every date, semicolon, UTF-8 BOM — so
  the panel and the command line can't drift apart; both read their rules
  from one place. Only approved, completed submissions go out: spam and
  multi-step leads still in progress stay on the screen. No library —
  `fputcsv()` does the quoting.

- **The export defuses spreadsheet formulas.** Submissions come from
  anonymous visitors and the file opens in the client's Excel or Sheets,
  which execute a cell beginning with `=`, `+`, `-`, `@`, tab or carriage
  return. Every such cell is written with a leading apostrophe, so
  `=HYPERLINK(...)` typed into a name field arrives as text. The side effect
  is deliberate: a phone number typed as `-11 99999` arrives as text too.

- **A second line on the submission cards.** `forms.<id>.preview_subtitle_field`
  names a field shown under the card's title, before the date — the company
  under the lead's name, say. Without it, or blank on a submission, the line
  is just the date. The IP left that line; it's still in the export's
  metadata columns. `schema:validate` rejects a name that isn't a field of
  the form, as it does for `preview_field`.

- **Large forms export in blocks.** Rows are read from the database 500 at a
  time and written out as they are read, so a form with tens of thousands of
  leads is never loaded whole into memory. The blocks advance by id rather
  than by offset, so a submission arriving mid-download can't duplicate a row
  in the file.

### Fixed

- **Logging in takes you back to the full address you asked for.** The admin
  kept only the path of the page that sent you to the login and dropped its
  query string, so a filtered list opened with an expired session came back
  unfiltered. A GET now keeps its query string through the login — which is
  also what brings a saved export link (above) back with its columns and
  period.

- **The button at the end of an admin form row lines up with the fields.**
  Text inputs, date inputs, selects and buttons each took the height the
  browser gave them, and in a row aligned by its bottom edge the shorter
  button sat a few pixels lower. They now share one height; multi-line
  text areas and small buttons keep their own.

- **The admin works on a phone.** The menu button sits in a top bar, so the
  page no longer scrolls under it and the title stays clear. The fields of
  a form row stack one under the other, and every table scrolls sideways
  inside its own box instead of stretching the page. The note beside the
  title of the collapsible blocks (conversion tracking, export) is hidden on
  small screens.

## v3.7.8 - 2026-09-10

One feature and one fix that had been wrong for two releases. Forms can
now be filled in over several steps, saving each one as it arrives; and a
theme written in any language other than English stops showing that
language to English visitors.

> **Runs a database migration** (`form_submissions.pending_step` and
> `step_token`, plus a unique index on `step_token`). Additive and
> idempotent — every row that already exists is a completed lead, and `NULL`
> is what "completed" means on both columns, so nothing needs a backfill.

### Added

- **`forms.<id>.steps` — a form that saves as it goes.** The first step
  creates the lead with its campaign attribution; the rest update the same
  lead until the last one closes it. `steps` partitions **field names**, so
  `fields` stays the single source of type/validation and stored data stays a
  flat map — reorganising steps is two lines of `site.json`, no migration, no
  stored lead touched, though the template's step markup moves by hand.
  `schema:validate` requires an exact partition.

- **Steps require AJAX, and a finished lead closes for good.** An
  intermediate step posted natively gets `ajax_required` instead of leaving
  the visitor stuck. A finished lead is closed (`form_completed`) and its
  token dies with it — so does marking a lead spam in the panel, which
  clears `step_token` in the same update that sets `blocked_by`, while
  keeping `pending_step` so **Not spam** hands back a lead that is still in
  progress instead of one relabelled "finished". Closing a lead is a
  conditional `UPDATE`, so two overlapping final submits produce one e-mail,
  one conversion and one `webhook`, not two. A step token never expires on
  its own; `bin/velk cleanup:incomplete-submissions --days=30` drops
  unfinished leads — blocked rows excepted, since those belong to
  `cleanup:spam-submissions` and its `keep_spam` retention — and, like its
  three siblings, **runs only when scheduled**. `_step` is required on any
  submit that carries a token: omitting it is `invalid_step`, never a silent
  merge into step 1. New codes in `docs/messages.md`: `step_saved`,
  `ajax_required`, `invalid_token`, `form_completed`, `invalid_step`,
  `incomplete_lead`.

- **`forms.<id>.step_webhook` fires on every step, the first included** — the
  point being a partial lead in the CRM before the visitor leaves. E-mail and
  `velk_form_success` still fire on the final step only, with the whole
  merged lead. On that same final step, `step_webhook` and `webhook` both
  fire, and both receive only that step's fields in `$values`, never the
  whole lead — the accumulated lead lives in `$context['data']`, one of four
  keys (`step`, `total_steps`, `completed`, `data`) added to `$context`,
  purely additive.

- **Honeypot and Turnstile run only while the lead's row doesn't exist yet**,
  not on every "step 1" — going back to fix step 1 of an open lead is a
  `merge` and is deliberately not re-filtered, since a false positive there
  (autofill, password managers) would destroy a real lead instead of
  screening a fresh one.

- **The panel marks a lead in progress** and gains a completion filter
  (finished only, by default). It shows for forms declaring steps **and** for
  any form still holding an in-progress row, so a `steps` block that stops
  parsing — which degrades to "no steps" silently — cannot take the already
  collected personal data off the screen, and off the delete button with it.
  The filter is hidden in the spam view, which reads every completion state
  instead. The Forms list's submission count and the sidebar badge both count
  finished leads only, so neither shows a number the list then fails to
  produce.

- **`_event=queue`.** An AJAX form that navigates to a thank-you page used to
  lose its conversion: the event came in the response body, and pushing it
  while the page unloads races the browser. Send `_event=queue` and the engine
  queues the event instead of returning it, so the next page fires it through
  `tracking_head()`. One or the other, never both. Applies to any AJAX form
  and to member registration.

### Fixed

- **Deleting a form submission now always deletes its uploaded files.**
  `cleanup:spam-submissions` did a raw `DELETE` and left the upload orphaned in
  `storage/form-uploads/` — the panel's delete did it right. One shared path
  (`FormSubmission::deleteWithFiles()`) is now used by the panel and both
  cleanup commands.

- **Re-submitting a step no longer strands the file it replaces.** Going back
  to swap the attachment (or double-clicking Next on a bad connection) stored
  the new upload and dropped the old one's metadata, leaving a file in
  `storage/form-uploads/` that no row referenced — so it survived deleting the
  lead in the panel and both cleanup commands. The replaced file is now deleted
  as the merge lands; re-submitting a step without choosing a file again still
  keeps the stored one.

- **A theme written in Portuguese showed Portuguese to an English visitor.**
  `I18n::translate()` had a shortcut that returned the source string without
  consulting the catalog whenever the visitor's language matched a constant
  hardcoded to `en` (`I18n::SOURCE_LANGUAGE`). That constant was right when it
  landed in v3.6.3 — the engine's own strings had just been moved to English —
  and stopped being right one release later: v3.7.1 replaced every
  visitor-facing engine message with a **code**, so the only source strings
  left are the theme's, and their language is whoever wrote the theme's
  choice. On any theme not written in English the shortcut fired for `/en`
  visitors, returned the wrong language, and the translation registered in the
  panel was never consulted — no error, and no way to fix it from the panel.

  The shortcut is gone: the catalog is consulted for every language, and the
  fallback to the source string (which already existed) gets all four
  combinations right. **The site's default language still shows the theme's
  literal** — now by guarantee rather than by luck, since `/admin/languages`
  refuses to register the default as a translatable language, so that catalog
  slot cannot be filled. Write the theme in the site's language and register
  only the languages you translate *into*.

  Behaviour changes for **exactly one language**: a visitor in `en`, who used
  to get the raw source and now gets the registered English translation when
  there is one. Every other language, the default included, is byte-for-byte
  unchanged — it already consulted the catalog. The cost is one memoized
  catalog read per request on a site whose theme *is* in English; every other
  site already paid it. `I18n::SOURCE_LANGUAGE` was removed.
## v3.7.7 - 2026-09-07

One feature and two reviews. How many subscriptions a member can hold, and
whether an item can be bought twice, become `site.json` decisions; a full
engine review found three things to fix, and a review of the subscription
and store flows — done before any site puts them in production — found six.
No migration.

### Added

- **How many subscriptions a member can hold, and whether an item can be
  bought twice, are now `site.json` decisions.** `members.multiple_plans`
  (default `false`) lets a member hold several active subscriptions, one per
  plan. `item_types.<type>.repeat_purchase` (default `true`) set to `false`
  drops a logged-in member's second purchase of the same item from the cart
  (`already_purchased` when nothing is left) and forces quantity 1. New theme
  helpers: `member_subscriptions()`, `member_subscribed_plans()`,
  `member_can_subscribe()`, `item_purchasable()`; `member_on_plan()` and
  `member_require_plan()` now pass when *any* active subscription matches.
  The webhook cannot stop a second subscription or order that slipped through
  (two tabs finishing before the first event arrives), so it logs
  `[Velk] ATENÇÃO:` and the members list shows every active subscription
  with a red `!` when they exceed the rule; nothing is cancelled or refunded
  automatically. `schema:validate` checks both flags. No migration.

### Fixed

- **A term could be opened — and edited — under the wrong taxonomy.**
  `TermController::editOrCreate()` loaded the term by id and never checked
  that it belonged to the taxonomy in the URL. `ItemController` does the
  equivalent check on `type`, and this controller's own `delete()` and
  `bulkDelete()` already checked `taxonomy` — the edit path was the one
  left out. `/admin/taxonomies/tag/42` with a `categoria` term opened it on
  the tag screen: parent validation and slug uniqueness then ran against the
  **wrong** taxonomy, so a `categoria` term could be given a `tag` parent,
  and a slug that collided inside `categoria` passed validation only to hit
  the database's unique key. Now a mismatch is a 404, like items.

- **Uploaded SVGs are served with `Content-Security-Policy: sandbox`.** SVG
  is the one upload the browser will *execute* when opened directly by URL.
  The uploader already sanitises it with a DOM-based pass — no script,
  no `foreignObject`, no `javascript:` — but an SVG sanitiser is a race
  against new vectors, and this header is the net under it: scripts are
  blocked and the document gets an opaque origin, so even a vector that
  slipped through could not reach the site's cookies or DOM. Nothing changes
  for `<img src="...">`, which never executes script anyway.

- **The post-submit redirect only follows a Referer on the same host.**
  The three `backUrl()` helpers (forms, member area and store) returned the
  raw `HTTP_REFERER`. Through a browser that is always the page that hosted
  the form, so it was not an open redirect in practice — but it was the one
  place the engine used a client-controlled header as a redirect target
  unchecked. The policy now lives in `Request::refererSameHost()`: same host
  (ignoring port and case) returns the referer, anything else falls back to
  the site root.

- **A member with an active subscription could start a second one.** Neither
  `subscribe()` nor the service checked for an existing subscription, so a
  double click, an old tab or a refresh created a second Stripe subscription
  and the member was charged twice. `subscribe` now answers
  `already_subscribed` (see `docs/messages.md`) and never reaches Stripe when
  a subscription with status `active`, `trialing` or `past_due` exists.
  Changing plan is done in the Customer Portal.

- **A half-configured site would take money it could not fulfil.** Both
  features turned on with `STRIPE_SECRET_KEY` alone. Without the matching
  webhook secret, checkout worked and Stripe charged — but every webhook was
  rejected with 400, so the member paid and never got access, or the order
  sat in `pending` forever. Subscriptions now also require
  `STRIPE_WEBHOOK_SECRET`, the store `STRIPE_STORE_WEBHOOK_SECRET`; missing
  either, the feature is *off* (checkout 404s) instead of broken.

- **`schema:validate` now checks the payment configuration** — `members.plans`
  (`name`, `intervals` limited to `month`/`year`, `features` as a list of
  strings), `item_types.*.sellable` as a boolean, `store.currency` as a
  3-letter ISO code (it used to fall back to BRL silently) — and reports,
  by endpoint name, a secret key set without the webhook secret the
  declared feature needs. It is the only part of the engine where a wrong
  `site.json` used to surface only with a client's money on the other side.

- **The Customer Portal could fail with "Invalid URL".** Its `return_url`
  was the raw back-URL, which falls back to a *relative* path when there is
  no Referer — and Stripe requires an absolute one. Now always absolute.

- **Concurrent webhooks for the same object could apply out of order.** The
  status update in both `Order::markStatusFromWebhook()` and
  `MemberSubscription::upsertFromStripe()` read the stored event timestamp,
  compared, then wrote — not atomically. The `UPDATE` now carries the
  comparison in its `WHERE` (compare-and-swap); a write that lost the race
  is treated as obsolete. Millisecond window, cheap fix.

- **Stripe create calls carry an `Idempotency-Key`.** A timeout after Stripe
  had already created the object, followed by a retry, produced duplicate
  customers, products or prices. Keys are deterministic per intent
  (member + e-mail for customers, plan for products, product + interval +
  amount for prices), so the same intent gets the same object back.

### Docs

- The Stripe setup chapter that did not exist: both endpoints, both secrets,
  which events to subscribe, where prices live, one-subscription-per-member,
  the success-page race, `past_due`, and what happens if webhooks stop.

## v3.7.6 - 2026-09-02

### Added

- **A discreet "É spam" link next to Delete, on forms that keep spam.** Until
  now the only way to get a junk submission out of the inbox was to delete it
  — which destroys the lead, with no way back if the call was wrong. Marking
  preserves it: the row moves to the spam list with
  `blocked_by = 'manual'`, a third origin alongside `honeypot` and
  `turnstile`, and the existing "Não é spam" button returns it in one click.

  The engine deliberately had no such path before, on the reasoning that
  Delete already removed a submission from the list and a second way to hide
  one would create two places to look. Two things that reasoning did not
  weigh: deleting is destructive where marking is not, and this is a
  **category**, not a second way to hide — with `keep_spam` on, the spam list
  becomes the record of what the automatic filters let through, which Delete
  throws away.

  Because it is reversible in one click, it asks for no confirmation, unlike
  Delete. It renders as text, not a button.

  The link requires `keep_spam` to actually be **on**. It does not appear
  merely because old spam exists — that case only keeps the "Spam" link
  visible so already-collected personal data stays deletable from the panel.
  And an already-blocked row is never overwritten: a `honeypot` catch stays
  `honeypot`, because the origin is what the spam list exists to record.

  No migration — the `blocked_by` column has been there since v3.7.2.

### Fixed

- **`.form-spam-link` and `.form-spam-tag` had no CSS at all.** Both classes
  have been in the form screen since v3.7.3 with nothing defining them: the
  "Spam" link inherited `a { color: inherit }` and read as ordinary text, and
  the origin tag was a bare `<span>`. Both are styled now — which is also what
  lets the same class serve the new `<button>` without the browser drawing
  button chrome around it.

- **Every e-mail the site sent carried a black square with the letter "E".**
  Not derived from anything — the initial was hardcoded in the template,
  sitting next to the site's real name. On a site called "Padaria do João"
  the header read **E · Padaria do João**, which is a stranger's initial on
  someone else's mail.

  The whole brand row is gone from both templates that had it (the form
  submission and the password reset). The site name stays in the footer,
  where it already was and where provenance is enough.

- **On mobile, the close button sat on top of the site name.** The menu
  toggle is one button that swaps a hamburger for an X, fixed at the
  top-left. That corner is empty while the sidebar is off-canvas — and stops
  being empty the moment the drawer slides in underneath it, landing the X
  squarely over the site's mark and name.

  With the menu open it now moves to the drawer's right edge. Still one
  button, not two: it carries `aria-expanded`, `aria-controls` and an
  `aria-label` that alternates, and a second element would put two keyboard
  controls in the page speaking for the same panel.

## v3.7.5 - 2026-09-01

> **Runs two database migrations** (`items.sort_order` and `terms.sort_order`,
> each with a supporting index). Both are additive and idempotent — an
> existing site is unaffected until a type or taxonomy actually turns the
> flag on.

### Added

- **`manual_order` — a curated order you drag, for content where publish
  date is the wrong sort.** Every item type comes out newest-first and every
  taxonomy alphabetically. That's right for a blog and wrong for almost
  everything else — a product grid, a team page, a FAQ, testimonials. Until
  now the only escape was a theme forcing `['order' => 'title ASC']`, which
  just swaps one automatic order for another; hand-curating a sequence
  wasn't possible at all.

  Set it per item type or per taxonomy in `site.json`:

  ```json
  "item_types": { "produto":   { "manual_order": true } },
  "taxonomies": { "categoria": { "manual_order": true } }
  ```

  and a drag handle appears on that type's or taxonomy's admin list. The
  theme needs no changes — `items()` and `terms()` start returning the
  dragged order on their own, through the same calls a template already
  makes. A template that already passes its own `order` keeps getting
  exactly that, flag or no flag; the manual order only becomes the
  *default*, and an explicit request has always won over the default.

  Turning the flag on does **not** reorder the site: the first time the
  admin list opens, whatever order is already on screen gets written into
  the new `sort_order` column, and dragging starts from there.

  Three limitations, accepted deliberately rather than solved: the list is
  still paginated at 50, so dragging reorders a row within its page, not
  across pages; reordering with a search or status filter active only
  permutes the positions the visible rows already held, so a row hidden by
  the filter, sitting between two that were moved, stays where it was; and
  `the_terms()` — the per-item helper that lists the terms attached to one
  item, not `terms()` — keeps hardcoding `ORDER BY t.name ASC` and never
  checks the flag, so an item page's category badges still print
  alphabetically even with `manual_order` on for that taxonomy.

  A type or taxonomy that never sets `manual_order` keeps today's order unchanged.
  The query count is conditional: the extra `SELECT taxonomy FROM terms` lookup is
  skipped only when no item type in `site.json` has `manual_order` set.

### Fixed

- **The honeypot was blocking real people.** Two genuine leads in five days
  were filed as spam with `blocked_by = honeypot`. Forensics on one of them:
  Chrome 151 on desktop, residential IP, internal referer, a contextual
  message written in Portuguese — human in every respect except that `_hp`
  arrived filled.

  The cause is autofill, and it is not something the site can opt out of.
  Chrome ignores `autocomplete="off"` for profile filling, and because `_hp`
  carries no meaning it classifies the field by its **position** in the form —
  a honeypot rendered before name/e-mail/phone gets filled as if it were the
  first real field. Password managers and extensions write straight into the
  DOM, where `autocomplete` is never consulted at all. Marking the field
  `readonly` does not help: false positives happened both with and without it.

  Over the same period the honeypot caught exactly one real bot (an IE11
  user-agent) and let three modern ones through. The scoreboard was inverted.

  `form_honeypot()` now emits, alongside the field, a minimal script that
  **empties `_hp` in the browser**. A visitor running a real browser can no
  longer fall into it, whatever autofill does; a bot posting raw HTTP that
  filled the field still does — and that was the only case the honeypot was
  really catching, since a bot driving a real browser already passed before.

  The script is dependency-free, carries no styling, and is emitted once per
  request no matter how many forms the page has. Themes need no change: one
  that only calls `form_honeypot()` keeps working exactly as before, and with
  JavaScript disabled the behaviour is identical to the previous version.

  Two details worth knowing if you maintain a theme:

  - The submit listener is registered in the **capture** phase on purpose. A
    theme that submits over `fetch` builds its `FormData` inside its own
    handler on the form; capture runs first, so the snapshot is already clean.
  - The field is also cleared on `input`/`change`, which is what covers a
    theme calling `form.submit()` programmatically — that path fires no submit
    event at all.

  Independently of this fix, a theme should render the honeypot **outside the
  natural run of the real fields** rather than as the form's first input.
  Chrome's positional heuristic is the aggravating factor here, and keeping
  the field away from the top of the form removes it.


## v3.7.4 - 2026-08-31

### Fixed

- **Saving an item with translations enabled did nothing, silently.** On a
  multi-language site, any field marked `required` also rendered as required
  inside the hidden translation panels. The browser refused to submit and then
  could not focus the offending field to explain why — so the Save button
  simply did nothing, with the reason visible only in the browser console:

  ```
  An invalid form control with name='fields_translations[en][cliente]'
  is not focusable.
  ```

  `required` is now dropped in every translation panel, at any nesting depth
  (fields inside `group` and `tabs` containers included).

  This was a contradiction inside the feature: the server already treats
  translations as optional — `fieldIn()` falls back to the default language,
  and the controller only stores what the translator actually filled in. Only
  the HTML claimed otherwise.

  Same trap `show_if` already handled by clearing `required` on hidden fields;
  the translation panels were missing it.

## v3.7.3 - 2026-08-26

### Added

- **"Not spam" — the way back from a false positive.** A submission the
  anti-spam blocked gets a button in its detail panel that clears the flag and
  returns it to the normal submissions list.

  v3.7.2 shipped the retention without the return path, which was half the
  feature: the recovered lead stayed trapped in a list nobody treats as an
  inbox. Keeping the evidence only matters if you can act on it.

  It returns to the submissions list rather than a third "recovered" list —
  once you say it is not spam, it is a lead like any other.

  Recovering fires **no email and no webhook**. They would run weeks after the
  submission, and a lead reaching the CRM dated today with three-week-old
  content confuses more than it helps. Whoever recovers it is looking at it on
  screen; forwarding is their call.

  There is no reverse action (mark as spam), deliberately: delete already
  removes a submission from the list, and a second path that merely hides it
  would create two places to look for the same thing.

### Fixed

- **The submissions filter button now matches the height of the recipients
  "Add" button.** It was the only `button--small` sitting in a row of
  full-height inputs, which read as misaligned. Both are 31px and share a
  baseline with the field beside them.

- **Bulk delete is an icon now, and it moved off the page header.** On item
  and term lists it was a full-width text button competing with "Translations"
  and "New X" — three buttons of similar weight, only one of which you press
  often. It is now a trash icon sitting to the left of the status filter, where
  the other list controls live.

  It matches the filter pill's height by stretching rather than by a hardcoded
  value, so it stays aligned if that pill's padding ever changes. Active state
  is on the button itself (`aria-pressed` included) because it sits outside the
  container that carries `is-bulk-active` — without it, turning selection mode
  on left no visible way to turn it off once the bar scrolled away.

## v3.7.2 - 2026-08-22

> **Runs a database migration** (`form_submissions.blocked_by` + index). It is
> idempotent and additive — nothing is dropped — but the upgrade is not a pure
> file swap.

### Added

- **`keep_spam` — the form keeps what the anti-spam rejected.** Set it on a
  form in `site.json` and a submission blocked by the honeypot or by Turnstile
  is stored instead of discarded, flagged with `blocked_by`. A **Spam** link
  appears on that form's screen in the admin, next to the submission count.

  Both checks reject real people sometimes: a password manager or browser
  autofill fills the hidden field, and Turnstile fails on a blocked-JS browser
  or a bad connection. The lead used to vanish with nobody knowing there had
  been a person on the other side.

  Absent the flag nothing is stored and no link appears — the behaviour from
  before this feature. Off by default, per form, because volume and
  sensitivity vary: a one-field newsletter gets hammered while a long quote
  form barely does, and what each collects changes the weight of retaining it.

  A rejected submission gets **no email, no conversion event and no webhook**.
  That is structural, not a check: all three live after
  `FormSubmission::create()` in the normal flow, and both anti-spam branches
  return before reaching it. The honeypot's response is unchanged, so a bot
  still cannot tell it was caught.

  Uploaded files are never stored for a rejected submission, only the declared
  filename — keeping a file from someone the anti-spam rejected means letting
  a bot write to the server's disk.

- **`php bin/velk cleanup:spam-submissions --days=30`** deletes blocked
  submissions past the window. Only touches `blocked_by IS NOT NULL`; approved
  leads are never deleted by age. Worth scheduling if you enable `keep_spam`:
  a form under attack writes thousands of rows a day, and the retained data is
  personal data belonging to people the system classified as bots, which makes
  the retention window an LGPD/GDPR matter rather than a disk-space one.

### Changed

- **`FormSubmission::filterWhere()` now also governs the spam/approved split**,
  and therefore never returns an empty clause: no filter means "the normal
  list", which is itself a condition. The default lives there so that any
  query that does not ask for spam gets approved rows only — leaving it to
  each call site would mean forgetting one fills the client's lead list with
  bots, silently.

## v3.7.1 - 2026-08-20

> **A patch number, but read the upgrade notes.** Nothing here changes the
> database or the schema, which is why it is not a minor — but themes DO need
> a change, and skipping it leaves blank boxes where messages used to be. Not
> a version to apply blind.

### Changed — BREAKING (every theme that renders a form, login or cart message)

- **The engine returns message codes, not sentences.** `form_status()`,
  `member_status()`, `store_status()` and the JSON bodies now carry
  `['status' => ..., 'code' => ..., 'params' => [...]]` instead of a
  ready-made `message`. Field errors changed the same way: `$r['errors']['email']`
  is now `['code' => 'invalid_email']`, not a string.

  Every visitor-facing sentence is gone from the core — 59 of them, across
  forms, members, the store and file uploads. The reason is that they could not
  be fixed from any site: engine source strings are English, and the only way
  to change them was the translation screen, which `ThemeStringController`
  hides on single-language sites. A Portuguese site displayed English with no
  way out. Field errors and upload failures were worse — hardcoded Portuguese
  literals that never went through `t()`, so `i18n:scan` never saw them and the
  panel could not reach them either. They were also the most-read messages in
  the system.

  A form's `success_message` from `site.json` still arrives as `message`. That
  is theme copy, not engine copy.

  **Themes must map codes to text.** Put the map in `theme/functions.php`; the
  demo theme ships a complete one. The full vocabulary — every code, its
  params, when it fires — is in `docs/messages.md`.

- **`FormUploadService` throws `FormUploadException` instead of
  `RuntimeException`.** It carries `errorCode` and `params` (the accepted
  extension list, the limit in MB) rather than a Portuguese sentence. Themes
  that caught `RuntimeException` around uploads still work — the new class
  extends it. The property is `errorCode` and not `code` because
  `Exception::$code` already exists as an int.

- **`MemberController::respond()` gained a `$params` argument** before
  `$event`. Only relevant to code calling it directly, which nothing outside
  the core does.

### Fixed

- **The honeypot's fake success no longer differs from a real one by its
  `message`.** Both now go through the same `successPayload()`. Comparing the
  two responses used to reveal the trap, which is exactly what a honeypot
  exists to avoid. The absence of `event` still distinguishes them, and that is
  deliberate: matching it would mean firing a fake conversion into the client's
  analytics.

- **The server no longer leaks infrastructure detail on upload failure.**
  Missing temp directory, blocked PHP extension and unwritable disk used to
  each get their own message; they now collapse into `store_failed`, with the
  detail kept in the log where it belongs.

## v3.7.0 - 2026-08-19

### Changed — BREAKING (themes that declared consent categories)

- **`consent.enabled` — the theme declares whether the site *has* a consent
  mechanism.** Until now any site offered "requires consent" in the admin,
  including a theme with no banner at all. Marking a script opt_in there left it
  inert **forever**: nothing could accept it, and nothing on screen explained
  the silence. A script that never loads and never errors is the worst state
  available.

  The flag is a statement about the *theme*, not the engine — only whoever
  builds the site knows whether a visitor has any way to accept or refuse.
  Switched off, the consent area disappears entirely (no column in the list, no
  checkbox in the form) and every script is treated as necessary.

  `categories` stays optional: `enabled` alone gives an all-or-nothing banner,
  and declaring categories is what makes it legible to a visitor ("Marketing"
  instead of "Optional").

  One case needed care. A script can carry a category from when the theme did
  have a banner; with the mechanism off, the gate would treat that category as
  **orphaned** and the script would vanish from the page. The emission clears
  the category in that case, so it loads — which is what "no consent" means.
  Clearing it in the helper rather than inside the gate keeps `Consent` pure,
  answering only "is this category granted?". Both halves are tested: the
  behaviour, and the proof that without that step the script would disappear.

  `schema:validate` now rejects two combinations that used to configure nothing
  in silence: categories declared without `enabled`, and an explicit
  `enabled: false` (a block whose only purpose is to say "no" is noise — remove
  the block).

  **A theme that declared `consent.categories` without `enabled` loses consent
  until the key is added**, and validation says so. The feature shipped in
  v3.6.0 and no production site adopted it, so the real reach is zero.

## v3.6.5 - 2026-08-19

### Changed

- **The consent column only says something when there is something to say.** A
  script with no consent now shows an empty cell instead of "Always loads
  (necessary)" — that is the default for every script, and repeating it down
  half the table spent attention without informing. The column now has content
  only where a consent decision exists, which is where the eye should stop.

  The implicit categories show just the default state — "Active by default" /
  "Inactive by default" — dropping the shared "Opcionais" label, which named
  nothing the operator needed. A declared category shows its own name plus that
  state ("Marketing — Inactive by default"), and a `required` one shows just the
  name, since "required" and "always loads" are the same statement twice.

## v3.6.4 - 2026-08-19

### Fixed

- **The scripts table claimed a script wasn't loading when it was.** The screen
  received only the categories declared in `site.json`, while the emission uses
  the *effective* set — which includes the two implicit ones. A script set to
  "requires consent, active by default" showed a red *"category not declared,
  script does not load"* while loading perfectly well.

  Worse than a useless warning: that badge is the only signal the screen gives
  about a script not being emitted, so it pointed at a setting that was already
  correct.

  The consent column now also shows the mode — "Opcionais — loads until
  refused" — because the two implicit categories share a label and nothing else
  distinguished the one that loads by default from the one that doesn't. The
  red badge stays, for categories that really are missing from `site.json`.

- **Added an explicit Edit button** to each row. The only way in was the name
  as a link, which doesn't read as clickable next to a red Remove button.

- Removed the "no consent categories declared" notice: it stated a fact the
  consent column already makes obvious, on every visit, forever.

## v3.6.3 - 2026-08-18

### Changed — BREAKING (Portuguese sites)

- **The engine's visitor-facing strings are now written in English**, and the
  translation shortcut asks the right question.

  41 source strings in `MemberController`, `StoreController` and
  `PublicFormController` — login, 2FA, registration, password reset, checkout,
  form submission — were literal Portuguese. On an English site they came out
  in Portuguese, while the button labels next to them were already English
  (converted in v3.2.0, which stopped at the helpers). The engine spoke two
  languages at once.

  They are English now, and six of them collapsed into three: two pairs said
  the same thing two ways, and translating both to the same text merges them
  in the catalog. 41 unique strings became 35.

  **The shortcut in `I18n::translate()` had to change with them.** It returned
  the source text whenever the current language equalled the *site default* —
  which was fine while the sources were Portuguese and every site defaulted to
  `pt-BR`. With English sources that premise breaks: a `pt-BR` site would get
  raw English and the translation registered in the panel would never be
  consulted, with no error and no way to fix it from the admin. It now asks
  whether the current language is the **source** language (`en`), so an
  English site still does no lookup and every other language reads the
  catalog.

  One consequence worth naming: a non-English site now loads the string
  catalog once per request, where the shortcut used to skip it. It is memoized
  for the rest of the request, and `loadCatalog()` degrades to an empty
  catalog if the read fails — a label in English beats a fatal error on a page
  that used to never touch this path.

  **Portuguese sites will show English** in forms, member area and checkout
  until the strings are translated. The fix is the normal Velk flow and takes
  a few minutes: `php bin/velk i18n:scan`, then fill the Portuguese column in
  the theme-strings screen. There is no automatic migration, by choice — see
  the skill's `upgrade-notes.md`.

## v3.6.2 - 2026-08-18

### Changed

- **Sessions start on demand, and responses declare their own cacheability.**
  The session used to open on every request, so *every* anonymous response
  carried a `Set-Cookie` — and no edge cache stores a response that sets a
  cookie, with good reason: the cached copy would hand one visitor's session id
  to everyone after it. The practical effect was that no page of a Velk site
  was cacheable, not even a home page with no form.

  It now opens when the request already carries the session cookie (there is
  something to read) or on the first write. `Response::send()` then sets
  `Cache-Control` from whether a session was actually opened — `public,
  max-age=0, s-maxage=CACHE_TTL` when it wasn't, `private, no-store` when it
  was — for GET/HEAD 200s that didn't set the header themselves.

  **`CACHE_TTL=0` is the default and disables all of it**: no header, no change
  in behaviour. `s-maxage` rather than `max-age` on purpose — the edge caches,
  the browser revalidates, so an urgent content fix isn't stuck behind
  visitors' own caches.

  A page with a form is still never cached: `csrf_field()` writes the token,
  and a per-visitor secret can't live in a page that is identical for everyone.
  That's the right trade — the pages that take traffic spikes are the home and
  content pages, not the contact form.

  With `APP_DEBUG=true` an `X-Velk-Cache` header says whether the response is
  cacheable and why not. That matters more than it sounds: a `csrf_field()` in
  a global partial (a newsletter form in the footer) makes every page write a
  session and nothing cache, with no error anywhere to point at it.

  Verified against a running site, not just unit tests: admin login persisting
  across requests, a public form submission whose flash survives the redirect
  and is consumed once, and the cache header in each case.

## v3.6.1 - 2026-08-15

### Added

- **The object form of reading a record is now complete.** `Item`, `Page` and
  `Term` expose `field()`, `url()` and a title accessor (`title()`; `name()` on
  `Term`), so a theme can be written without `with_context()` at all:

  ```php
  <?php foreach (items('post') as $post): ?>
      <a href="<?= e($post->url()) ?>"><?= e($post->title()) ?></a>
  <?php endforeach; ?>
  ```

  Two things were missing before. `Page` had no `url()` — `the_url()` handled
  `Item` and `Term` and returned `'/'` for pages — and `field()` always read the
  **default** language, so a multi-language theme built this way showed the
  wrong text with nothing to indicate it.

  `field()` now resolves the active request language by delegating to
  `fieldIn()`. Nothing breaks, thanks to a property that was already there:
  `fieldIn()` falls back to the default-language value when no translation
  exists. A field nobody translates — a price, which `OrderService` reads —
  returns exactly what it did before, and a test pins that down, because losing
  it would break checkout silently.

  `with_context()` remains the default for templates: terser, and a `partial()`
  reads the record without it being threaded in. What changes is having a
  complete alternative where ambient context is the wrong tool — `functions.php`,
  webhook handlers, anywhere far from a template, where `field()` returns its
  default *silently* for lack of context.

## v3.6.0 - 2026-08-15

### Changed — BREAKING (every site) — tracking scripts moved to their own screen

- **`/admin/scripts` replaces the "Scripts & rastreamento" options page.** The
  three textareas (`head`, `body_start`, `body_end`) are gone; scripts are now
  rows in a `tracking_scripts` table, each with a name, position, on/off
  toggle, drag-to-reorder order and an optional consent category.

  Three textareas worked while a script was "a block of HTML per position".
  Consent makes each script carry its own decision, and three fields can carry
  one decision each — the case that breaks it is mundane: GTM and an error
  monitor, both in `<head>`, with different consent.

  **The existing values are NOT migrated.** A pasted block can hold three
  snippets from different services, and only whoever runs the site knows which
  category each belongs to; a backfill would dump them into one row marked
  necessary, which looks like it migrated and leaves the site tracking without
  consent. The procedure is in the skill's `upgrade-notes.md` — **copy the
  three fields out before saving anything**, because `Option::setGroup()`
  stores the group as one blob and the first save drops whatever left the
  schema.

- **Consent gating, off by default and per script.** A new script has no
  category and always loads — GTM, a verification tag, an error monitor need no
  decision. Ticking "this script requires consent" reveals a category select:
  the theme's `consent.categories` when declared, or two implicit options
  (*optional on/off by default*) when not, in which case the banner approves or
  refuses everything at once.

  Granted rows are emitted raw at their position — a returning visitor touches
  no JavaScript. Non-granted ones go out inert
  (`<script type="text/plain" data-velk-consent="…">`, base64 payload because
  pasted snippets routinely contain `</script>`), and `window.velkConsent`
  activates them on acceptance without a reload. A row pointing at a category
  the theme no longer declares emits *nothing*; the table flags it in red.

  Velk ships the mechanism, not a banner: `velkConsent.categories()`,
  `.state()`, `.answered()`, `.set()`, `.acceptAll()`, `.rejectAll()`, plus a
  `velkconsent` event. The wording, the UI and the legal adequacy stay with the
  site owner. The runtime is only injected when at least one enabled script
  actually has a category.

  Two properties that are easier to know than to debug: a gated `<noscript>`
  never renders (with JS on the browser doesn't parse it; with JS off the
  runtime never runs — and a visitor who can't see a banner shouldn't get
  non-essential tags), and snippets of one service must share a category or you
  install half a GTM. Conversion events are deliberately not gated.

- **Member signup conversion settings moved** to the bottom of `/admin/scripts`.
  Same storage (`option('tracking.signup_*')`), same reader in
  `MemberController` — only the screen that edits them changed.

- **Sortable tables**, reusable. `data-sortable-table` + `data-sortable-endpoint`
  on a `<tbody>` makes its rows drag-reorderable and persists the new order over
  fetch. Built for the scripts screen, meant for the item tables next.

  Not in this release: consent logging, per-script consent granularity,
  geolocation, cookie scanning.

### Changed — BREAKING (multi-language sites only)

- **`i18n.default_label` names the default language, replacing a hardcoded
  map.** `I18n::labelFor()` carried a fixed list of thirteen codes
  (`pt-BR` → `Português (Brasil)`, …) used only for the default language —
  the one language nobody could rename. Additional languages are rows in
  `languages` with a required "Nome" field, and the panel refuses a code
  equal to the default, so the default had no owner for its name and no way
  to become `Brasil` or `PT`.

  Now every language has exactly one owner: the default's name comes from
  the theme, the rest from the panel. The map is gone, and a theme that
  omits the key falls through to the raw code.

  **Themes with `i18n.enabled: true` must add it** — `schema:validate`
  rejects its absence rather than letting `pt-BR` show up in the live
  switcher and on the admin's language tabs:

  ```json
  "i18n": { "enabled": true, "default": "pt-BR", "default_label": "Português" }
  ```

  Single-language sites are unaffected: with i18n off, no screen renders a
  language label, and the key is neither read nor required.

### Added

- **`show_if` — conditional fields.** Any field can declare a condition against
  a sibling: `{"show_if": {"field": "tem_cta", "equals": true}}`, or `"in"` for
  a list of accepted values. Two operators only — enough for a checkbox gating
  a block or a select choosing a branch, without site.json becoming an
  expression language.

  Comparison is deliberately loose: a `boolean` arrives as a real bool from
  JSON and as `"1"`/`""` from a raw POST, so strict comparison would break
  `"equals": true` in the most common case of all. A hidden field also **drops
  its `required` attribute** — a required field nobody can see blocks submit
  with nothing on screen explaining why — and gets it back when it reappears.

  Scope is the flat values array: siblings plus anything inside `group`/`tabs`.
  A repeater row is its own scope, so each row decides independently and a row
  can't depend on a field outside it; `schema:validate` rejects that, along
  with unknown targets, self-reference, and missing or duplicated operators.
  A malformed condition leaves the field visible — vanishing from a config typo
  is worse than one extra field.

  Rendered server-side, kept in sync by `admin.js`: no flash, and a JS error
  elsewhere doesn't leave the form showing everything.


- **`repeater.title_field`** — names a subfield to label each collapsed row,
  replacing `ITEM 01` / `ITEM 02`. Ten rows that all read "ITEM NN" are an
  index of nothing; the editor has to open each one to find the one they
  meant. Allowed subfield types are the ones whose value already *is* the
  text a human reads — `text`, `email`, `url`, `tel`, `number`, `textarea`,
  `select`, `link`. `select` shows the option's **label** (`{"br": "Brasil"}`
  renders `Brasil`, not `br`); `link` shows its title and falls back to the
  URL, since a bare URL still identifies a row better than a number.

  An empty value falls back to the number, and deliberately by leaving the
  span **empty**: the number is a CSS counter
  (`.repeater__row-title:empty::before`), so it keeps renumbering itself on
  drag-reorder and delete without any JS. Writing "Item 3" into the span
  would freeze that number and it would start lying after the first reorder.

  The label is rendered server-side and kept in sync by `admin.js` while the
  editor types, so it doesn't flash and survives a JS error elsewhere on the
  page. Lookup descends into `group`/`tabs` (transparent to storage). A name
  that isn't a subfield, or one of an unusable type, is rejected by
  `schema:validate` with the valid types listed. `flexible` is unaffected —
  its rows are already labelled by layout name.

- **`forms.<id>.preview_field`** — names which field labels a submission's
  collapsed card in `/admin/forms/{id}`. Omitted, the label keeps falling back
  to the first non-empty text value, which is what it always did; the point of
  the key is that the old rule worked only by accident of ordering. Data is
  stored in field declaration order, so "the first filled text field" happened
  to be the name — nobody building a form could guess that, and reordering
  fields for layout silently changed the list. The named field still falls back
  when it holds nothing usable (an optional field left blank, or a `file`,
  which stores a metadata array rather than a string): a worse label beats an
  empty card. A name that isn't a field of that form is a config error, caught
  by `schema:validate` with the valid names listed — at runtime it would just
  fall back and look like the setting was ignored.

### Changed

- **The `link` field reads as one field again.** URL, title and "abrir em nova
  aba" were three stacked controls with nothing tying them together, so they
  scanned as three independent fields and the field's own label ("Botão de
  CTA") ended up orphaned from what it described. They now sit in a single
  bordered box — the same recipe `.field-group` and `.repeater__row` already
  use — with the new-tab checkbox sharing the URL's line, since it is an
  attribute *of* that URL. The title keeps its own full-width line because
  it's free text. Below roughly 410px the checkbox drops to its own line
  instead of squeezing the URL input, which is what happens inside a repeater
  row or a two-column `form-row`. Markup only: the three inputs keep the same
  composite `name[...]` contract, so nothing changes about what gets posted
  or stored.

### Fixed

- **The admin sidebar showed the user being edited, not the logged-in one.**
  On `/admin/users/{id}` the footer card took the edited person's name; on
  `/admin/users/new` it disappeared entirely, taking the "Usuários" nav link
  with it. `Controller` shared the authenticated user as `user`, and the users
  screen passes `user` as the record it edits — page data wins the
  `array_merge` in `View::renderFile()`. The shared value is now `authUser`, a
  name no page picks by accident.

- **The submissions list drew its own disclosure arrow.** A hand-typed `▾` that
  didn't rotate on open and changed shape with the system font, so the same
  control had two looks in one panel. The chevron now comes from
  `FieldRenderer::chevron()`, and its rotation is keyed on `details[open] >
  summary` instead of being scoped to the field accordion.

- **The signup-conversion accordion showed on sites without members.** Gated on
  `members.enabled`, the same test that governs the sidebar's "Membros" — with
  members off there is no registration to convert.

## v3.5.2 - 2026-08-14

### Changed

- **Migration files are removed once applied.** `migrations/` joins
  `post_install_cleanup`, so an installed site no longer carries them. They
  exist to move a database forward; once run on an install they have no
  further job there, and a site moved between servers takes its database with
  it rather than replaying migrations. Every upgrade fetches a fresh tarball
  that brings the files back, applies them, and drops them again — scaffolding
  rather than state.

  This changes `update-cms` from pushing the **local working copy** to pushing
  a **published release**: it resolves the version from `--version` or the
  local `.velk-version`, downloads that tarball, verifies its sha256 before
  extracting, and rsyncs the `engine_paths` declared in *that* manifest. Three
  things follow. A client's server now receives exactly a version that exists
  in `versions.json`, so an uncommitted local edit can't reach it by accident.
  The hand-maintained fallback path list the script needed is gone, because
  the tarball always carries a manifest — that list had silently stopped
  shipping `config/` for two releases. And the deploy no longer depends on the
  local `migrations/` folder existing, which is what made removing it possible
  at all.

  Server-side cleanup runs **only after `migrate` reports success**. Deleting
  the migrations against a half-applied schema would leave a site running new
  code on an old database with no files left to retry from.

  The rollout sequences itself: the cleanup list is read from the tarball, so
  sites stay as they are until they upgrade to a version whose manifest
  carries the new entry.

  **One scenario stops working, deliberately.** `Installer::run()` builds the
  schema from the migration files, and Velk auto-installs on first boot when
  it finds no `migrations` table. So copying an installed site's *files* onto
  an **empty** database no longer self-installs — there is nothing to build
  the schema from. Copying the database along with the files, which is what
  moving a site actually means, is unaffected; so is `init.sh`, which restores
  a dump; so is a fresh `velk install`, which extracts a tarball that still
  carries the files. If you do need to rebuild a schema from scratch, run the
  install flow rather than copying files.

- **`migrate:status` no longer claims "all up to date" when it can't tell.**
  With the files gone, `pending()` returns empty for both "nothing pending"
  and "nothing to compare against" — reporting the first would be a confident
  false negative on the exact question someone SSHes in to ask. It now says
  the files aren't present, prints how many migrations the `migrations` table
  records as applied, and shows the engine version from `.velk-version`.

## v3.5.1 - 2026-08-14

### Added

- **Cloudflare Turnstile on public forms, optional and off by default.**
  A new `form_captcha()` helper renders the widget next to
  `form_honeypot()`, and the whole feature turns on **purely by the presence
  of both keys** in `.env` — the same way the store lights up when
  `STRIPE_SECRET_KEY` exists:

  ```
  TURNSTILE_SITE_KEY="0x4AAA..."
  TURNSTILE_SECRET_KEY="0x4AAA..."
  ```

  With no keys the helper returns an empty string and the submit path never
  calls out, so a theme can print it unconditionally and each site decides.
  Nothing changes for existing sites. No theme JavaScript is required: the
  Cloudflare script renders the widget and injects the token into a hidden
  field itself, so a plain POST — or a `fetch()` carrying `FormData` — takes
  it along. Widget mode (Managed / Non-interactive / Invisible) is chosen in
  the Cloudflare dashboard rather than here, and the domain does **not** need
  to use Cloudflare DNS; it only has to be listed as a hostname on the widget.

  Turnstile and not reCAPTCHA for a reason that isn't preference: reCAPTCHA
  sends data to Google, sets cookies, and Google uses it for its own
  purposes, which turns every form into a third-party disclosure and
  therefore an LGPD consent question — and a captcha that can only run after
  a cookie banner is accepted is a captcha that doesn't protect anything.
  Cloudflare states Turnstile isn't used for advertising and doesn't rely on
  tracking cookies.

  Three behaviours are deliberate and worth knowing before deploying it. The
  check runs **after** the honeypot, which is free and already catches the
  naive bots, so a network round-trip is never spent on traffic a string
  comparison would have stopped. A rejected captcha is an **honest error**,
  unlike the honeypot's intentional lie — real people fail sometimes (blocked
  JS, flaky network), and answering "sent!" to someone who didn't send loses
  the lead with nobody noticing. And verification **fails open**: if
  Cloudflare can't be reached, the submission proceeds and a line goes to the
  error log, because the honeypot is still running and losing real leads
  during someone else's outage costs more than a few minutes of spam. A token
  that is missing, or one the provider actually rejects, is still turned away.

  A rejected submission stores nothing and fires no conversion event — not by
  a check, but because the enqueue lives past `FormSubmission::create()`,
  which those branches never reach.

## v3.5.0 - 2026-08-14

### Changed — BREAKING

> Despite the minor version number, this release requires changes to any
> theme reading `utm_params()` and any webhook handler reading
> `$context['utm']` or `$context['click_ids']`. Read the note below before
> upgrading a live site.

- **UTM and ad click IDs are now one thing: `params`.** `Velk\Utm` and
  `Velk\ClickIds` were the same class with different constants; they're
  replaced by `Velk\Params`, one whitelist, one `params JSON` column on
  `form_submissions`, one key in the webhook `$context` and in the conversion
  event payload. Adding a parameter — a new ad platform, a `utm_id` — is now
  one whitelist entry and nothing else, where a queryable one used to require
  a migration.

  The five UTM columns existed because the admin filtered and grouped by
  them. **That filter is gone**, and it's what unlocks the rest: UTM is an
  input for analytics tools — whoever builds a link with `utm_campaign` reads
  it in GA4 or Ads, not in a list of twenty submissions — so the panel was
  competing badly with tools that do it properly. The parameters are still
  shown on each submission; only the filtering left, and with it the reason
  for the columns.

  **Last-touch now applies per group rather than globally**, which preserves
  the previous behaviour: arriving with `?gclid=x` and no UTM replaces the
  click ID and leaves the UTM intact. A single global last-touch would have
  wiped the campaign and left a lead carrying a click ID with no origin.
  Within a group, replacement is still total and never merges.

  Migrating a theme: `utm_params()` becomes `tracking_params()`, and it now
  returns only the keys actually captured instead of five UTM fields padded
  with `null` — the set is open-ended now, so a fixed shape stopped making
  sense. Webhook handlers read `$context['params']` instead of
  `$context['utm']` and `$context['click_ids']`. GTM containers read
  `params` instead of `utm` and `click_ids`.

- **First non-additive migration in Velk's history.** The migration drops six
  columns **without backfilling** — the existing UTM and click ID data is
  discarded, which was verified site by site beforehand as depending on
  nothing in production. On a database where that history mattered, the right
  shape would have been expand/contract: add `params`, backfill, run a whole
  release writing to both, and only then drop.

  Consequence worth stating plainly: until now `upgrade --version=<older>`
  was safe because the schema only ever ran AHEAD of older code, and an
  unknown column is simply ignored. Downgrading across this release finds
  code looking for columns that no longer exist. **This point is a one-way
  door.**

## v3.4.0 - 2026-08-13

### Added

- **Conversion events for ad platforms, configured in the panel.** Setting up
  a conversion (Google Ads, Meta, GA4) is the traffic manager's job, not the
  programmer's — but until now, firing one on a form submission meant editing
  the theme, so a client switching agencies had to hand over repo access.
  Velk already accepted this premise with `tracking.head`/`body_start`/
  `body_end`; what was missing is the "only when it actually worked" slice.
  Every **recorded** submission now pushes a `velk_form_success` event to
  `window.dataLayer` (and a completed registration pushes
  `velk_signup_success`), carrying the form/member id, the record id, and any
  UTM and ad click IDs captured on that visit.

  This has to live in the core, and the reason isn't obvious: **the honeypot
  lies on purpose.** `PublicFormController::submit()` answers a caught bot with
  `status => success`, byte-identical to a real one, so nothing outside the
  controller can tell them apart — any theme-side implementation counts bots as
  conversions. The enqueue therefore sits immediately after
  `FormSubmission::create()`, which the honeypot branch returns ~50 lines
  before ever reaching: the guarantee is the shape of the code, not a check
  someone could forget. Same for `Member::create()`. It also lands *before*
  email and webhook dispatch, so a bounced notification or a CRM outage can't
  suppress a conversion — a lead is a lead.

  The event name is fixed on purpose. The dataLayer event name and the GA4/Ads
  conversion name are different things: in GTM you fire a `generate_lead` tag
  *from* a `velk_form_success` trigger. A configurable name would invite people
  to type `generate_lead` in the panel and expect that to be enough, and it
  would break the one real payoff — a fixed name is a documentable contract, so
  an agency running many Velk sites can build one GTM container, import it
  everywhere, and only swap the conversion ID.

  Configuration is per form on `/admin/forms/{id}` (mapping + snippet, stored
  in a new `form_settings` table) and, for registration, in a collapsible
  section of the existing **Scripts & rastreamento** page. Two selects ask
  *which field is the email* and *which is the phone* rather than offering a
  checkbox per field: Google's enhanced conversions only consume named keys,
  so a checked free-text `message` field would be ignored by Google and exposed
  for nothing. Values go in plain text because the Google tag normalises and
  SHA-256s them in the browser — hashing server-side against the wrong
  normalisation produces a valid hash that matches nothing, and fails silently.
  Nothing is mapped by default: sending personal data to a named third party is
  the site owner's decision, and an engine upgrade must never change who
  receives the users' data.

  For sites with no tag manager (plenty paste `gtag` directly), each form and
  the registration flow take an optional snippet, since a dataLayer push does
  nothing when nobody is listening. It runs *alongside* the push, never instead
  of it, reads its data from `window.velkFormSuccess` / `window.velkSignupSuccess`
  rather than any template placeholder syntax, and is emitted in a **separate**
  `<script>` block — a syntax error in pasted code makes the browser discard the
  whole block, and `try/catch` can't help because that failure happens at parse
  time, so isolation is the only mitigation.

- **JSON responses for public form submissions and member registration.**
  Sending `Accept: application/json` now gets the outcome as a JSON body
  (`status`, `message`, plus `errors`/`values` on a 422) instead of the
  flash-and-redirect flow. Purely additive: a plain `<form>` POST sends no such
  header, so every existing theme behaves exactly as before.

  This also fixes a silent trap. A theme that reached for `fetch()` got a 302
  that fetch **follows** by default; the fetched page rendered server-side and
  consumed the flash (`form_status()` does get + forget), so the success message
  vanished with no error anywhere — and the conversion event would have
  vanished the same way. Each response now routes its event to exactly one
  drain: into the JSON body for an AJAX caller, or onto the queue for the next
  render otherwise. Both would fire it twice; queue-only would fire it late or
  never. Worth knowing: the AJAX path is also the more private one, since the
  payload never enters the HTML — it lives only in JS memory.

## v3.3.2 - 2026-08-13

### Fixed

- **A public form submission containing invalid UTF-8 returned a 500 and lost
  the lead.** `FormSubmission::create()` encoded the submitted values with
  `json_encode()` and passed the result straight to a JSON column; on malformed
  input `json_encode()` returns `false`, which MySQL rejects outright
  (*"Invalid JSON text: The document is empty"*), so the exception surfaced as
  a server error and the submission was never stored. Reachable by anyone able
  to craft a POST — no authentication, no valid session beyond the CSRF token.
  Both encodes now use `JSON_INVALID_UTF8_SUBSTITUTE`: a bad byte costs one
  character, where before it cost the entire lead. `Member::create()` had the
  same exposure through public registration and got the same treatment.
  Present since each feature shipped.

  Upgrading is worth doing even if you don't want v3.4 yet — this is two
  files and no schema change.

## v3.3.1 - 2026-08-13

### Added

- **Bulk delete for items and taxonomy terms.** A "Bulk delete" button in
  `/admin/items/{type}` and `/admin/taxonomies/{taxonomy}` enters a
  selection mode — no page reload — that reveals a checkbox column (with a
  "select all" in the header), an action bar showing the live count, and
  "Delete selected" / "Cancel". Deletion still requires a confirmation
  dialog, same as the existing single-row delete. Backed by two new routes,
  `POST {prefix}/items/{type}/bulk-delete` and `POST
  {prefix}/taxonomies/{taxonomy}/bulk-delete`, registered ahead of the
  existing `/{id}` routes so `bulk-delete` is never swallowed as an id.
  `ItemController::bulkDelete()` / `TermController::bulkDelete()` mirror the
  single-delete sequence exactly — auth, CSRF, then **per-id scope check**
  (`$item->type === $type` / `$term->taxonomy === $taxonomy`) — deleting one
  row at a time through the model's existing `delete()` instead of a single
  `DELETE ... WHERE id IN (...)`, so a forged id from another type/taxonomy
  is silently skipped exactly like single delete already does, and any
  future cascade added to the model is inherited automatically. The
  incoming id list is normalized by a new pure helper,
  `Controller::parseBulkIds()` (shared base class, same shape as the
  existing `MediaController::parseSelectedIds()`), which discards
  non-numeric/zero/negative values, deduplicates, and caps at 200 ids —
  robustness against a malformed request, not a security boundary (the
  per-id scope check is what makes it safe). The row-level delete `<form>`
  that already lives in each table row is left untouched — the bulk
  checkboxes reference an external `#bulk-delete-form` via the HTML5
  `form="…"` attribute instead of wrapping the table, so no `<form>` ever
  nests inside another. The post-delete redirect preserves the listing's
  current filters/pagination (query string).

### Fixed

- **Image variants now generate for nested fields on save.** Field-declared
  `variants` on `image`/`gallery` fields were only generated when the field
  sat at the TOP level of a content type — an image inside a `repeater`,
  `flexible`, `group` or `tabs` silently got no variants until someone
  remembered to run `bin/velk media:variants`, so `image_url($id, 'card')`
  fell back to the full-size original (never a 404, just a much heavier
  image than the theme asked for). `VariantService::ensureForFields()` now
  walks the whole field tree, descending through exactly the same
  containers `FieldRenderer::collect()` does: `group`/`tabs` share the
  parent's value scope (their subfields are stored flat), while
  `repeater`/`flexible` open a fresh scope per row — and for `flexible` the
  row's `_layout` selects which subfield defs apply, with rows pointing at
  a removed layout skipped, matching `collect()`'s self-heal. Nesting is
  handled at any depth (a repeater inside a repeater works). The save stays
  cheap despite covering more ground: the walk is pure, media ids are
  deduplicated before any work happens (the same image used in 20 repeater
  rows is one job), and media rows are now fetched in a single
  `Media::findMany()` query instead of one `Media::find()` per field —
  fewer queries than before the fix. Generation remains idempotent (an
  existing variant file is never rebuilt) and non-fatal. `bin/velk
  media:variants` is still the tool for backfilling pre-existing uploads,
  changed variant dimensions, translations, and member images.

## v3.3.0 - 2026-08-09

### Added

- **File upload fields in public forms.** `theme/site.json → forms.*.fields[]`
  gains a `file` type (`accept`: extension whitelist subset, `max_size`: MB,
  default 10) for public form submissions — one file per field. Uploaded
  files are stored **separate from the media library** (no `Media` row, never
  listed in `/admin/media`), at `storage/form-uploads/YYYY/MM/<random-token>.<ext>`
  — private by construction (same deny-list as the rest of `storage/`),
  reachable only through a new authenticated admin download route (`GET
  /admin/forms/{id}/submissions/{sid}/file/{field}`). MIME is verified from
  the real file bytes (reusing `MediaService::detectMime`, now public) with
  its own whitelist (PDF, jpg/png/webp/gif, docx/xlsx/pptx, zip — no
  video/audio) narrower than the media library's, since the upload is
  anonymous. `svg` requires explicit opt-in per field (`"accept": ["svg"]`)
  because it is executable XML; when opted in it is sanitized the same way
  the media library sanitizes it. A submission's `data[<field>]` holds a metadata array (`_file`,
  `path`, `name`, `size`, `mime`) instead of a string — the admin submissions
  view and the notification email render it as a name + human-readable size
  + download link (the email never attaches the file or exposes a public
  link); `forms.<id>.webhook` handlers receive the same metadata shape.
  Deleting a submission also deletes its file(s); a submission rejected by
  validation cleans up any file already written for that same request. Theme
  templates with a `file` field need `enctype="multipart/form-data"` on the
  `<form>` — documented prominently in `AGENTS.md`, since the failure mode
  otherwise is silent (the field just arrives empty). Also fixes a latent bug
  where a POST larger than PHP's `post_max_size` — which discards the whole
  body, `_csrf` included — surfaced as a misleading "session expired" error
  instead of a "file too large" one.

- **Ad click ID capture (gclid & cia) for offline conversion import.**
  `Velk\ClickIds` captures `gclid`/`gbraid`/`wbraid` (Google Ads), `fbclid`
  (Meta), `msclkid` (Microsoft Ads), `ttclid` (TikTok), `li_fat_id`
  (LinkedIn), and `twclid` (X) from the query string of any GET request —
  same session-based, last-touch mechanism as UTM capture (`Velk\Utm`), but
  a closed, hardcoded whitelist (never an arbitrary query param — an open
  capture would be an unlimited write vector and a privacy/LGPD risk) and
  its own storage: a single `click_ids JSON NULL` column on
  `form_submissions`, no index — a click ID is unique per click, so grouping
  or filtering by it means nothing, unlike a UTM campaign. Included in the
  `forms.<id>.webhook` `$context` alongside `utm`, and shown in the admin
  submission detail next to the UTM fields when present (no filter, for the
  same reason there's no index). The point: hand the ID as-is to whatever
  CRM/ad-platform integration runs the offline conversion import (Google Ads
  and equivalents), so ad platforms can optimize bidding on real conversions
  instead of just clicks.

## v3.2.2 - 2026-07-26

### Added

- **Image variants**, generated at write time (never lazily at render/URL
  time — the reliability problem that got the old lazy-generation feature
  removed). Every uploaded raster image (jpeg/png/gif/webp) now gets a
  global `thumb` variant at upload — a 400px box, `contain`, downscale-only
  — used by the admin's media previews and callable from themes via
  `image_url($id, 'thumb')`. `image`/`gallery` fields in `site.json` can
  also declare their own `variants` (`name`, `width`/`height`, `fit`:
  `contain`/`cover`/`crop`), generated when content is saved. Resolution
  (`image_url()` / `Media::url()`) is a pure disk check that falls back to
  the original file when a variant is missing — never a 404. New CLI:
  `bin/velk media:variants [--regen]` backfills the thumb + every declared
  variant for every image (including nested repeater/flexible fields);
  `--regen` rebuilds existing files. Deleting a media now also deletes its
  variant files.
- **Payment webhook**: a top-level `site.json → payment_webhook` key (string
  or list of theme function names) that fires a `theme/functions.php`
  handler — `function(string $event, array $data, array $context): void` —
  on confirmed Stripe events: `order.paid`/`order.failed`/`order.expired` on
  a real status transition, and `subscription.<status>` (verbatim Stripe
  status) whenever a sync is applied and the status changed or the event
  timestamp advanced (renewals fire; tie replays don't). Same best-effort
  contract as forms: missing/throwing handlers are logged, never break
  webhook processing; validated by `schema:validate`. The existing forms
  webhook (`forms.<id>.webhook`) is now documented in `AGENTS.md` too — both
  share a new internal `WebhookDispatcher` service.
- **Rich text: code and tables.** The `richtext` field's TipTap editor
  gained toolbar buttons for inline code and code blocks (from StarterKit)
  and simple tables — insert, add/delete row and column, delete table, with
  resizable columns. The vendored `tiptap.js` bundle was regenerated to
  include the Table extensions. Rendering tables on the front is the theme's
  job (`field()` outputs the stored HTML).

### Changed

- **Admin: dark sidebar.** The admin sidebar is now dark (`#10101e`) with
  light-gray text; the nav icons were refreshed — distinct icons for Scripts
  & tracking, Members (no longer identical to Users), and Languages.

## v3.2.1 - 2026-07-23

### Fixed

- Docs: `AGENTS.md` now documents the **built-in SEO/social block**
  (`meta_title`, `meta_description`, `og_image`, auto-attached to every page
  and every `has_page` item type) and no longer uses `seo` as the
  `field_groups` example. This stops AI agents from declaring a duplicate
  custom SEO field group when building a theme. Same fix in the `velk` skill.

## v3.2.0 - 2026-07-23

### Added

- Admin panel i18n: English, Portuguese and Spanish catalogs, resolved
  per-user (each admin can pick their own panel language) with a project-wide
  default via `ADMIN_LOCALE`. The same locale also governs 2FA login-code
  emails and password-reset emails, for both admins and members.
- MIT `LICENSE` (Elleven Digital).

### Changed

- **Default admin locale is now English** (`ADMIN_LOCALE` defaults to `en`).
  This is a breaking default for existing Portuguese installs — set
  `ADMIN_LOCALE=pt` in `.env` to keep the admin panel and member login-code
  emails in Portuguese.
- Finished de-branding: removed remaining traces of prior product names
  (Plooma/Ellev/Nano) from shipped config templates and docs. The product is
  Velk; the company is Elleven Digital.
- **Store/subscription button helpers are now English-first.** The default
  labels of `member_subscribe_button()`, `member_portal_button()` and
  `store_checkout_button()` changed from Portuguese (`Assinar`/`Gerenciar
  assinatura`/`Comprar`) to translatable English (`Subscribe`/`Manage
  subscription`/`Buy`) via `t()`. A theme that relied on the bare defaults on a
  Portuguese site should pass the label explicitly, e.g.
  `store_checkout_button($cart, 'Comprar')`, or add the string to its `t()`
  catalog.

### Fixed

- Pre-launch hardening pass: security and correctness fixes found in review
  ahead of public launch, including a packaging bug where `config/` (and
  therefore `config/env.php`) was missing from the released engine tarball,
  which made every fresh install fatal before the installer could run.

## v3.1.0 - 2026-07-21

### Added

- Template models for items and taxonomies (per-template custom fields with
  picker + round-trip persistence).

### Changed

- Admin UI polish.

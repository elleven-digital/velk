# Velk — Agent Instructions

This file is the contract between Velk and AI coding agents (Claude Code,
GPT engineers, etc). Read it before touching anything in this project.

> **Do NOT confuse with the company:** the product is **Velk**; the
> company/org is **Elleven Digital** (`elleven-digital`, `@elleven.digital`).
> "Elleven" is not the product name — never rename it.

## What Velk is

A minimalist, schema-driven CMS designed for AI-driven workflows:

- **Pure PHP 8.2+**, no Composer required, runs on any cheap shared hosting.
- **MySQL** storage. Custom fields are stored as JSON columns (not the
  `wp_postmeta` pattern).
- **`site.json` is the source of truth** for the site's structure: pages, item
  types, taxonomies, custom fields. The admin panel is generated from it.
- **Templates are pure PHP** with helper functions. No DSL, no build step.
- **No magic from WordPress**: no hooks/filters, no plugins, no core
  content/thumbnail fields. Title, slug and publish date are the only built-ins;
  everything else is a custom field.

## Repo layout

```
core/                      Engine. Do not edit unless extending the platform.
  Bootstrap.php            Boot entry
  App.php                  Service container
  Config.php               site.json + env loader
  Database.php             PDO wrapper
  Router.php               URL routing
  FrontRouter.php          Public site routing
  helpers.php              Global helpers used by templates
  Models/                  Page, Item, Term, Media, User
  FieldRenderer.php        Renders form inputs + collects POST values
  Admin/                   Admin panel (controllers, views, CSS, JS)
  Migrator.php             Migration runner
config/
  env.php                  Reads env vars into the app config array. Engine
                           code — edit `.env`, not this file.
migrations/                Versioned SQL migrations (timestamped files; removed after install)
storage/uploads/           Uploaded media (served at /uploads/...)
storage/cache/             HTML cache (future)
storage/logs/              Logs
theme/                     Project theme — a SEPARATE package, like WordPress.
                           Not part of Velk core (gitignored). Each project
                           drops its own theme here.
  site.json                Schema: pages, item types, taxonomies, fields.
                           Lives WITH the theme because it's part of the theme.
  style.css                Theme stylesheet
  templates/               Page, single, taxonomy templates
  partials/                header.php, footer.php, etc.
    richtext/              Partials of the richtext blocks (see Richtext blocks)
index.php                  Single front controller (project root)
.htaccess                  URL rewriting + blocks for internal dirs
.htaccess.example          Template the Installer copies into .htaccess
.env                       Environment vars (DB creds, APP_URL, ADMIN_LOCALE,
                           etc.) — gitignored. Copy from .env.example and
                           edit THIS file — never config/env.php.
.env.example               Template copied into .env (see Installer/CLI install).
robots.txt                 Crawler instructions (per-project, gitignored)
robots.txt.example         Template the Installer copies into robots.txt
bin/velk                   CLI tool
```

## The flow when building a site

1. Edit `theme/site.json` — define pages, item types, taxonomies, fields.
2. Run `./bin/velk schema:validate` to verify the JSON.
3. Run `./bin/velk page:sync` to seed page records into the DB.
4. Run `./bin/velk migrate` if any new migration files were added.
5. Create or edit templates in `theme/templates/` — these are referenced
   **by file name** from `site.json`. Never associate templates by slug.
6. Use the admin panel to create items and edit page content.

## site.json — the core schema

```json
{
  "site": {
    "name": "...",
    "language": "pt-BR"
  },
  // Note: the canonical site URL is NOT declared here. The theme is
  // project-neutral; deployment URL goes in `.env` as `APP_URL` and is
  // read by Sitemap, absolute_url(), and any other code that needs the
  // canonical origin.

  "field_groups": {
    "contato": {
      "label": "Contato",
      "fields": [
        {"name": "telefone", "type": "text", "label": "Telefone"},
        {"name": "email", "type": "email", "label": "Email"},
        {"name": "endereco", "type": "text", "label": "Endereço"}
      ]
    }
  },

  // `image` / `gallery` fields may declare a `variants` array to get
  // extra sized versions generated automatically (write-time, never
  // lazy) — see "Image variants" in Helpers for the full schema:
  // {"name": "foto", "type": "image", "label": "Foto",
  //  "variants": [{"name": "card", "width": 600, "height": 500, "fit": "crop"}]}

  "pages": {
    "home": {
      "label": "Home",
      "template": "page-home.php",
      "url": "/",                      // optional, defaults to "/" for "home", "/{key}" otherwise
      "fields": [ ... ]
    }
  },

  // Page templates available for admin-created pages (the "+ Criar página"
  // button in /admin/pages). Each entry registers a template the editor
  // can pick when creating an extra page beyond the structural ones
  // above. The page lives in the DB (not in this JSON) and its URL is
  // `/{slug}` where slug is set in the admin.
  "page_templates": {
    "landing": {
      "label": "Landing page",
      "file":  "page-landing.php",     // file under theme/templates/
      "fields": [
        {"name": "headline", "type": "text"},
        {"name": "blocks",   "type": "flexible", "layouts": { /* ... */ }}
      ]
    }
  },

  "item_types": {
    "post": {
      "label": "Posts",
      "label_singular": "Post",
      "slug": "blog",                  // URL prefix (e.g., /blog/{item-slug})
      "taxonomies": ["categoria", "tag"],
      "manual_order": false,           // optional, default false. Curated
                                       // drag order instead of newest-first —
                                       // see "Manual ordering" below.
      // `templates` is a MAP (key => {label, file}), not a list. Declare
      // EITHER `template` (single file, all items) OR `templates` (named
      // choices the editor picks per item) — never both; schema:validate
      // rejects the combination.
      "templates": {
        "destaque": {"label": "Post destacado", "file": "single-post-destaque.php"}
      },
      "fields": [
        {"name": "thumbnail", "type": "image", "label": "Imagem destacada"},
        {"name": "content", "type": "richtext", "label": "Conteúdo"},
        {"group": "contato"}
      ]
    }
  },

  "taxonomies": {
    "categoria": {
      "label": "Categorias",
      "label_singular": "Categoria",
      "slug": "categoria",
      "hierarchical": true,
      "has_page": true,                // optional, default true. When false:
                                       //   - /<slug>/<term> returns 404
                                       //   - terms still exist in admin
                                       //     and can be assigned to items
                                       //   - sitemap skips entirely
                                       //   - Term::url() returns ''
                                       // Mirror of the same flag on item_types.
      "manual_order": false,           // optional, default false. Curated
                                       // drag order instead of alphabetical —
                                       // see "Manual ordering" below.
      "template": "taxonomy-categoria.php"
    }
  }
}
```

### Rules of site.json

- **Templates are linked by file name only**, not by slug. If you change an
  item's slug, the template still works because the binding is in the JSON.
- **Custom item templates** (e.g., a special "destaque" layout for a single
  post) are listed under `item_types.{type}.templates` as a MAP (`key: {label,
  file}`, not a list). The user picks one in the admin panel when
  creating/editing the item.
- **No standard `content` or `thumbnail`.** If you want them, declare them
  as custom fields. This is intentional — every site decides what fields
  matter to it.
- **Field groups** are reusable. Reference them with `{"group": "contato"}` inside
  any `fields` array; they are inlined at render time.
- **`not_found_page`** (top-level key, sibling of `pages`) — the key of a
  `pages` entry to render (with its own template, status 404) as the
  front-end 404 page. Optional; omit for the engine's generic 404.
- **`security.csp`** (top-level `security` key) — the public site's Content
  Security Policy: the mode, and the domains the theme's templates load and
  its forms post to. See
  [Content Security Policy](#content-security-policy-public-site).
- **`richtext_blocks`** (top-level key) — content blocks the theme declares
  for the richtext editor (big numbers, a testimonial, a video), each with the
  file of its partial in `theme/partials/richtext/`. A richtext field lists
  the ones it accepts in its own `blocks`. See
  [Richtext blocks](#richtext-blocks).

### Built-in SEO fields

Velk automatically attaches a built-in SEO/social block — `meta_title`
(text), `meta_description` (textarea), `og_image` (image) — to **every
page** and to **every item type with `has_page: true`** (the default;
see `Config::seoFields()`). The admin renders it as its own "SEO &
compartilhamento" section, separate from whatever fields you declare, so
editors always find it in the same place. Taxonomies do NOT get this
block — `has_page` on a taxonomy only controls whether term archive pages
exist, unrelated to SEO.

**Do NOT declare your own SEO / meta / Open Graph / share-image fields.**
A custom `seo` field group, or fields like `meta_title` / `meta_description`
/ `og_image` / `share_image`, duplicate the built-in block — editors end up
with two "title" or two "image" inputs that do the same thing.

In the theme, read the built-in fields exactly like any other field —
there's no separate SEO-only helper:

```php
<?= e(field('meta_title')) ?>
<?= e(field('meta_description')) ?>
<?php $ogImage = image_url(field('og_image')); ?>
```

(Separately, `the_canonical()` and `the_hreflangs()` — see i18n Phase 2 —
render canonical/alternate `<link>` tags. They're unrelated to this block,
but often sit next to it in `<head>`.)

### Available field types

| Type | Description |
|------|-------------|
| `text` | Single-line text input |
| `textarea` | Multi-line plain text |
| `richtext` | TipTap WYSIWYG (HTML output) — formatting, headings, lists, quote (`<blockquote>`), link, image, **inline code, code block, and simple tables**. Quote and table styling on the front is the theme's job. Optional `blocks`: the theme blocks the author can insert in the text — see [Richtext blocks](#richtext-blocks). |
| `image` | Reference to a media library entry. Stored as **integer ID**, not URL. |
| `gallery` | Ordered list of media references with drag-and-drop reorder. Stored as **array of integer IDs**. |
| `file` | Reference to ANY media library entry (mirrors `image`, without the image-only restriction — video, audio, documents, archives). Stored as **integer media ID**. Optional `accept` (array of category strings: `image`, `video`, `audio`, `document`, `archive`) limits which categories the picker shows. Resolve with `file_url($id)` or `media($id)` for metadata (name, mime, size). |
| `select` | Dropdown — define `options` as `{value: label}` |
| `boolean` | Checkbox (true/false) |
| `number` | Numeric input |
| `email`, `url`, `date` | HTML5 input variants |
| `repeater` | Repeating group of subfields. Define `fields` array on the field. Optional `title_field` names a subfield to label each collapsed row instead of `ITEM 01` — see below. |
| `flexible` | Like a repeater, but each row picks a layout (named group of fields) from a predefined set. Define `layouts: {key: {label, fields}}` on the field. Each saved row carries `_layout` so the theme can switch on it. Modeled after ACF Pro's Flexible Content. |
| `link` | Composite link: optional `title` + `url` + an "abrir em nova aba" checkbox. Stored as `{title, url, new_tab}` (or `null` when the URL is blank). The URL is required *semantically* (blank ⇒ empty link), **not** via HTML `required` — so an empty optional link never blocks a page save. URL is stored as typed (allows `mailto:`, `#anchor`, relative; no scheme whitelist — trusted editor input). Render in the theme with `link_tag(field('cta'))`. |
| `item` | Reference to another item, picked via an inline searchable combobox. Define `item_type` on the field (the target type slug from `item_types`); the menu offers only **published** items of that type. Stored as the target item's **integer id** (or `null` when empty), exactly like `image`. Resolve in the theme with `item(field('produto_destaque'))`, which returns an `Item` (or `null` if missing/deleted). |
| `group` | **Organizational only** — a collapsible box around fields in the admin UI. Define `label` + `fields` array. Starts COLLAPSED by default (same policy as repeater/flexible rows); pass `"open": true` to start expanded. Children are stored FLAT, exactly as if declared outside the group — `field('title')` in the theme works the same whether `title` sits inside a group or not, and removing the group wrapper from site.json later changes nothing about saved data. Works inside repeater/flexible rows too. |
| `tabs` | **Organizational only** — a tab strip over field sets in the admin UI. Define `tabs: [{label, fields}, ...]`. Same flat-storage contract as `group`: all tabs share the surrounding scope (so two tabs can't both declare a field named `title` — `schema:validate` flags it), and the theme reads values by field name as usual. |

Field common options: `name` (required), `label`, `help`, `required`,
`placeholder`. Repeaters: `fields` (array of subfield defs). Galleries
work like `image` — `field('name')` returns an array of media IDs; pass
each through `image_url($id)` in the theme to render. Flexible
content: `field('name')` returns a list of dicts each with `_layout` +
the saved subfield values; iterate and switch on `_layout` to render.

**`show_if` — conditional fields.** Any field can declare a condition against
a **sibling** in the same scope:

```json
{ "name": "tem_cta", "type": "boolean", "label": "Tem CTA" },
{ "name": "cta_url", "type": "url", "required": true,
  "show_if": { "field": "tem_cta", "equals": true } },
{ "name": "coords",  "type": "text",
  "show_if": { "field": "tipo", "in": ["mapa", "video"] } }
```

Two operators, `equals` and `in` — enough for the real cases (a checkbox
gating a block, a select choosing a branch) without turning site.json into an
expression language.

- **Comparison is loose on purpose.** A `boolean` arrives as a real bool from
  JSON but as `"1"`/`""` from a raw POST, and a numeric `select` comes back a
  string. Strict comparison would break `"equals": true` in the single most
  common case.
- **Hidden drops `required`.** A required field the user can't see blocks
  submit with nothing on screen to explain it; the attribute returns when the
  field reappears.
- **Scope is the flat values array**: siblings, plus anything inside a
  `group`/`tabs` (transparent to storage). A repeater row is its own scope —
  a row's field can't depend on something outside the row, and
  `schema:validate` rejects it.
- **Values of hidden fields are still saved.** Hidden is not deleted, so
  toggling back and forth doesn't lose what was typed.
- A malformed condition leaves the field **visible** — disappearing from a
  config typo is worse than one extra field, and `schema:validate` catches the
  typo anyway (unknown target, self-reference, missing or duplicated operator).

The initial state is rendered server-side; `admin.js` only keeps it in sync as
the editor types, so nothing flashes and a JS error elsewhere doesn't leave the
form showing everything.

**`title_field` — labelling repeater rows.** Collapsed rows read `ITEM 01`,
`ITEM 02` by default, which turns a list of ten into an index of nothing.
Point `title_field` at a subfield and the row shows that value instead:

```json
{ "name": "links", "type": "repeater", "label": "Links principais",
  "title_field": "rotulo",
  "fields": [
    { "name": "rotulo",  "type": "text", "label": "Rótulo" },
    { "name": "destino", "type": "link", "label": "Destino" }
  ]
}
```

- **Which types work**: `text`, `email`, `url`, `tel`, `number`, `textarea`,
  `select`, `link` — the ones whose value already *is* the text a human would
  read. `image`/`gallery`/`file` store ids or metadata, `boolean` doesn't
  distinguish one row from another, `richtext` is markup, and `item` is an id
  that only becomes text with a DB lookup. `schema:validate` rejects the rest
  by name and lists what's allowed.
- **`select` shows the option's label**, not the stored value —
  `{"br": "Brasil"}` renders `Brasil`.
- **`link` shows the title, falling back to the URL** when the title is
  blank. A bare URL still identifies the row better than `ITEM 03`.
- **Empty value falls back to the number.** That's a CSS counter
  (`.repeater__row-title:empty::before`), so it keeps renumbering itself on
  drag-reorder and delete with no JS involved — the reason the server writes
  an *empty* span rather than `Item 3` when there's nothing to show.
- The subfield may live inside a `group` or `tabs`; those are transparent to
  storage, so the lookup descends into them.
- The label is rendered server-side and kept in sync by `admin.js` as the
  editor types, so it survives a JS error elsewhere on the page.

Only `repeater` takes this. `flexible` rows are already labelled by their
layout name.

**Flexible content example:**

```json
{
  "name": "blocks",
  "type": "flexible",
  "label": "Conteúdo",
  "layouts": {
    "hero":     { "label": "Hero",         "fields": [ {"name": "title", "type": "text"}, {"name": "image", "type": "image"} ] },
    "two_cols": { "label": "Duas colunas", "fields": [ {"name": "left",  "type": "richtext"}, {"name": "right", "type": "richtext"} ] },
    "cta":      { "label": "CTA",          "fields": [ {"name": "label", "type": "text"}, {"name": "url",   "type": "url"} ] }
  }
}
```

The key `_layout` is reserved — don't use it as a subfield name. Saved
rows look like `[{"_layout": "hero", "title": "...", "image": 12}, ...]`.

**Group / tabs example (organizational containers):**

```json
[
  {
    "type": "group",
    "label": "Hero",
    "fields": [
      {"name": "hero_title", "type": "text",  "label": "Título"},
      {"name": "hero_image", "type": "image", "label": "Imagem"}
    ]
  },
  {
    "type": "tabs",
    "tabs": [
      {"label": "Conteúdo", "fields": [ {"name": "body", "type": "richtext"} ]},
      {"label": "Contato",  "fields": [ {"name": "telefone", "type": "text"} ]}
    ]
  }
]
```

Storage stays flat: the example above saves `{"hero_title": ..., "hero_image": ...,
"body": ..., "telefone": ...}` — the containers never appear as storage keys.
In templates, read the values exactly like ungrouped fields: `field('hero_title')`.

## Templates

### Resolution order

1. **Pages (structural, from `pages`)**: `template` from `site.json` → fallback `page.php`.
2. **Pages (admin-created, from `page_templates`)**: the `file` of the chosen
   `page_templates` entry → fallback `page.php`. URL is `/{slug}` set in the admin.
3. **Items single**: the item type declares either `template` (single file,
   all items) or `templates` (a map of named custom templates the editor
   picks per item) — never both; `schema:validate` rejects the combination.
   In list mode, an item without a matching template key falls back
   straight to `single.php`.
   When neither the declared file nor its fallback (`page.php`,
   `single.php`, `taxonomy.php`) exists in `theme/templates/`, that URL is
   a 404 (never an error page), and `schema:validate` lists it under
   "Warnings:" — the site.json is still valid.
4. **Item archives**: intentionally **not a route**. `/{item-type-slug}`
   alone always 404s — item-type archives were built, then deliberately
   removed (a closed design decision, not open work). See "URL
   conventions" for the supported alternative.
5. **Taxonomy archives**: taxonomy's `template` from `site.json` → fallback `taxonomy.php`.

**Never associate templates by slug.** Always reference template files in
`site.json`. Slugs change; bindings shouldn't break.

### Template skeleton

Every template should follow this pattern:

```php
<?php /** @var \Velk\TemplateContext $item */ ?>
<?php
\with_context($item, function () {
    get_header();
    ?>
    <article>
        <h1><?= e(the_title()) ?></h1>
        <div><?= field('content') /* trusted HTML from TipTap */ ?></div>
    </article>
    <?php
    get_footer();
});
?>
```

**Two ways to read a record, both first-class.** The ambient helpers are the
default and what most themes use. The same values are reachable straight off
the object, with no `with_context()` at all:

```php
<?php foreach (items('post') as $post): ?>
    <a href="<?= e($post->url()) ?>"><?= e($post->title()) ?></a>
    <p><?= e($post->field('resumo')) ?></p>
<?php endforeach; ?>
```

`Item`, `Page` and `Term` all expose `field()`, `url()` and a title accessor
(`title()`; `name()` on `Term`). **They resolve the active request language**,
delegating to `fieldIn()`/`titleIn()`, which fall back to the default-language
value when a translation is missing — so a field nobody translates (a price)
reads the same either way.

Pick by situation rather than taste: the ambient form keeps templates terse and
lets a `partial()` read the record without it being threaded through; the object
form is explicit about which record it reads, which is what you want in
`functions.php`, in a webhook handler, or anywhere the value is computed rather
than printed. `field()` returns its default *silently* when there's no ambient
context, so code far from a template is safer explicit.

Wrapping in `\with_context($context, fn ...)` is what makes `field()`,
`the_title()`, etc. work without passing the record around.

### Template variables

The template receives a single variable depending on its type:

| Template type | Variable | What |
|---------------|----------|------|
| `page-*.php` | `$page` | `TemplateContext`, `$page->record` is `Page` |
| `single-*.php` | `$item` | `TemplateContext`, `$item->record` is `Item` |
| `taxonomy-*.php` | `$term` | `TemplateContext`, `$term->record` is `Term`, `$term->records` is `list<Item>` |

There is no `archive-*.php` convention / `$archive` variable — item-type
archives were removed by design; see "URL conventions".

## Helpers

All helpers are in `core/helpers.php` and globally available in templates.

### Output

- `e($value)` — **escape-by-default**. Use ALL the time:
  `<?= e($value) ?>`. The only exception: trusted HTML (TipTap richtext output,
  which is HTML on purpose). Comment when you skip escaping.
- `csp_nonce()` — the page's nonce, for every `<script>` in the theme:
  `<script nonce="<?= csp_nonce() ?>">`. See
  [Content Security Policy](#content-security-policy-public-site).
- `csp_nonce_scripts($html)` — adds the page's nonce to every `<script` in a
  piece of trusted third-party HTML (an embed pasted by the editor).
- `richtext($html)` — a richtext field's HTML with the theme's blocks
  rendered: `<?= richtext(field('content')) /* trusted */ ?>`. See
  [Richtext blocks](#richtext-blocks).
- `json_script($id, $data)` — data for the page's JavaScript, as
  `<script type="application/json" id="…">`, which the browser stores and
  never runs. Read it with `JSON.parse(document.getElementById(id).textContent)`.
  `<`, `>`, `&` and quotes are escaped, so a `</script>` inside a value can't
  close the block. Themes can use it too.
- `dd(...$values)` — debug dump and exit.

### Context

- `current_context()` — current `TemplateContext` if any
- `with_context($context, fn() => ...)` — push a context for a block
- `field($name, $default = null)` — read a custom field from current context
- `the_title()` — title from current context
- `the_slug()` — slug from current context
- `the_url()` — URL from current context

### Items / Terms

- `items($type, $args = [])` — query items. Args: `limit`, `offset`, `status`,
  `term`, `order`. Default status = `published`.
- `terms($taxonomy)` — all terms in a taxonomy.
- `the_terms($taxonomy)` — terms attached to current item.
- `item($id)` — resolve an `item`-field value (an integer id) to the `Item`
  model, or `null` when empty/missing/deleted. Idiom: `<?php $p = item(field('produto_destaque')); ?>`
  then `<?php if ($p): ?>…<?= e($p->title) ?>…<?php endif; ?>`. Check `$p->status`
  if you want to hide items that are no longer published.

### Manual ordering

By default items come out newest-first (`published_at DESC`) and terms come
out alphabetically. That's right for a blog and wrong for a showcase — a
product grid, a team page, a FAQ. Set `manual_order` to get a curated order
you drag in the panel instead:

```json
"item_types": { "produto":   { "manual_order": true, … } },
"taxonomies": { "categoria": { "manual_order": true, … } }
```

**The theme changes nothing.** `items('produto')` and `terms('categoria')`
start returning the panel order on their own, through the same helpers a
theme already calls.

**`the_terms()` doesn't inherit this.** It calls `Item::terms()` — a
separate, per-item join query — which still hardcodes `ORDER BY t.name ASC`
and never checks `Term::manualOrder()`. Turn on `manual_order` for
`categoria` and the category badges on an item page keep printing
alphabetically, even though `terms('categoria')` and the panel listing both
show the curated order.

**An explicit `order` still wins**, exactly as it always has. `items()`
keeps accepting `['order' => 'title ASC']`, and a template that already
passes one keeps getting exactly that order, flag or no flag:

```php
items('service', ['order' => 'published_at ASC, created_at ASC'])
```

Turning on `manual_order` for `service` does **not** change what this
template shows — an explicit request has always beaten the type's default,
and the manual order only ever becomes that default. This is correct (a box
ticked in the admin shouldn't silently override a template's own request),
but it's also the first thing anyone enabling the flag will report as "the
drag doesn't do anything." The fix lives in the template: drop the `order`
key so the type's default — now the manual one — takes over. `terms()` never
took an `order` argument to begin with, so this caveat only applies to
`items()`.

Turning the flag on does **not** reshuffle the site: the first time the
panel list opens, the order already on screen is written into the
`sort_order` column, and you drag from there.

Three limits worth knowing:

- **The list is still paginated at 50.** Dragging moves a row **within its
  page** — you cannot drag from page 3 to the top.
- **On the items listing, search and the status filter stay usable while
  dragging**, because reordering only permutes the `sort_order` values the
  visible rows already held. A row hidden by the filter, sitting between
  two you moved, stays exactly where it was. The terms listing has no
  search or status filter to begin with, so this bullet doesn't apply
  there.
- **A taxonomy archive page (`/categoria/valor`) only gets manual order when
  exactly one item type declares that taxonomy, and that type has the flag.**
  A taxonomy shared by two or more types keeps date order there instead —
  each type keeps its own `sort_order` sequence starting at 1, and
  interleaving two unrelated sequences wouldn't mean anything. The
  taxonomy's own panel listing is unaffected either way; this limit is
  specific to the front-end archive page. There's also no theme-side escape
  hatch here the way `items()` has one: `Item::byTerm()` takes only a term
  id and a limit, no `order` argument, and its one caller in `FrontRouter`
  doesn't pass one — a theme can't force a different order on this page,
  flag or no flag.

Types and taxonomies without the flag always come out in the same **order**
as before. Whether `byTerm()` runs the same **queries** is conditional,
though: it skips the extra `SELECT taxonomy FROM terms` lookup only when no
item type anywhere in `site.json` has `manual_order` on. Turn it on for even
one unrelated type, and every taxonomy archive page — including one for a
taxonomy whose owning type never set the flag — pays for that one extra
query. The order returned doesn't change; only the query count does.

### URLs / media

All URL helpers automatically prepend the install **base path** (the URL prefix
when Velk is mounted in a subdirectory like `/velk/`). So every internal link
keeps working whether the site is at `https://site.com/` or
`https://site.com/velk/`.

- `url($path = '/')` — root-relative URL for an internal route. Includes base
  path. Use this for navigation links, never hardcode `/sobre`.
- `absolute_url($path = '/')` — full scheme + host URL. Reads `app.url` from
  env. Use for canonical tags, og:url, sitemap.
- `admin_url($path = '/')` — admin URL with the configured admin prefix.
- `asset($path)` — root-relative asset URL (CSS, JS, images at the project
  root or under `theme/...`, plus engine-served paths like `uploads/...`).
- `base_path()` — the prefix itself (for raw concatenation if needed).
- `image_url($value, $size = 'full')` — resolve an image-field value to a URL.
  Accepts: **integer media id** (canonical), absolute URL, root-relative path,
  bare filename, or arrays. Returns `''` when the media is missing/deleted.
  `$size` names an image **variant** (`'thumb'`, or any name declared in a
  field's `variants` — see "Image variants" below) and only matters when
  `$value` resolves to a media id. Pure existence check: if that variant
  was generated and its file is still on disk, its URL comes back;
  otherwise this falls back to the URL of the **original** file — never a
  404. `'full'` (the default) always returns the original. Raw URLs,
  root-relative paths, and bare filenames pass through unaffected by `$size`.
- `file_url($value)` — resolve a `file`-field value (media id) to a URL.
  Alias of `image_url($value)` — same input handling, works for any media
  type, not just images.
- `image_alt($value, $fallback = '')` — alt text saved on the media record.
  i18n-aware: when the active language differs from default and the media
  has an `alt_translations` entry for it, returns the translated alt.
  Falls back to the default alt, then to `$fallback`, then to `''`.
- `media($id)` — return the `Media` model for that id (or `null`). Useful when
  you need width/height, mime, etc.
- `link_tag($link, $text = null, $attrs = [])` — render a `link`-field value as a
  safe `<a>`. `$link` is the saved `{title, url, new_tab}` array (or `null`);
  returns `''` when there's no URL. Adds `target="_blank" rel="noopener noreferrer"`
  when `new_tab` is set. Link text defaults to `$text`, then the saved `title`,
  then the URL. Extra `$attrs` (e.g. `['class' => 'btn']`) become escaped
  attributes. Everything is escaped with `e()`. Idiom: `<?= link_tag(field('cta')) ?>`.

**Image field idiom** — `field('image_field')` returns the raw value (an
integer id). Always go through `image_url()` and check the result is non-empty
before rendering, so deleted media doesn't produce broken `<img>` tags:

```php
<?php $src = image_url(field('thumbnail')); ?>
<?php if ($src !== ''): ?>
    <img src="<?= e($src) ?>" alt="<?= e(image_alt(field('thumbnail'))) ?>">
<?php endif; ?>
```

### Image variants

Every uploaded raster image (jpeg/png/gif/webp — never svg) gets a global
**`thumb`** variant generated at upload time: a 400px box, `contain` fit,
downscale-only. It's what the admin uses for media previews, and themes
can request it too: `image_url($id, 'thumb')`. The name `thumb` is
reserved — a field can't declare its own variant called `thumb`
(`schema:validate` rejects it).

Fields can declare their own sized variants. Any `image` or `gallery`
field may carry a `variants` array:

```json
{
  "name": "foto",
  "type": "image",
  "label": "Foto",
  "variants": [
    {"name": "card",   "width": 600,  "height": 500, "fit": "crop"},
    {"name": "wide",   "width": 1200,                "fit": "contain"},
    {"name": "banner",                "height": 400, "fit": "cover"}
  ]
}
```

- `name` — lowercase slug (`^[a-z0-9][a-z0-9-]*$`); becomes a directory
  under `storage/uploads/_variants/`. **Site-global label**: the same
  name must mean the same `{width, height, fit}` everywhere it's
  declared — `schema:validate` errors on conflicting redeclarations.
- `width` / `height` — the target box, either or both. Exact size for
  `crop`; max size for `contain`/`cover`.
- `fit` (default `contain`):
  - `contain` — scales by the SMALLER ratio; the whole image fits inside
    the box ("caber"). A 1000×1000 source in a 600×500 box → 500×500.
  - `cover` — scales by the LARGER ratio; the image covers the box, one
    side may exceed it ("ocupar"). Same source → 600×600.
  - `crop` — like `cover`, then center-crops to EXACTLY `width`×`height`
    (requires both). Same source → 600×500.
  - All three preserve aspect ratio and never upscale past the source.

SVG uploads are exempt from all of this — they're already scalable, so
`variants` on an SVG-backed field are never generated; `image_url()` just
returns the SVG itself regardless of `$size`.

**Generation is write-time, never lazy.** The thumb is generated at
upload; field variants are generated when content is saved (page, item,
option, term) — never on demand at render/URL time (that was the failure
mode of the older, removed design). Both paths are idempotent (an
existing variant file is left alone) and non-fatal (missing GD, or any
GD error, just skips generation — the original stays servable).
At-save generation walks the WHOLE field tree, so `image`/`gallery`
fields nested inside `group`, `tabs`, `repeater` or `flexible` (at any
depth) get their variants just like top-level ones. Member fields can
declare `variants` too — the registry and
`schema:validate` treat them like any other image/gallery field — but
member saves never trigger generation; those images only get variants
from the CLI backfill.

**Resolution is pure** — `image_url($value, $size = 'full')` and
`Media::url($variant = 'full')` just check disk: variant file exists →
its URL; missing → the ORIGINAL's URL. Never a 404, never triggers
generation. `'full'` (default) always returns the original.

**CLI:** `bin/velk media:variants [--regen]` backfills the thumb + every
declared variant for every image — a superset, so it also covers nested
repeater/flexible fields and translations that save-time generation
skips. `--regen` rebuilds files that already exist (use after changing a
variant's dimensions). Run it after upgrading an existing site to a
version with this feature, or after editing `variants` in `site.json`.

### Forms (admin only)

- `csrf_token()`, `csrf_field()` — CSRF protection. **Always include
  `<?= csrf_field() ?>` in admin forms.**

### Layout

- `get_header()`, `get_footer()` — include `theme/partials/header.php` /
  `footer.php`.
- `partial($name, $data = [])` — include `theme/partials/{name}.php`.
- `tracking_head()`, `tracking_body_start()`, `tracking_body_end()` — echo
  raw tracking/analytics snippets (GTM, Meta Pixel, chat widgets) pasted by
  an admin into the built-in "Scripts & rastreamento" options group. Call
  at the standard placement points (just before `</head>`, right after
  `<body ...>`, just before `</body>`). Output is intentionally unescaped —
  admins paste provider-supplied HTML/JS as-is; the only change is the page's
  nonce added to each `<script>` (see
  [Content Security Policy](#content-security-policy-public-site)). No-op
  when the option is empty.
  When the theme declares `consent.categories`, these same three helpers apply
  the consent gate — see "Consent-gated scripts" below. No theme change needed
  either way.

### Misc

- `slugify($string)` — turn a string into a slug.
- `config($key)` / `site($key)` — config access via dot notation.

## Database

Tables (see `migrations/` for full schema — that folder is removed by the install skill after applying, but remains the source of truth in the repo):

- `users` — admin users
- `pages` — one row per `pages.{key}` from `site.json`
- `items` — every post/case/etc. `fields` is JSON.
- `terms` — taxonomy terms. `fields` is JSON.
- `item_term` — N:N relation
- `media` — uploaded files
- `settings` — key/value JSON store
- `migrations` — applied migration log

**`fields` columns are JSON.** Items and terms have native MySQL JSON columns
that hold all custom field values. To query inside JSON, use MySQL's
`->>'$.field_name'` operator.

## CLI commands

```
./bin/velk migrate                       Run pending migrations
./bin/velk migrate:status                Show pending migrations
./bin/velk user:create <email> <pw> <nm> Create a new admin user
./bin/velk page:sync                     Seed page records from site.json
./bin/velk schema:validate               Validate site.json (exit 1 on issues; "Warnings:" never fail)
./bin/velk serve [port]                  Start built-in dev server (default 8080)
./bin/velk i18n:scan                     Scan theme+core for t() calls, register in the i18n catalog
./bin/velk i18n:scan-admin               Lint __() usage in core/Admin against lang/{en,pt,es}.php (CI gate)
./bin/velk form:export <form-id>         A form's submissions as CSV on stdout
./bin/velk csp:check                     What strict CSP would block in the theme, plus security.csp in site.json
```

`form:export` writes the same file as the **Export** block on the form's
admin screen: approved, completed submissions only (never spam, never a
multi-step lead still in progress), newest first, semicolon-separated, with a
UTF-8 BOM so Excel reads accents. By default the file has the form's fields
only; `--cols=name,email,@meta,@params` picks the columns — field names from
`site.json`, plus `@meta` (id, date, IP, browser, referrer, email status,
recipients) and `@params` (the UTM and click-ID columns); an unknown column is
an error. `--from=YYYY-MM-DD` and
`--to=YYYY-MM-DD` bound the period, `--sep=,` switches to commas, `--no-bom`
drops the BOM. Errors go to stderr, so stdout is only ever the file —
`./bin/velk form:export contact > leads.csv` — and any exit but 0 means the
file is incomplete: 1 for a bad flag, an unknown form, or a failure
mid-export (a missing table, a lost connection, a write refused by a full
disk — `Export failed: …`); 2 when the database can't be reached at all,
which stops `bin/velk` before the command runs (`Setup error: …`). The one
difference from the panel's file is the metadata column headers: the panel
writes them in the logged-in user's admin language, the command in
`admin.locale` (it has no session).

There's also a content-management CLI for scripting item/page edits without
the admin UI: `item:types`, `item:list`, `item:get`, `item:create`,
`item:update`, `item:publish`, `item:unpublish`, `item:delete`, `page:list`,
`page:schema`, `page:get`, `page:update`. Most support `--format=json`,
`--dry-run`, and `--json-stdin` (read the `--json` payload from stdin,
avoiding shell-quoting issues). Run `./bin/velk --help` for the full list
with flags.

### Auto-migrate on web boot

An installed Velk site automatically applies pending migrations on the first web request after an upgrade. This lets no-shell hosts (shared hosting without SSH) upgrade by simply uploading the new engine files — no CLI step required.

Concurrent requests are serialized with a native MySQL `GET_LOCK` so only one request runs the migration at a time.

**`AUTO_MIGRATE`** (`.env`) — enabled by default; set `AUTO_MIGRATE=false` to disable and manage migrations manually via `bin/velk migrate`.

**What the visitor may see during an upgrade:**

- **"Atualizando…"** — another request is already applying migrations; the page auto-refreshes in a few seconds.
- **"Atualização falhou"** — a migration failed. The page is intentionally generic (no stack trace shown to the public).

**Recovery from a failed migration:**

The detail (which migration + the error message) is written to `storage/cache/migration-error.json` and `storage/logs/migration.log` — readable via FTP or a file manager. After investigating and fixing the issue, run `bin/velk migrate` (via CLI or cron) — it applies any remaining migrations and clears the failure flag, restoring the site. Note: migrations are **not transactional** (DDL auto-commits in MySQL), so a partially-applied migration may need manual cleanup in the database before a safe retry.

## URL conventions

These paths are relative to the install — if Velk is mounted at `/velk/`, prepend
that prefix.

- `/` → home page (the page with key `home` in `site.json`, or with `url: "/"`)
- `/{page-key}` → other pages (or use explicit `url` in the page def)
- `/{item-type-slug}/{item-slug}` → single item
- `/{taxonomy-slug}/{term-slug}` → taxonomy term archive
- `/admin` → admin panel
- `/uploads/...` → media files
- `/theme/...` → theme assets (CSS, JS, images)

**`/{item-type-slug}` alone is not a route** (no automatic "archive"/list
view). This was built once and intentionally removed — a closed design
decision, not open work: to expose a list like `/blog`, declare a regular
`pages` entry (its own key, template, and URL) whose template lists items
itself with `items($type)`, exactly like any other page. Don't propose
re-adding `archive_template` / an automatic archive route unless asked to
revisit that decision.

All routes respond to **GET and HEAD** (HEAD mirrors GET's status/headers,
body omitted) — not GET-only.

**Hierarchical item types/taxonomies** (`"hierarchical": true` on the
`item_types.{type}` or `taxonomies.{tax}` def) nest via
`/{type-slug}/{parent-slug}/{child-slug}/...`, matched level by level.
Structural pages nest the same way via a `"parent": "<other-page-key>"` key
in their `site.json` def; admin-created custom pages get a parent picker in
the admin UI. In all three cases slugs only need to be unique among siblings.

### Subdirectory installs

Velk detects its base path automatically from `$_SERVER['SCRIPT_NAME']`. For
hosts where autodetect fails, set `APP_BASE_PATH` in `.env`:

```
APP_BASE_PATH=/velk
```

(Leave empty/unset to autodetect, or set to an empty string to force a root
install. This lives in `.env`, not `config/env.php` — that file just reads
the variable via `Env::get('APP_BASE_PATH', null)`.)

When writing templates, **always** go through helpers (`url()`, `asset()`,
`admin_url()`, `image_url()`, `Item::url()`, `Term::url()`,
`Page::pageUrl()`). These all prepend the base path. Never hardcode root
paths like `/blog` — they break in subdirectory installs.

## Security

- **Always escape** with `e()`. The only allowed unescaped output is trusted
  HTML (TipTap richtext output). Mark these explicitly:
  `<?= field('content') /* trusted */ ?>`.
- **CSRF protection** via `csrf_field()` is enforced on all admin POST routes.
  A failed check (`Controller::verifyCsrfOrFail()`) is almost always a session
  that died with the form open, so it reads as "session expired", not as an
  attack: a script request (`X-Requested-With`, `Accept: application/json`,
  or `Sec-Fetch-Dest: empty` — a plain `fetch` sends no X-Requested-With, and
  following a redirect would hand it an HTML page with 200) gets JSON 403; a
  form posted inside an iframe (`Sec-Fetch-Dest: iframe`, the block window)
  gets a short page there; a normal POST is redirected (303) with
  `error_code: session_expired` flashed and a fresh token — to the login when
  nobody is signed in, to the Referer when it is the site's own
  (`Request::refererSameHost()`), to the dashboard otherwise (the login would
  bounce a signed-in user there and lose the flash). The flash is a code,
  worded by `Controller::flashErrorText()` in the language of the screen that
  shows it.
- **The admin runs with a CSP that has no `'unsafe-inline'` in `script-src`**
  (`Controller::ADMIN_CSP`), so a script injected through an escaping bug
  doesn't execute. Admin views never carry executable inline JavaScript: no
  inline `<script>` (a `json_script()` data block or a `text/template` isn't
  executable), no `on…=` attribute, no `javascript:` link. Behavior goes in
  `core/Admin/assets/js/admin.js` (`data-autosubmit` for a filter that submits
  on change), and data the page hands to it goes in a data block via
  `json_script()` — the JS translations live in `layout/_js_i18n.php`.
  `tests/unit/admin_csp_script_test.php` scans the admin views and
  `FieldRenderer` for inline scripts and handlers. `style-src` keeps
  `'unsafe-inline'`. `form-action 'self'`: an admin form only posts to the
  site, so an injected fake login can't send a password off-site (form-action
  doesn't fall back to `default-src`; a future admin flow that hands off to
  another origin — a payment provider, OAuth — needs that origin in the
  policy, since the browser checks the redirect after the POST too; a gateway
  in front of the admin — Cloudflare Access, an OAuth proxy — that answers an
  expired session's POST with a redirect to its own login gets that redirect
  refused, and the click looks like a no-op until a reload). Behind Cloudflare
  with JavaScript detections on, the admin's console shows Cloudflare's
  injected inline script refused: nothing is enforced unless a WAF rule uses
  `cf.bot_management.js_detection.passed` — keep the admin path out of such a
  rule.
- **The admin asks not to be indexed.** Every response under the admin prefix
  carries `X-Robots-Tag: noindex, nofollow` (`AdminRouter::noIndex()`), and
  the admin layouts add `<meta name="robots" content="noindex, nofollow">`.
  Don't list the admin in `robots.txt`: `Disallow` only stops the visit, so
  a crawler that obeys it never sees the `noindex`, and `robots.txt` is
  public — it would give away an admin path that was changed.
- **The public site's CSP comes from `site.json`** (`security.csp`, read by
  `Velk\PublicCsp`), see
  [Content Security Policy](#content-security-policy-public-site).
- **PDO with prepared statements** throughout. No string interpolation in SQL.
- Sensitive files (`config/env.php`, `*.json`, `*.md`, `*.lock`, `.env`,
  `.example`) and internal directories (`core/`, `config/`, `migrations/`,
  `storage/`, `bin/`, `vendor/`, plus all PHP files inside `theme/`) are
  blocked by the root `.htaccess`.

## Richtext blocks

A theme can declare its own content blocks — big numbers, a testimonial, a
video — for the author to drop between the paragraphs of a `richtext` field.
A block is structured data filled in a form; its HTML belongs to the theme.

**Declare the block** once, at the top level of `site.json`, and list it on
each richtext field that accepts it. A field accepts none by default:

```json
"richtext_blocks": {
  "bignumbers": {
    "label": "Big numbers",
    "file":  "bignumbers.php",
    "fields": [
      { "name": "itens", "type": "repeater", "label": "Números", "title_field": "rotulo", "fields": [
        { "name": "valor",  "type": "text", "label": "Número" },
        { "name": "rotulo", "type": "text", "label": "Rótulo" }
      ] }
    ]
  }
}
```

```json
{ "name": "content", "type": "richtext", "label": "Conteúdo", "blocks": ["bignumbers"] }
```

- The key is lowercase letters, digits and `_`, starting with a letter. It
  goes into the stored HTML.
- `label`, `file` and a non-empty `fields` list are required. Any field type
  works: `image`, `gallery`, `repeater`, `flexible`, `{"group": "…"}`
  includes.
- `file` is the partial that renders the block, like a template's `file`: a
  file name only (`.php` optional), always in `theme/partials/richtext/`. The
  binding is the declaration, never the key — `"file": "numeros.php"` works
  for the key `bignumbers`.
- A top-level field of a block becomes a variable in its partial, so its name
  has to be a valid PHP variable name (`cta_label`, not `cta-label`).
  Repeater subfields are array keys and take any name.
- No block inside a block: a richtext field of a block can't list `blocks`.

**Write the partial**, `theme/partials/richtext/bignumbers.php` — the `file`
declared above. It receives each field of the block as a variable, like
`partial()`:

```php
<div class="bignumbers">
    <?php foreach ($itens as $n): ?>
        <div class="bignumbers__item">
            <strong><?= e($n['valor']) ?></strong>
            <span><?= e($n['rotulo']) ?></span>
        </div>
    <?php endforeach; ?>
</div>
```

A field missing from an old post arrives empty — `null` for a text, `false`
for a boolean, an empty list for a repeater, at any depth —, so the partial
doesn't break when the block, or a repeater inside it, gains a field later.
A text can therefore be `null`: print it with `e()`, and cast it with
`(string)` before a string function. The CSS is the theme's: the engine
injects none. The block prints inside the container where the theme prints the
text, so it inherits that container's styles.

**Print the field through `richtext()`:**

```php
<?= richtext(field('content')) /* trusted */ ?>
```

Without the helper the block never shows, and nothing breaks: what is stored
is a marker, an empty tag. A block that can't be printed — not declared, no
partial, broken data — prints nothing; with `APP_DEBUG=true` the reason goes
to the PHP error log. A value that isn't a string (old data of a field that
changed type) prints nothing too.

A partial that prints a `<script>` or an `<iframe>` (a video embed) follows
the site's [Content Security Policy](#content-security-policy-public-site)
like any template. In `strict`, a script needs `csp_nonce()`. An `<iframe>`
needs nothing, unless the site already restricts frames with
`security.csp.allow.frame`: then the embed's domain has to be in that list.

**In the admin**, the toolbar of such a field gains a **+ Blocks** button. It
opens a row below the toolbar with the blocks the field accepts — a row
even when there is only one, so the author sees what goes in; picking one
opens a window with that block's fields, and saving puts a card in the text
where the cursor is. The card shows the block's name and a one-line summary,
not the site's look, between a drag grip on the left and **Edit** and **×**
on the right — the same grip and × as a repeater row. The whole card drags
(the grip only says so); × removes the block from the text without asking,
and Ctrl+Z brings it back. The block window is an iframe; its image,
gallery and file fields — and the image button of a rich text inside it —
open the media library over the whole panel, not inside the window
(`openMediaPicker()` in `admin.js` opens it in the topmost admin window and
hands the pick back to the field).

- A block only lives at the top level of the text: between paragraphs, never
  inside a list, a quote or a table. With the cursor in a list, inserting a
  block splits the list in two; in a table or an image caption, the card goes
  right after it.
- A stored block that the field no longer accepts, or that left `site.json`,
  stays in the text as "unavailable". It can be moved — dragged, or cut and
  pasted in the same field — or deleted with its ×; the editor never drops it
  on its own.
  "Unavailable" is about the editor only: on the site, `richtext()` still
  prints a block that is declared in `richtext_blocks`, whatever the field
  lists.
- A marker the block window can't show — `data-velk-data` that isn't a JSON
  object, or content inside the tag (written outside the admin) — becomes a
  card without **Edit** that says its data can't be read. Saving the text
  keeps its `data-velk-data` as it was and what was inside the tag (as the
  browser reads it); other attributes on the tag are dropped. A marker that
  comes in by pasting enters empty — content inside it, from another page,
  never reaches the text.
- Pasting a block only works into a field that lists it, or that already had
  that block when the text was opened.

**What is stored**, for content written outside the admin (the CLI, an
agent). The field is still one HTML string, and the block is an empty `<div>`
with two attributes:

```html
<p>Before.</p>
<div data-velk-block="bignumbers" data-velk-data='{"itens":[{"valor":"120+","rotulo":"Clientes ativos"}]}'></div>
<p>After.</p>
```

`data-velk-data` is a JSON object with the block's field values, in the shape
the admin stores for any form (an image is its media id). The editor writes
the attribute with double quotes and HTML entities; single quotes, as above,
read the same. Inside the attribute, write `&` as `&amp;` and the quote that
delimits it as an entity (`&#39;` or `&quot;`). Keep the tag empty and at the
top level of the text. `item:create`, `item:update` and `page:update` refuse a
marker whose `data-velk-data` isn't a JSON object, whose tag can't be read (a
raw apostrophe inside a single-quoted value ends it), or that has content
inside, and say which field and block.

Image variants for a block's images are generated when the block is saved in
the admin window. For content written outside it, run
`bin/velk media:variants`.

`schema:validate` checks the declaration: block keys, `label`, `file` and
`fields`, that the declared file exists, every `blocks` list, and — once some
field lists a block — that some theme file calls `richtext()`.

## Content Security Policy (public site)

The core sends the public site a Content Security Policy declared in
`site.json` (`security.csp`), next to the theme: what the site loads from
elsewhere — its embeds, where its forms post — is the theme's knowledge, and
the policy travels with it between environments. Only the report collector
(`CSP_REPORT_URI`) stays in `.env`, since it differs per environment. The
admin keeps its own policy; a response that already carries one (the SVG
under `/uploads`), a policy the theme sent with `header()` and anything that
isn't HTML are left alone — with a theme policy in place the core sends none,
so `security.csp` has no effect until the theme drops it. No mode sets
`default-src`: whatever isn't listed stays unrestricted.

```json
"security": { "csp": {
    "mode": "strict",
    "frame_ancestors": ["https://partner.example.com"],
    "allow": {
        "frame": ["https://www.youtube.com", "https://www.google.com"],
        "form": ["https://*.list-manage.com"]
    }
} }
```

| `mode` | Header |
|---|---|
| `open` (default, and with no block) | `frame-ancestors 'self' …; base-uri 'self'; upgrade-insecure-requests` — breaks nothing, restricts no script |
| `strict` | `script-src 'nonce-…' 'strict-dynamic'`, `object-src 'none'`, `base-uri`, `form-action`, `frame-ancestors`, the domain lists below |
| `report` | the `open` policy, enforced, plus the `strict` policy as `Content-Security-Policy-Report-Only`: no script is blocked, the browser reports what strict would block |
| `off` | none |

`upgrade-insecure-requests` goes out only over HTTPS, and never in the
Report-Only header, where browsers ignore it. An unknown mode falls back to
`open`, and an invalid source is dropped. Both go to the PHP error log. In
`open` and `off` the log gets only what changes the site in those modes: a
block that isn't an object, an unknown key or a wrong mode in
`security.csp`, `frame_ancestors`, an old variable in `.env`. So an error in
a list doesn't fill the log on every page. `csp:check` reports every
problem in the block and in `.env`; `schema:validate`, the ones in
`site.json`, plus a block in the wrong place (`security.mode`, a top-level
`csp`) — which neither the runtime nor `csp:check` sees: its first line then
shows the mode as `open`.

**Strict mode runs only the scripts that carry the page's nonce.** The core
adds it to everything it prints — `form_honeypot()`, `form_captcha()`, the
consent runtime, the scripts registered in the admin (inside the base64 of
the deferred ones too) and the conversion events. The theme adds it to
every `<script>` in its templates, `src=` ones included:

```php
<script nonce="<?= csp_nonce() ?>" src="<?= e(asset('js/app.js')) ?>" defer></script>
<script nonce="<?= csp_nonce() ?>">initMenu();</script>
```

What those scripts load afterwards inherits the trust (`'strict-dynamic'`):
the tags GTM fires, the scripts the marketing team pastes in the admin. So
nobody has to list GTM's domains, and there is no script list. Third-party
HTML the theme prints from a field (an Instagram embed with its own
`<script>`) goes through `csp_nonce_scripts($html)`, which adds the nonce to
every `<script` in it that has none — only for content the editor is trusted
to paste.

What no nonce unlocks: `on…=` attributes (`onclick`, `onload`) and
`javascript:` links — use a listener in a script file —, `eval()` /
`new Function()` unless `"allow_eval": true` (GTM custom JavaScript
variables and Alpine.js need it), and `<object>` / `<embed>`. Browsers older
than `'strict-dynamic'` support (Safari before 15.4) run the page's own
scripts and drop what they load. Run `./bin/velk csp:check` before turning
strict on: it lists every `<script>` without a nonce, `on…=` and
`javascript:` in the theme's templates, a
`<meta http-equiv="Content-Security-Policy">` in them (it would apply
together with the core's), a `header('Content-Security-Policy…')` in the
theme's PHP (the core then sends no policy of its own), a
`<form action="https://…">` (or a button's `formaction`) to another site
that nothing allows (see below), `eval` in the theme's JS (a warning), a
problem in `security.csp` (an unknown mode or key, an invalid source) and an
old CSP variable left in `.env` — exit 1 when there is an error. It reads
`APP_URL`, so a form whose full address is the site's own isn't mistaken for
another site. The admin's *Scripts & tracking* screen warns in `strict` and
`report`.

**Where a form may send** (`form-action`) is always part of the strict
policy, like `base-uri` — enforced in `strict`, reported in `report`: to the
site itself, so an injected fake form (a member login, say) can't send what
the visitor types elsewhere. Two things widen it, and they add up:

- `security.csp.allow.form` — the destinations the site's forms post to (a
  newsletter form posting straight to an e-mail provider);
- the core itself: with subscriptions or the store on,
  `https://checkout.stripe.com` (and `https://billing.stripe.com` for the
  member portal). Those buttons post to the site, which redirects to Stripe,
  and the browser applies `form-action` to that redirect too.

Chrome checks **every redirect after a form is sent**, not only the form's
`action` (engines have differed here; list the destinations either way). So
besides Stripe, anything the site answers a form with a redirect to another
site is refused in strict: a `member_require_plan()` fallback pointing at an
external checkout, a `member_require()` login URL on another site, a route
the theme registers, a Stripe custom domain for Checkout or the portal —
list those in `security.csp.allow.form`. The same goes for a form pasted in
the admin or built by a script. `csp:check` reads templates as text, so it
can't see what PHP answers with or builds (an `action` printed from an
option included): these show up in the browser. The violation names the
address the form was sent to, not the redirect's destination — a violation
naming the site's own `/member/login` or `/forms/…` means the answer
redirected somewhere not allowed. In `report` the redirect goes through and
the new page replaces the old one, so read it through `CSP_REPORT_URI` or
with DevTools' *Preserve log*.

`open` sends no `form-action`.

**Scripts a CDN inserts at the edge** never pass through Velk, and `csp:check`
can't see them. Cloudflare puts the page's nonce, read from the header, on the
scripts of its JavaScript detections; a script a CDN inserts without it is
blocked in strict — check Cloudflare's Email Address Obfuscation
(`email-decode.min.js`), which decodes the e-mail addresses on the page. Run
`report` first and read what the browser reports.

**Domain lists** (`security.csp.allow`; CSP syntax: `https:`, `'self'`, or a
host with optional scheme, `*.`, port and path), for a site that wants
control over third parties. They apply in `strict` and `report`. A declared
list restricts its resource to the site itself and the list; absent, empty
or with every entry invalid (each one is reported), the resource stays free
(`["'self'"]` allows only the site):

| Key | Directive |
|---|---|
| `allow.connect` | `connect-src 'self' <list>` |
| `allow.img` | `img-src 'self' data: <list>` |
| `allow.frame` | `frame-src 'self' <list>` |
| `allow.form` | adds to `form-action 'self'`, which strict always sends (above) |

A declared list also gets what the core's own features load: with Turnstile
configured, `https://challenges.cloudflare.com` joins `allow.frame` (the
captcha widget is an iframe; its script carries the nonce), so declaring the
list never breaks the forms. The rest of the block:

| Key | |
|---|---|
| `frame_ancestors` | who may put the site in an iframe, besides itself (every mode) |
| `allow_eval` | `true` adds `'unsafe-eval'` to the strict `script-src` |
| `CSP_REPORT_URI` (`.env`) | one `https://` URL that receives violation reports (`strict`, `report`) |

**Up to v3.8.3 the policy came from `.env`** (`CSP_MODE`, `CSP_ALLOW_EVAL`,
`CSP_FRAME_ANCESTORS`, `CSP_SCRIPT_SRC`, `CSP_CONNECT_SRC`, `CSP_IMG_SRC`,
`CSP_FRAME_SRC`, `CSP_FORM_ACTION`). Those variables are no longer read.
Until the block is in `site.json`, a site that set them changes in any mode:
`strict` and `report` fall back to `open`, `off` starts sending the `open`
header, and the `CSP_FRAME_ANCESTORS` domains can no longer frame the site.
While one of them is set to something other than the old default (`open`,
`0`, empty), the PHP error log names it and its new place on every page, and
`csp:check` reports it. A site that used `CSP_SCRIPT_SRC` loses its host
restriction on scripts, and a same-origin script inserted without the nonce
or one written with `document.write` — which the list's `'self'` and hosts
let through — is now blocked: write the block with `"mode": "report"`, read
the reports after the upgrade, then set `"mode": "strict"`.

**A conversion snippet run by the theme** (the `event.snippet` of an AJAX
form submission) runs by creating a script element — never `eval`, which
strict blocks — and gets the nonce copied from the page. Read it with the
`nonce` property: browsers hide the attribute, so `getAttribute('nonce')`
returns an empty string.

```js
// At load time: document.currentScript is null inside a callback and in modules.
var NONCE = (document.currentScript || document.querySelector('script[nonce]') || {}).nonce || '';
// …in the submit handler, after the JSON arrives:
if (json.event && json.event.snippet) {
    var s = document.createElement('script');
    s.nonce = NONCE;
    s.text = json.event.snippet;
    document.body.appendChild(s);
}
```

**The nonce and the edge cache.** With `CACHE_TTL` above 0 a page nobody
wrote a session for is cached at the edge (see
[Sessions and edge caching](#sessions-and-edge-caching)), header and body
together: nothing breaks, but every visitor gets the same nonce until the
copy expires, so a script injected into the page could reuse it — the nonce
must be unique per response. **Don't turn on `strict` and `CACHE_TTL` together.**
A site that needs both has its edge replace the nonce on every response (a
Cloudflare Worker, for example); that's hosting configuration, not the core's.

## Do / Don't

**Do**

- Edit `site.json` to add fields, types, taxonomies.
- Use `e()` everywhere except trusted rich-text HTML.
- Create new template files and bind them in `site.json`.
- Use the helpers — they hide details and stay stable.
- When adding a custom item template, also register it in
  `item_types.{type}.templates` so the admin can pick it.
- Run `schema:validate` after editing `site.json`.

**Don't**

- Don't bind templates to slugs. Slugs are content; templates are code.
- Don't add hooks/filters or plugin systems. Extensibility goes through
  `theme/functions.php` (load-time only) and explicit helpers.
- Don't introduce a `content` or `thumbnail` "default field." Declare them as
  custom fields if needed.
- Don't write SQL with string interpolation. Use prepared statements.
- Don't bypass the FieldRenderer when creating custom field UIs without
  understanding how `collect()` will read them back.
- Don't put PHP files inside `theme/` and expect them to be web-accessible.
  Only static files are served via `/theme/...`.
- Don't write a visitor-facing sentence in engine code. Return a code and let
  the theme phrase it — see
  [Messages](#messages-the-engine-returns-codes-the-theme-writes-the-words).
  The one exception is a form's `success_message`, which is theme copy from
  `site.json`, not engine copy.

## Common tasks

### "Add a new section to the home page"

1. Open `theme/site.json`. Find `pages.home.fields`. Add the new field:
   ```json
   {"name": "testimonial", "type": "richtext", "label": "Depoimento"}
   ```
2. The admin form regenerates automatically. No code change needed in admin.
3. Edit `theme/templates/page-home.php` to render the value:
   ```php
   <?= field('testimonial') /* trusted */ ?>
   ```

### "Add a new item type 'service'"

1. In `site.json`, under `item_types`, add:
   ```json
   "service": {
     "label": "Serviços",
     "label_singular": "Serviço",
     "slug": "servicos",
     "template": "single-service.php",
     "fields": [
       {"name": "icon", "type": "text", "label": "Ícone"},
       {"name": "description", "type": "richtext", "label": "Descrição"}
     ]
   }
   ```
2. Create `theme/templates/single-service.php`.
3. Run `./bin/velk schema:validate`.
4. The sidebar in admin now shows "Serviços" automatically.

### "Add a custom template for a specific item"

1. Create the template file, e.g., `theme/templates/single-post-destaque.php`.
2. Register it under the item type's `templates` (a MAP, keyed by the
   template's identifier — not a list):
   ```json
   "templates": {
     "destaque": {"label": "Destaque", "file": "single-post-destaque.php"}
   }
   ```
3. In the admin item editor, a "Template" dropdown appears, letting the
   editor pick "Destaque" for that specific item.

### "Add a reusable field group to multiple types"

1. Define the group under `field_groups.contato`.
2. Reference it inside any item type / page fields with `{"group": "contato"}`.
3. The fields are inlined in the admin form.

(SEO specifically is already built-in — see "Built-in SEO fields" above —
so you don't need a field group for it.)

## Media library

The library lives at `/admin/media`. It supports:

- Upload (drop files or click) — POST `/admin/media/upload`, JSON response.
  Accepted types, with a size limit per category (`MediaService::maxSizeMap()`):
  images (jpeg, png, gif, webp, svg) and documents (pdf, docx, xlsx, pptx)
  up to 25 MB, audio (mp3, m4a, ogg, wav) up to 50 MB, zip up to 100 MB,
  video (mp4, webm, mov) up to 200 MB. PHP's `upload_max_filesize` and
  `post_max_size` still cap every upload.
- Detail panel: alt text edit, copy URL, delete.
- Picker mode (`/admin/media?picker=1`) — embed-layout view used inside an
  iframe modal opened by image fields. Selecting an item calls
  `window.parent.__velkPickerCallback(data)` which the image field registers
  before opening the modal.
- Search, on the library and in the picker — `?q=` matches the file name (the
  original one the grid shows, and the one stored on disk) and the alt text
  of the default language, word by word: every word (up to 5) has to appear
  in one of those fields, in any order. `%` and `_` are literal. It runs on the server
  (`Media::listFiltered()` / `countFiltered()`), so it reaches the whole
  library, not just the open page, and combines with the picker's `accept`
  filter and with the pagination. `admin.js` re-runs it as the user types
  (250 ms after the last key) and swaps only `[data-media-results]`; the box
  is a plain GET form, so Enter works without JavaScript. Alt translations
  are not searched.

### Storage layout

```
storage/uploads/
├── {filename}.{ext}              original, always served for 'full'
└── _variants/
    └── {variant-name}/
        └── {filename}.{ext}      thumb + declared per-field variants
```

Image variants (`thumb`, generated at upload; per-field variants
declared in `site.json`, generated at content save) live under
`_variants/<name>/`, one directory per variant name, mirroring the
original filenames. Deleting a media deletes its variant files too. See
"Image variants" in Helpers for the full schema and `image_url()` for
resolution.

Filenames use a slugified version of the original name (`Foo Bar.JPG` →
`foo-bar.jpg`). Collisions get `-1`, `-2`, … suffix.

## Options pages

Site-wide global fields — like contact info, footer texts, social links —
declared in `theme/site.json → options`. Each entry becomes a sidebar link
in the admin and a fields-only edit form (no slugs, no URLs, no list view).
Inspired by ACF Options Pages.

### Schema

```json
"options": {
  "contato": {
    "label": "Contato",
    "description": "Informações de contato exibidas no rodapé.",
    "fields": [
      { "name": "address", "type": "textarea", "label": "Endereço" },
      { "name": "phone",   "type": "text",     "label": "Telefone" },
      { "name": "email",   "type": "email",    "label": "Email" },
      { "name": "social",  "type": "repeater", "label": "Redes sociais", "fields": [
        { "name": "network", "type": "text", "label": "Rede" },
        { "name": "url",     "type": "url",  "label": "URL" }
      ]}
    ]
  }
}
```

Field types are the same set used by item types and pages (text, textarea,
richtext, image, select, boolean, repeater, etc.). Field groups (`{"group": "contato"}`)
work too.

### Reading values in templates

```php
<?= e(option('contato.email')) ?>
<?= e(option('contato.phone')) ?>

<?php foreach ((option('contato.social') ?? []) as $social): ?>
    <a href="<?= e($social['url']) ?>"><?= e($social['network']) ?></a>
<?php endforeach; ?>

<?php $footer = options('rodape'); ?>
<?= e($footer['tagline'] ?? '') ?>
```

- `option($path, $default = null)` — dot path access. First segment is the
  options page key, rest navigates the JSON. Works with nested fields,
  groups, and repeater rows: `option('contato.social.0.url')`.
- `options($key)` — full values array for one options page, in the active
  language: each field falls back to the default language when it has no
  translation, so `options('contato')['email']` and `option('contato.email')`
  always agree. The merge is shallow (a translated repeater replaces the
  default one as a whole), as in `fieldsIn()`.

### Storage

One row in the `settings` table per options page (`setting_key = "options.{key}"`,
`value` = JSON of all fields). Reads are cached in-process, so calling
`option()` 50 times in a template runs one query.

## Forms

Forms are declared in `theme/site.json → forms` and rendered manually in
templates. The connection is the form ID.

### Schema

```json
"forms": {
  "contato": {
    "label": "Contato",
    "subject": "Novo contato pelo site: {{name}}",
    "success_message": "Mensagem enviada — em breve entramos em contato.",
    "fields": [
      { "name": "name",    "type": "text",     "label": "Nome",     "required": true },
      { "name": "email",   "type": "email",    "label": "Email",    "required": true },
      { "name": "phone",   "type": "tel",      "label": "Telefone" },
      { "name": "message", "type": "textarea", "label": "Mensagem", "required": true }
    ]
  }
}
```

Field types: `text`, `email`, `tel`, `url`, `number`, `textarea`, `checkbox`,
`hidden`, `select`, `file`. Each field also accepts `required` and `maxlength`
(`file` doesn't use `maxlength` — see below).

The `subject` template supports `{{field_name}}` placeholders that get
substituted with the submitted values.

### `preview_field` and `preview_subtitle_field` — what labels a submission in the admin list

The submissions list at `/admin/forms/{id}` shows one collapsed card per
entry. `preview_field` names which field supplies that label:

```json
"forms": {
  "contato": {
    "preview_field": "email",
    "preview_subtitle_field": "empresa",
    "fields": [ ... ]
  }
}
```

Omit it and the label falls back to **the first non-empty text value**. That
default works by accident of ordering — submissions are stored in field
declaration order, so "the first filled text field" is usually the name.
Which is exactly why the key exists: nobody building a form could guess that
rule, and reordering fields for layout reasons silently changed the list.

The named field still falls back when it holds nothing usable — an optional
field left blank, or a `file` field (those store a metadata array, not a
string). A worse label beats an empty card. A field name that doesn't exist
in the form is a **config error**, not a runtime one: `schema:validate`
rejects it and lists the valid names, because at runtime it would just fall
back and look like the setting was ignored.

Labels are trimmed and cut to 60 characters (by character, not byte).

`preview_subtitle_field` names a second field for the card's second line,
before the date: `ACME · 22/08/2026 13:19`. Unlike the label it has **no
fallback**. Left out, or blank on a given submission, the line is just the
date, because falling back to "the first text" would repeat the title under
itself. `schema:validate` checks it like `preview_field`. The card no longer
shows the IP; it's still in the export's metadata columns.

### File fields

```json
{ "name": "curriculo", "type": "file", "label": "Currículo",
  "accept": ["pdf", "docx"], "max_size": 5, "required": true }
```

- `accept`: list of allowed extensions, a subset of the built-in whitelist
  (`pdf`, `jpg`, `png`, `webp`, `gif`, `docx`, `xlsx`, `pptx`, `zip` — no
  video, no audio; this is deliberately narrower than the media library's
  whitelist because the upload is anonymous). Omitted = the whole whitelist.
- **`svg` requires explicit opt-in**: it is only accepted when the field
  declares `"accept": ["svg", ...]`. SVG is executable XML and the uploader
  here is anonymous, so it is never accepted by default — a form that didn't
  ask for it can't receive one by accident. When opted in, the file is
  sanitized (same sanitizer as the media library) and, like every form
  upload, only ever served as an authenticated `attachment` download.
- `max_size`: megabytes. Omitted = 10 MB. This is the field's own ceiling —
  the real max is still whichever is smaller between this and the server's
  `post_max_size`/`upload_max_filesize`.
- **v1 supports one file per field** — no multiple selection.
- MIME is detected from the real file bytes (magic bytes + finfo), never
  trusted from the browser-supplied type. SVGs are sanitized the same way
  the media library sanitizes them (scripts/handlers/`javascript:` stripped)
  and rejected outright if they don't parse. And when served back through
  `/uploads/`, an SVG carries `Content-Security-Policy: sandbox` — the net
  under the sanitizer, since sanitizing SVG is a race against new vectors.
  Scripts are blocked and the document gets an opaque origin, so a vector
  that slipped through could not reach cookies or the site's DOM. `<img>`
  usage is unaffected.

**Files uploaded through a `file` field do NOT go into the media library.**
No `Media` row is created, they never show up in `/admin/media`, and
`file_url()`/`media()` don't resolve them. They're stored separately at
`storage/form-uploads/YYYY/MM/<random-token>.<ext>` — private by
construction (same `.htaccess`/nginx deny-list as the rest of `storage/`) —
and only reachable by an authenticated admin via the download link shown in
`/admin/forms/{id}`.

On a successful upload, `submission.data[<field>]` holds a metadata array
instead of a string — same convention as the flexible field's `_layout`
marker:

```php
['_file' => true, 'path' => '2026/07/ab12…cd.pdf', 'name' => 'curriculo.pdf',
 'size' => 123456, 'mime' => 'application/pdf']
```

`path` is an internal, private, opaque reference — never build a public URL
from it. If a field isn't `required` and the visitor sends no file, the key
is simply absent from `data` (no empty-string placeholder, since a file
input has nothing meaningful to prefill on a validation error either).

### Multi-step forms

`forms.<id>.steps` partitions the declared fields into steps. Each step is
saved as it is submitted: the first creates the lead, the rest update **the
same** lead until the last one closes it.

```json
"orcamento": {
  "label": "Orçamento",
  "steps": [
    ["name", "email", "consent"],
    ["cep", "address", "city"],
    ["budget", "message"]
  ],
  "step_webhook": "sync_partial_lead",
  "fields": [ … every field, exactly as before … ]
}
```

`steps` lists **field names**, not field definitions: `fields` stays the single
source of type, validation and `preview_field`, and the stored `data` stays a
flat `{field: value}` map with no notion of a step. The presence of `steps`
**is** the "this form allows steps" switch; there is no separate flag.
`schema:validate` requires an exact partition: every name must exist in
`fields`, and every field must appear in exactly one step — and it rejects
`step_webhook` declared on a form with no `steps` at all.

**Run `./bin/velk schema:validate` after every edit to `steps`.** It is the
only gate on the exact-partition rule, it does not run on its own, and a
malformed declaration costs more than one rejected submission: at runtime
`steps` that does not parse degrades **silently** to "this form has no steps"
— a stray string, a step that is not a list, an empty step, all of them. New
visitors then post the whole form at once against a template still rendering
a wizard, and leads already in progress stay in the panel only because the
completion filter falls back to "any in-progress row exists" (see "In the
panel" below). The command turns all of that into one legible error.

**Nothing keeps the template honest about that partition.** There is no theme
helper for it — `form_url()`, `form_status()`, `form_honeypot()` and
`form_captcha()` are the entire helper surface for forms, and the
`Config::formStepFields()` method that reads `steps` back is internal to the
engine. So the markup that decides which inputs render on which step is the
theme author's own, copied by hand from the same partition `site.json` already
declares — and `schema:validate` cannot see that copy, because it isn't part
of the schema. On the server, `submit()` narrows `fields` down to the current
step's names before validating, and `validate()` builds `$values` by walking
only that narrowed list: **any field the template posted on the wrong step is
silently absent from `$values` — not an error, not a warning, just gone.** A
field that belongs to the current step but is missing from the markup is the
other half: it is still walked, so it stores a blank, or fails `required` for a
value the visitor never got asked for. Move a field from step 2 to step 1 in `site.json`
and forget to move its `<input>` in the template, and one of two things
happens: an optional field just stops being collected, or a field that is now
required on an earlier step fails there every time, for a value the visitor
already typed on the later step the stale template still shows it on.
Reorganising `steps` is genuinely two lines of `site.json` — no stored lead
changes, no migration — but only for what the server stores; the template has
to move with it, by hand, or the two silently disagree about where a field
lives.

**That is what produces `incomplete_lead`, and the theme has to handle it
going backwards.** Before closing a lead the engine re-checks every required
field against the *accumulated* data, not just the step that arrived; the
names that are still missing come back in `errors`. Two things get a form
there: reorganising `steps` while a lead is already in progress (it was
collected under the old partition and the new one asks for more), and a
required field that ended up in **no** step at all — `schema:validate` catches
the second, and only if you run it. The trap is that this arrives on the
**final** step, and the fields it names are not on that screen. Redrawing the
final step with the errors attached shows the visitor nothing she can act on;
the theme has to send her **back** to the step that owns those field names. A
stepped form that ignores this code strands her on the last step, refused
every time she presses submit, with no visible reason — and each refused
attempt with a `file` field on that step uploads and discards the file again.
If the same lead can also be closed by another tab, `form_completed` is the
answer she gets instead; that one means the lead really is done.

**Steps require AJAX.** An intermediate step submitted as a plain POST is
refused with `ajax_required`, because in redirect mode the theme has no way to
receive the token for the next step. This is the one Velk form feature that
does not work without JavaScript — a wizard is an interactive widget, and it is
opt-in per form.

**The protocol.** Two plumbing fields alongside `_csrf` and `_hp`: `_step`
(1-based) and `_step_token` (absent on step 1). A successful intermediate step
answers:

```js
{ status: "success", code: "step_saved",
  step: 1, next_step: 2, total_steps: 3, token: "…" }
```

Send that `token` back as `_step_token` on the next step. The final step
answers exactly what a single-step form always answered — `submitted`, the
`message` from `site.json`, and `event` — so nothing downstream changes.

**`_step` is required on every step that carries a token**, and leaving it out
is `invalid_step`, not step 1. Without a token, a missing `_step` still means
step 1 — that is the only step that can start a lead. With one, guessing was
worse than refusing: a POST carrying step 2's fields but no `_step`, against a
lead sitting at step 2, used to merge into **step 1** — the engine validated
step 1's fields against step 2's body, so a required field on step 1 produced
`validation_failed` naming fields the visitor had already filled in, and a
fully optional step 1 had its real answers overwritten with blanks.

**A token only works in the session that issued it.** `resolveStep()` checks
this before it even looks for a matching lead, on every branch that carries a
token — not only when the lead can't be found. A made-up token and someone
else's leaked token fail exactly the same way, with `invalid_token`: the
engine doesn't distinguish "never existed" from "not yours." The scope is the
session, not the tab — several tabs of the same browser session each get their
own token — but a different browser, device, or logged-out/incognito session
never will. Don't hand the token somewhere it could cross that boundary
(logged server-side, pasted into a support ticket).

**That "several tabs" promise has a ceiling.** A session remembers only the
last `STEP_TOKENS_MAX = 10` tokens per form; past that, issuing a new one
silently evicts the oldest. The 11th concurrent tab of the *same* form in the
*same* session pushes tab 1's token out — tab 1's next submit gets
`invalid_token` even though its lead is still sitting open in the database,
`pending_step` and all. It takes eleven concurrent in-progress tabs of one
form in one session to get there; accepted as the cheaper problem than a
session that grows without bound.

**A session that dies mid-flow strands the lead the same way.** CSRF is
checked before any step logic runs, so a lost or expired session on step 2
answers `session_expired` — nothing step-aware, the same code a single-step
form would give. The visitor has no way to know their step-1 data is still a
row in `form_submissions` with a `pending_step` that nothing will ever advance
again; only `cleanup:incomplete-submissions` (below) or a manual delete in the
panel reaches it.

**What happens when.**

| | when |
|---|---|
| row written | **every** step |
| `params` (UTM, click ids), `ip`, `user_agent`, `referer` | first step only, never overwritten — the attribution is the first touch |
| `step_webhook` | **every** step, final one included |
| `webhook` | final step only; `$values` is still just that step's fields — see below |
| e-mail to recipients | final step only |
| `velk_form_success` | final step only |
| honeypot, Turnstile | **only while the lead's row doesn't exist yet** |

Honeypot and Turnstile run only on the request that **creates** the row —
`resolveStep()`'s `action === 'create'`, which is not the same test as "step
number is 1" even though the two usually coincide. Going back to fix step 1 on
a lead that already reached step 2 resolves as a `merge`, same as any other
back-edit, and is deliberately **not** re-filtered (see "Going back a step"
below) — re-running the antispam there would mean discarding a real edit to a
real lead, not screening a fresh submission. Once the row exists it may hold a
genuine lead, and the honeypot has known false positives (Chrome autofill,
password managers); at that point the only options would be marking a valid
lead as spam or silently dropping the visitor's correction, and both destroy
good data because of the visitor's browser, not anything they did. A bot that
reaches step 2 already passed both filters and holds an unguessable token —
all it can do from there is pollute its own lead. Turnstile has a second,
independent reason: its token is valid for a single verification, so it could
not run on every step even if that were desirable.

**`step_webhook` takes a handler the same shape as [`webhook`](#webhook)
below** — string for one function, list for several, called in order, same
signature:

```php
function sync_partial_lead(string $formId, array $values, array $context): void
```

**Both webhook keys get four extra `$context` keys**, appended after the six
that already existed (`submission_id`, `ip`, `user_agent`, `referer`,
`params`, `created_at` — see "Webhook" below) — `step`, `total_steps`,
`completed` and `data`, the accumulated lead. Purely additive: a handler
written before this feature existed doesn't notice.

That split matters for `$values` too, and it's easy to get backwards.
`step_webhook`'s `$values` — on every step, the final one included — is only
the fields that JUST arrived; `$context['data']` is where the rest of the lead
lives. **`webhook` follows the same rule on the final step**: its `$values` is
still only that last step's fields, not the whole lead, even though it fires
at the same moment the visitor "finishes" the form. The notification e-mail
and the conversion event's `user_data` are different on purpose — both get the
**whole merged lead**, because the site owner reading the e-mail, and Google
matching a conversion against an email or phone that may have been typed on
step 1, both need the full picture, not whichever fragment arrived last. A
`webhook` handler that assumes it receives the same shape as the e-mail will
work on a single-step form and quietly drop data on a stepped one.

`completed` is not derivable from `step === total_steps` — it's
`resolveStep()`'s decision, not an equality check. Proof: shorten `steps` from
3 to 2 while a lead sits pending at step 3, then have the visitor resubmit
step 2 — it arrives as `step: 2`, `total_steps: 2` (equal!), and still
completes nothing, because that lead's own pointer is still 3. On a form
without `steps` the four keys are always `1`, `1`, `true` and `$values`.

**On the final step, `webhook` and `step_webhook` fire together**, with
identical `$context` and identical `$values`. A handler hung on both keys in
`site.json` runs twice for that submission. A `step_webhook` handler that
wants only the intermediate steps should check `$context['completed']` rather
than assume it is never called on the last one.

**A finished lead is closed.** Any step arriving after the final one is refused
with `form_completed`, and the token dies with the lead. The e-mail already
went out and the conversion already fired; letting a late step rewrite the data
would leave the panel and the CRM disagreeing with what was e-mailed. Two final
steps that genuinely overlap — a double-click on a slow connection — resolve
the same way: closing the lead is `UPDATE … WHERE id = ? AND pending_step IS
NOT NULL`, so the database picks a winner and the loser gets `form_completed`
without a second e-mail, conversion or `webhook`.

**Marking a lead spam kills its token, and only its token.** The admin panel's
`markSpam()` clears `step_token` in the same update that sets `blocked_by`, so
a visitor still mid-flow who posts the next step gets `form_completed`,
indistinguishable from a lead that finished normally. `pending_step` is kept
deliberately: it is the only record of how far the lead got, **Not spam** has
no way to invent it back, and a recovered false positive that came back
labelled "finished" would count in the badge, show no step marker, and sit
outside `cleanup:incomplete-submissions` forever despite never having produced
an e-mail or a conversion. So a blocked row can be incomplete — the spam view
asks for every completion state rather than assuming otherwise, and
`cleanup:incomplete-submissions` skips blocked rows, leaving them to
`cleanup:spam-submissions`, the one command that honours `keep_spam` retention.

**Going back a step** is allowed: re-submitting an earlier step overwrites
those fields and does not move the pointer. The server does not hand the
accumulated values back — in AJAX they are still on screen. `step_webhook`
still fires on this resubmission — its gate is "this form has steps", not
"this step is new" — so a CRM sync handler runs again with the corrected
fields. A `step_webhook` handler needs to tolerate being called more than
once for the same lead; this is the case that does it. If the step carries a
`file` field and the visitor picks a **different** file, the previous upload is
deleted from `storage/form-uploads/` as the merge lands — leaving it would
strand a CV or a photo ID that no row references and no cleanup command can
reach. Re-submitting the step **without** choosing a file again keeps the
stored one: an empty file input is absent from the merge, not an empty value.

**In the panel**, a lead in progress is marked `step 2 of 4` — the translated
`forms.step_of` string, shipped in en/es/pt. The submissions
list gains a completion filter (finished only, by default). It appears for
forms declaring `steps` **or** for any form that still holds an in-progress
row — the second half matters, and for the same reason the **Spam** link
keeps showing old spam after `keep_spam` is switched off. `steps` that stops
parsing degrades to "no steps" silently (see `schema:validate` above), and
without that fallback the filter would vanish, a hand-written
`?completeness=all` would be forced back to the default, and every panel query
would carry `AND pending_step IS NULL` — hiding the name, e-mail and phone
already collected from people who never finished, and hiding them from the
delete button along with it. The filter is never offered in the spam view,
which reads every completion state instead. Both counts outside the list follow
the finished-only rule — the Forms list's own submission column and the sidebar
navigation badge count finished leads only, so neither promises a number the
list then fails to show.

**Personal data collected before consent.** If the consent checkbox lives on a
later step, step 1 has already stored a name, an e-mail and a phone number from
someone who never accepted anything. That is a decision for whoever builds the
form — put the checkbox on step 1 if it matters. And unfinished leads
accumulate with nothing to cap them: a `step_token` has no TTL, and nothing
ages it out on its own. `bin/velk cleanup:incomplete-submissions --days=30`
drops incomplete leads — and, with them, their tokens — and like its three
siblings **nothing runs on its own**: schedule it, or delete stragglers by
hand in the panel. A site that never schedules it keeps every abandoned
partial lead, personal data included, indefinitely.

### HTML template

Theme dev writes the markup. Use `form_url($id)` for the action and
`csrf_field()` + `form_honeypot()` + `form_captcha()` for spam protection.
`form_status($id)` returns the flash result of the previous submission
(success/error + field errors when validation fails). It reports **codes,
not sentences** — see [Messages: the engine returns codes](#messages-the-engine-returns-codes-the-theme-writes-the-words)
below. `theme_message()` / `theme_field_error()` in the examples are the
theme's own mapping functions, not engine helpers.

### Anti-bot: honeypot, and optionally Turnstile

`form_honeypot()` ships on every form and costs nothing: a hidden field bots
tend to fill. A caught bot gets a **fake success** on purpose — telling it
otherwise just teaches the author to work around the trap. Nothing is stored
and no conversion event fires.

Since v3.7.5 the helper also emits a small script that **empties the field in
the browser**, because the honeypot alone was rejecting real people: Chrome
ignores `autocomplete="off"` for profile filling and classifies a meaningless
field like `_hp` by its position in the form, and password managers write
straight into the DOM. Anyone running a real browser now passes; a bot posting
raw HTTP that filled the field still gets caught, which was the only case the
honeypot actually caught. The script needs nothing from the theme, carries no
styling, and is emitted once per request however many forms the page has.

**Render it away from the real fields** — after them, just before the submit
button, not as the form's first input. Chrome's positional heuristic is what
made a top-of-form honeypot get autofilled as if it were the name field.

The fake success goes through the same `successPayload()` as the real one, so
the `success_message` matches too — otherwise comparing the two responses
would reveal the trap. The absence of `event` still distinguishes them, and
that is accepted: matching it would mean firing a fake conversion into the
client's analytics to hide a trap from a bot.

#### Keeping what the anti-spam rejected

Both checks reject real people sometimes. A password manager or browser
autofill fills the hidden field; Turnstile fails on a blocked-JS browser or a
bad connection. Either way the lead used to vanish with nobody knowing there
had been a person on the other side.

Set `keep_spam` on a form and the rejected submission is stored instead of
discarded, flagged with `blocked_by` (`'honeypot'` or `'turnstile'`):

```json
"forms": {
  "contato": {
    "keep_spam": true,
    "fields": [ ... ]
  }
}
```

A **Spam** link then appears on that form's screen in the admin, next to the
submission count. Absent the flag, nothing is stored and no link appears —
exactly the behaviour from before the feature existed.

What does NOT happen for a rejected submission: no email, no conversion
event, no webhook. That is structural rather than a check — all three live
after `FormSubmission::create()` in the normal flow, and both anti-spam
branches return well before reaching it. The honeypot also keeps lying: the
response is unchanged, so the bot still cannot tell it was caught.

Uploaded files are never stored for a rejected submission, only the declared
filename. Keeping a file from someone the anti-spam rejected means letting a
bot write to the server's disk, which is what it wanted.

A blocked submission that turns out to be a real person gets a **Not spam**
button in its detail panel. It clears `blocked_by` and the submission joins
the normal list — no separate "recovered" list, because once you say it is not
spam it is a lead like any other.

Recovering does **not** fire the notification email or the webhook. They would
run weeks after the submission, and a lead reaching the CRM dated today with
three-week-old content confuses more than it helps. Whoever recovers it is
looking at it on screen; forwarding is their call.

There is deliberately no reverse (mark as spam): to get a submission out of
the list there is already delete, and a second path that merely hides it would
create two places to look for the same thing.

Two things to weigh before turning it on:

- **Volume.** A form under attack writes thousands of rows a day. Schedule
  `php bin/velk cleanup:spam-submissions --days=30` (it only touches
  `blocked_by IS NOT NULL`; approved leads are never deleted by age).
- **Data protection.** You are retaining personal data belonging to people
  the system classified as bots, without their knowledge. Under LGPD/GDPR
  the retention window is the point, not a disk-space nicety. Per-form and
  off by default for that reason.

`form_captcha()` adds Cloudflare Turnstile, and it turns on **purely by the
presence of both keys** in `.env` — the same way the store lights up when
`STRIPE_SECRET_KEY` exists:

```
TURNSTILE_SITE_KEY="0x4AAA..."
TURNSTILE_SECRET_KEY="0x4AAA..."
```

With no keys, `form_captcha()` returns an empty string, so a theme can print
it unconditionally and each site decides. No theme JavaScript is needed: the
Cloudflare script renders the widget and injects the token into a hidden
field itself, so a plain POST — or a `fetch()` with `FormData` — carries it
along. Widget mode (Managed / Non-interactive / Invisible) is chosen in the
Cloudflare dashboard, not here; and the domain does **not** need to use
Cloudflare DNS, only to be listed as a hostname on the widget.

Turnstile rather than reCAPTCHA for a reason that isn't taste: reCAPTCHA
ships data to Google, sets cookies, and Google uses it for its own purposes,
which makes every form a third-party disclosure and therefore an LGPD
consent question — and a captcha that only runs after a cookie banner is
accepted is a captcha that doesn't protect. Cloudflare states Turnstile
isn't used for advertising and doesn't rely on tracking cookies.

Two behaviours worth knowing, both deliberate:

- **A rejected captcha is an honest error**, unlike the honeypot's lie. Real
  humans fail sometimes (blocked JS, flaky network), and answering "sent!"
  to someone who didn't send loses the lead with nobody noticing.
- **Verification fails OPEN.** If Cloudflare can't be reached (timeout, DNS,
  outage), the submission goes through and a line lands in the error log.
  The honeypot still runs, and losing real leads during someone else's
  outage costs more than letting some spam through for a few minutes. A
  token that is missing or actually *rejected* is still turned away — that's
  an answer from the provider, not the absence of one.

> **A form with ANY `file` field needs `enctype="multipart/form-data"` on
> the `<form>` tag.** Without it, the browser silently sends the field as
> empty — no error, no console warning, the file simply never arrives. This
> is the single most common mistake when adding file uploads to a theme.

```php
<?php $r = form_status('contato'); ?>

<?php if ($r && $r['status'] === 'success'): ?>
    <p class="success"><?= e(theme_message($r, 'Enviado!')) ?></p>
<?php endif; ?>

<form action="<?= e(form_url('contato')) ?>" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <?= form_captcha() ?>

    <input type="text" name="name" value="<?= e($r['values']['name'] ?? '') ?>" required>
    <?php if (isset($r['errors']['name'])): ?>
        <small class="error"><?= e(theme_field_error($r['errors']['name'])) ?></small>
    <?php endif; ?>

    <input type="file" name="curriculo">
    <?php if (isset($r['errors']['curriculo'])): ?>
        <small class="error"><?= e($r['errors']['curriculo']) ?></small>
    <?php endif; ?>
    <!-- ... -->

    <?= form_honeypot() ?><!-- depois dos campos reais, nunca antes -->
    <button type="submit">Enviar</button>
</form>
```

`enctype="multipart/form-data"` is harmless to add even on forms with no
file field today — safe to always include if you want one convention for
every form template.

**Marking spam by hand** (v3.7.6). With `keep_spam` on, each submission in the
inbox gets a discreet **"É spam"** link next to Delete. It writes
`blocked_by = 'manual'` — a third origin alongside `honeypot` and `turnstile` —
and moves the row to the spam list, where the existing "Não é spam" button
brings it back.

The point is that deleting **destroys** the lead while marking preserves it,
and the spam list becomes the record of what the automatic filters let
through. Marking is reversible in one click, so it asks for no confirmation,
unlike Delete.

For a [stepped](#multi-step-forms) form, "preserves it" includes the step the
lead had reached. Marking clears the `step_token`, so a visitor mid-flow can no
longer push data into a blocked row, but leaves `pending_step` alone — **Not
spam** then hands the lead back still in progress rather than relabelling it
finished, which is a claim nothing could take back.

Two limits worth knowing. The link needs `keep_spam` actually **on** — it does
not appear merely because old spam exists (that case only keeps the "Spam"
link visible so already-collected personal data stays deletable). And an
already-blocked row is never overwritten: a `honeypot` catch stays `honeypot`,
because the origin is the thing the spam list exists to record.

The e-mail already went out and the conversion event already fired, at submit
time. Marking spam afterwards undoes neither, and is not meant to — those are
effects that already happened in the world.

### Submission lifecycle

1. POST `/forms/{id}` validates CSRF, honeypot, Turnstile, and field rules.
2. The submission is saved to `form_submissions` (DB column `data` is JSON).
3. The configured recipients (set in `/admin/forms/{id}`) receive an email
   with all submitted values formatted as a table.
4. The user is redirected back to the referrer; theme reads the result via
   `form_status($id)` to render success / error.

If email fails (no recipients, SMTP error), the submission is still saved —
the failure is recorded in `email_status` / `email_error` columns and visible
in the admin.

**This is the single-step path** — every form without `steps`, and whichever
request *closes* one that has them. Creating a stepped lead does not follow it:
step 1 writes the row and returns `step_saved` without emailing or
redirecting. An intermediate step is
shorter: CSRF still gates it, but honeypot and Turnstile already ran (only
the row's creation sees them), only that step's own fields get validated,
nothing is emailed, and it answers `step_saved` over JSON instead of a
redirect — plain-POSTing an intermediate step is refused outright
(`ajax_required`), since there is no page render left to carry the next token
back. See [Multi-step forms](#multi-step-forms).

### Attribution (tracking parameters)

`Velk\Params` captures where a visitor came from — UTM **and** ad click IDs,
one concept — from the query string of any GET request, and persists it to
the **session** so it survives navigation: land on the landing page, submit
the form two pages later, the attribution is still there.

Everything lands in a single `params JSON NULL` column on `form_submissions`
(NULL when nothing was captured), **no index**. It is never queried by
`WHERE` or `GROUP BY`: UTM is an input for analytics tools — whoever builds a
link with `utm_campaign` reads it in GA4 or Ads, not in a list of twenty
submissions — and a click ID is unique per click, so grouping by one means
nothing. The admin shows the parameters on each submission and offers no
filter for them.

Adding a parameter — a new ad platform, a `utm_id` — is one entry in
`Params::WHITELIST` and nothing else. No migration, because the storage is
JSON.

**The whitelist is closed and the loop walks IT, never the query string.**
Capturing an arbitrary parameter would be an unlimited write vector (anyone
can bloat the table through a URL) and an LGPD problem: URLs sometimes carry
PII by accident (`?cpf=...`), and that must never be persisted just because
it appeared in a query string. Arbitrary capture is structurally impossible,
not a check someone could forget. Each value is capped at 255 chars.

**Last-touch applies per group, not globally.** UTM answers "which campaign"
(written by marketing) and a click ID answers "which click" (generated by the
platform) — they arrive together or apart and have independent lifetimes. So
arriving with `?gclid=x` and no UTM replaces the click ID and *leaves the
UTM intact*; a single global last-touch would wipe the campaign and leave a
lead with a click ID and no origin. Within a group, replacement is total and
never merges: no "Monday's gclid plus Friday's fbclid", because double
attribution is wrong attribution.

Themes read the current set with `tracking_params()`, which returns only the
keys actually captured (`[]` on a direct visit).

The whole point of capturing a click ID is **offline conversion import**:
handing it, as-is, to whatever CRM or integration runs in
`forms.<id>.webhook` (below) so that tool can report back to the ad platform
("this specific click turned into a sale"), which is what lets Google
Ads/Meta/etc. optimize bidding for actual conversions instead of just
clicks. Velk only captures and hands off the ID — running the import itself
is always external to Velk.

### Webhook

`forms.<id>.webhook` calls a theme function AFTER the submission is saved
and emailed — string for one handler, list for several (called in order):

```json
"webhook": "send_to_crm"
"webhook": ["send_to_crm", "notify_slack"]
```

The handler lives in `theme/functions.php`:

```php
function send_to_crm(string $formId, array $values, array $context): void
```

`$values` is the validated submitted fields — of the current step only, on a
form declaring [`steps`](#multi-step-forms), even on the final step that
closes the lead; that section says where the merged lead goes instead.
`$context` = `submission_id`,
`ip`, `user_agent`, `referer`, `params` (associative array, whitelist keys
with a value only — see "Attribution" above; `[]` when none
were captured), `created_at`, and then `step`, `total_steps`, `completed`
and `data` (the accumulated lead), also detailed there. Those four are always
present — on a form without `steps` they are `1`, `1`, `true` and the same
array as `$values`, so don't use their presence to detect a stepped form. A missing function or a handler that throws is
caught and logged (`error_log`) — never breaks the submission.

A `file` field's entry in `$values` is the metadata array described in
"File fields" above (`_file`, `path`, `name`, `size`, `mime`), not a string —
a handler that forwards `$values` as-is to an external API (CRM, Slack) should
account for that shape. `path` is a private, internal reference; it is NOT a
URL and resolves to nothing outside the app.

### Admin

`/admin/forms` lists all forms with submission counts. `/admin/forms/{id}`
manages recipients (add/remove inline) and shows every submission with full
data, IP, timestamp, and email status. Tracking parameters (when captured)
are shown in the same expanded detail, in one section — no filter, see
"Attribution" above. A `file`
field's value renders as a download link (name + human-readable size) —
`GET /admin/forms/{id}/submissions/{sid}/file/{field}`, authenticated, 404
on anything that doesn't resolve exactly to that submission's stored file.
Deleting a submission also deletes its uploaded file(s) from disk.

An **Export** block downloads the approved, completed submissions as CSV —
`GET /admin/forms/{id}/export`, authenticated, the same file as
`./bin/velk form:export` (see "CLI commands"). The query string is the whole
choice, so the URL is a reusable saved export, and it survives a login
redirect: `cols[]=<field>` per form field, `cols[]=@meta` and
`cols[]=@params` for the metadata and tracking groups (no `cols` at all =
the form's fields, the same default the block's checkboxes start with — the
two groups come unchecked; an unknown column is dropped, so a saved link survives a
renamed field as long as one of its columns still exists — with none left it
is the no-columns error, not an empty file), `date_from`/`date_to`
(`YYYY-MM-DD`, an invalid one is ignored) and `sep=;` or `sep=,`. The
block's form always sends `p=1`, so submitting it with every column
unchecked is an error instead of a bare link that exports everything. Not
shown in the spam view.

### AJAX submission

Send `Accept: application/json` and the controller answers with a JSON body
instead of the flash-and-redirect flow. `200` on success, `422` on a real
failure, so a theme can branch on the status alone:

```js
const r = await fetch(form.action, {
  method: 'POST',
  headers: { 'Accept': 'application/json' },
  body: new FormData(form),          // multipart, so file fields work
}).then(r => r.json());
// { status, code, message?, errors?, values?, event? }
```

`errors` and `values` carry the same shape the session flash would have, for
redrawing client-side. Purely additive — a plain `<form>` POST sends no such
header and behaves exactly as before.

**A [stepped](#multi-step-forms) form sends two more fields and gets four
back.** Send
`_step` (1-based) and, from the second step on, `_step_token` — the `token`
the previous step returned.

`_step` is the theme's own markup, one hidden input per step's `<form>`, and
it is **not optional once a token is in play** — a POST that carries
`_step_token` without `_step` is refused with `invalid_step` rather than
guessed at as step 1:

```html
<input type="hidden" name="_step" value="2">
```

`_step_token` is the one the JS sets, because only the previous response knows
its value:

```js
const url = step1Form.action;        // same endpoint for every step

let r = await fetch(url, {
  method: 'POST',
  headers: { 'Accept': 'application/json' },
  body: new FormData(step1Form),     // _csrf, _hp, step 1's fields, `_step: "1"`
}).then(r => r.json());
// { status: "success", code: "step_saved", step: 1, next_step: 2, total_steps: 3, token: "…" }

const fd = new FormData(step2Form);  // _csrf, step 2's fields, and the
                                     // hidden `_step` input shown above
fd.set('_step_token', r.token);      // `_hp` is only checked when the row is created
r = await fetch(url, {
  method: 'POST',
  headers: { 'Accept': 'application/json' },
  body: fd,
}).then(r => r.json());
// intermediate step: same step_saved shape — and the SAME token, which
//                     lives until the lead closes; it never rotates
// final step:        { status: "success", code: "submitted", message?, event? }
```

**Don't call `fetch()` without that header.** `fetch` follows the 302, the
fetched page renders server-side and consumes the flash (`form_status()` does
get + forget), and the message — plus any conversion event — evaporates with
no error anywhere.

**Navigating to a thank-you page?** Send `_event=queue` with the POST. By
default the conversion event rides in the response body and the theme pushes
it — but pushing and navigating in the same instant races the unload, and a
custom GTM tag can lose the request silently. With that field the engine
**queues** the event instead and omits it from the body; the next page fires it
through `tracking_head()`, which every theme already calls. One or the other,
never both, so "exactly once" still holds. Works for any AJAX form, stepped or
not, and for member registration (`velk_signup_success`) too.

The queue is not indefinite: a queued entry expires after
`ClientEvents::TTL_SECONDS` (600 seconds, 10 minutes) and is silently dropped
past that, never fired. Ample for the POST→redirect bridge this exists for,
not a promise that survives a thank-you page the visitor is slow to reach.

## Messages: the engine returns codes, the theme writes the words

The core never picks the words a visitor reads. It reports **what happened** —
a code, plus whatever data only it knows — and the theme decides how to say it.

```php
// what the engine returns
['status' => 'error', 'code' => 'rate_limited', 'params' => ['minutes' => 15]]

// what the theme does with it (theme/functions.php)
'rate_limited' => 'Muitas tentativas. Tente de novo em {minutes} minuto(s).',
```

**Why it works this way.** The engine's own source strings were English, and
the only way to change them was the translation screen — which
`ThemeStringController` hides on single-language sites. A Portuguese site had
no way out. Worse, field errors and upload failures were
hardcoded Portuguese literals that never went through `t()` at all: invisible
to `i18n:scan`, invisible to the panel, and the most-read messages in the whole
system. Codes fix all of it: no English to translate, nothing hidden from the
scanner, and the wording lives entirely in the theme — where the editorial
decision belongs.

**Payload shape:**

```php
[
  'status' => 'success' | 'error' | 'info' | 'two_factor',
  'code'   => 'submitted',
  'params' => ['minutes' => 15],        // only when the sentence needs data
  'errors' => ['bio' => ['code' => 'max_length', 'params' => ['max' => 200]]],
  'values' => ['email' => 'x@y.com'],
]
```

`status` is for branching (box colour, `if (!res.ok)`); `code` is for choosing
the sentence. In JSON, `error` answers 422 and everything else 200.

**The one surviving sentence.** A form's `success_message` in `site.json` still
arrives as `message`. That is not the engine having an opinion — it is the
theme sending its own, written by whoever built the site, and it goes through
`t()` like any theme string. Absent, there is no text at all: the theme gets
`submitted` and decides.

Codes reach the theme through `form_status($id)`, `member_status()`,
`store_status()`, and the JSON body on AJAX submits.

**Mapping them.** Put the map in `theme/functions.php` (loaded at boot).
[`docs/messages.md`](docs/messages.md) has a copy-pasteable skeleton. A theme
need not cover every code, but always pass a generic fallback — without one an
unmapped code renders as `invalid_credentials` on the visitor's screen.

**The full vocabulary is in [`docs/messages.md`](docs/messages.md)** — every
code, its params, and when it fires. Read it before adding a message anywhere
in the core: a new visitor-facing sentence in engine code is a regression, and
a silent one, since nothing breaks when it happens.

## Sessions and edge caching

The session starts **on demand**, not on every request. It opens when the
request already carries the session cookie (there is something to read) or on
the first write. A page nobody reads from or writes to emits **no
`Set-Cookie`** — which is what makes it cacheable at the edge, since a CDN
won't store a response that sets a cookie, and rightly so: the cached copy
would carry one visitor's session id and hand it to everyone after.

`Response::send()` then decides `Cache-Control` from whether a session was
actually opened:

| Request | Header |
|---|---|
| Nothing touched the session | `public, max-age=0, s-maxage=CACHE_TTL` |
| Session opened (form, login, flash) | `private, no-store` |

Only GET/HEAD with status 200, and only when the response didn't set
`Cache-Control` itself. **`CACHE_TTL=0` (the default) disables the whole
thing** — no header is emitted and behaviour is exactly as before. A cached
page carries one CSP nonce for every visitor — see
[the nonce and the edge cache](#content-security-policy-public-site).

`s-maxage` rather than `max-age`: the edge caches, the browser revalidates. A
high `max-age` would leave visitors on stale HTML until their own cache
expired, which is the worst possible behaviour during an urgent content fix.

**A page with a form is never cached**, by design. `csrf_field()` writes the
token to the session, and a CSRF token is a per-visitor secret while a cached
page is identical for everyone — the two are incompatible by definition, not
by implementation. That's fine: the pages that take traffic spikes are the
home and content pages, not the contact form.

**The theme decides the outcome more than the engine does.** A `csrf_field()`
inside a global partial — a newsletter form in the footer, say — makes *every*
page write a session, and nothing caches, with no error anywhere. Set
`APP_DEBUG=true` and read the `X-Velk-Cache` header to see which it is and
why.

### Where session data lives

The session cookie always had a lifetime; the DATA used to
live in the host's folder under php.ini's `gc_maxlifetime`. On Debian/Ubuntu
PHP-FPM that folder is cleaned by the `sessionclean` cron job, which reads
php.ini (24 min) and ignores `ini_set` and the pool — and the folder is shared
by every site on the server. So `Session` now sets, before `session_start()`
(`Session::storageSettings()` / `applyStorageSettings()`):

- `session.gc_maxlifetime` = `session.lifetime` (the longer of the two
  login limits below, 30 days by default), always;
- with the **files** handler only, `session_save_path` = `SESSION_PATH`
  (default `storage/sessions`, created on demand with `0700` by the PHP that
  serves the site — the file names are the session ids, so the folder must not
  be listable by other users of a shared server), and PHP's own GC on
  (`gc_probability = 1`, `gc_divisor = 100`), because nothing else cleans it.

A folder that can't be created or written, `SESSION_PATH=` (empty), or a
php.ini handler that isn't `files` (Redis, Memcached) keep the host's
settings — never an error page. `storage/` is denied to the public by
`.htaccess.example` and `nginx.conf.example`, and it is not an engine path, so
an upgrade keeps the sessions. Don't ship `storage/sessions` in git or in a
tarball: a checkout would create it `0755`.

**More than one server.** The files handler is per server: behind a load
balancer, a request that lands on another node doesn't find the session.
Today the answer is php.ini — `session.save_handler = redis` (or memcached)
with a shared server, which Velk leaves untouched. A database driver (a
`SessionHandlerInterface` over a `sessions` table, as Drupal and Joomla do)
was evaluated and not built: it needs a migration (`id` VARCHAR(128) PK,
`data` MEDIUMBLOB, `last_activity` INT indexed, `user_id` nullable for a future
"log out everywhere"), a lock per session id against concurrent requests of
the same visitor (`GET_LOCK`, the pattern `AutoMigrator` already uses) and one
read plus one write per request that opens a session — cached anonymous pages
open none, so the cost falls only on forms and the panel. Worth building when
a client actually runs more than one server; until then it would be a write
on every admin request for no one's benefit.

### How long a login lasts

Counted from the **last use**, not from sign-in, and separately for the two
logins that share the one session (and the one cookie):

| | Default | `.env` (seconds) | What counts as use |
|---|---|---|---|
| Admin | 2 days | `ADMIN_SESSION_LIFETIME` | opening a screen of the panel — `Controller::__construct()` calls `Auth::touch()` |
| Member | 30 days | `MEMBER_SESSION_LIFETIME` | any page that reads the member's login — `MemberAuth::check()` / `member()` |

The last use is a timestamp in the session (`_admin_seen_at`,
`_member_seen_at`), rewritten at most once a minute (`Session::markActive()`)
so a busy screen doesn't write the session on every request. Past the limit,
`Session::idleExpired()` drops only that login (`user_id` or `member_id`); the
other one stays. For the admin, the panel then redirects to the login with
`error_code: session_idle` (`Auth::expiredThisRequest()`), worded by the login
screen. A session from before the stamp existed gets one on first use instead
of being dropped.

`Auth::check()` never renews the stamp: the public site and the draft preview ask whether
an admin is signed in, and that is not use of the panel. Don't call `touch()`
outside `Controller`.

**The cookie slides.** PHP sends the session cookie only when the id is new
(first visit, login, `regenerate()`); on an existing session it doesn't resend
it, so its expiry used to stay pinned to the sign-in time. `Session` resends
it right after `session_start()` whenever the request brought the same id the
session has (`Session::cookieNeedsRefresh()`), valid for `session.lifetime` —
the longer of the two limits. It only happens on requests that already open
the session, so anonymous cached pages are untouched.

No keepalive (a request every few minutes while a tab is open) and no
"remember me": the limits are long enough that neither earns its cost. The
old single `SESSION_LIFETIME` is no longer read.

## Tracking scripts & consent

Scripts live in their own screen, `/admin/scripts` — one row each, with a
name, a position (`head` / `body_start` / `body_end`), an on/off toggle, an
optional consent category, and a drag-to-reorder order. `tracking_head()`,
`tracking_body_start()` and `tracking_body_end()` emit them; themes call the
same three helpers they always did.

**Consent is off by default, per script.** A new script has no category and
always loads — the common case (GTM, a domain-verification tag, an error
monitor) needs no decision at all. Ticking *"this script requires consent"*
reveals a category select.

**Categories** are declared by the theme, and are what make the banner
readable to a visitor:

```json
"consent": {
  "enabled": true,
  "categories": {
    "analise":   { "label": "Análise",   "mode": "opt_out" },
    "marketing": { "label": "Marketing", "mode": "opt_in" }
  }
}
```

**`enabled` is the theme stating it has a banner**, and it is what makes the
whole consent area exist. Without it the admin shows no consent column and no
"requires consent" checkbox, and every script is treated as necessary. That
matters because Velk ships no banner: on a theme without one, offering the
option would invite marking a script that then never loads — nothing could
accept it, and nothing on screen would explain the silence.

`categories` is optional. `enabled` alone gives an all-or-nothing banner;
declaring categories is what lets it say "Marketing" instead of "Optional".
`schema:validate` rejects categories declared without `enabled`, since that
combination configures nothing.

| `mode` | Behaviour |
|---|---|
| `required` | Always loads; the cookie can't revoke it. |
| `opt_out` | Loads until the visitor refuses. |
| `opt_in` | Loads only after explicit acceptance. |

Without the block, consent still works — the select then offers two implicit
options (*optional, on by default* / *optional, off by default*) and approval
is all-or-nothing. Declaring categories is what lets the banner say
"Marketing" instead of "Optional".

**How the gate works.** `tracking_*()` reads the consent cookie and decides
per row:

- **no category** → emitted raw. Necessary, always.
- **granted category** → emitted raw, at its position. A returning visitor who
  already answered goes through no JavaScript at all.
- **not granted** → emitted inert, for the runtime to activate:
  `<script type="text/plain" data-velk-consent="marketing">BASE64</script>`

The payload is base64 because pasted HTML routinely contains `</script>` —
any snippet that writes another tag does — which would close the wrapper early
and spill the rest as loose markup.

A row pointing at a category the theme **no longer declares** emits nothing —
not raw, not inert. That's a renamed key in `site.json`, and "can't classify"
has to mean "doesn't load". The scripts table flags those rows in red.

**Theme API** (`window.velkConsent`, injected by `tracking_head()` only when
at least one enabled script actually has a category):

```js
velkConsent.categories()          // {marketing: {label, mode}, ...}
velkConsent.state()               // {marketing: false, analise: true}
velkConsent.answered()            // false → show the banner
velkConsent.set({marketing: true})
velkConsent.acceptAll() / rejectAll()
document.addEventListener('velkconsent', e => e.detail.state)
```

`set()` activates the inert scripts immediately — no reload. It cannot do the
reverse: a script that already executed has already set its cookies and sent
its data. Revoking applies from the next navigation on.

**Two properties worth knowing before debugging them:**

A gated `<noscript>` never renders. With JS enabled the browser doesn't parse
`<noscript>` contents; with JS disabled the activation runtime never runs.
That's the correct outcome rather than a gap — a visitor without JS can't be
shown a consent banner either. GTM's `<noscript>` iframe is dead weight in a
gated category.

Snippets of the same service must share a category. GTM's `head` script in
`marketing` and its `<noscript>` in a necessary row installs half a GTM.

Conversion events (below) are **not** gated: the site pushes to its own
`dataLayer`, and whatever listens to that push — GTM — is what sits behind
consent. Gating the push would hide conversions even from visitors who
accepted everything.

## Conversion events

Every **completed** form submission pushes `velk_form_success` to
`window.dataLayer`; a completed member registration pushes
`velk_signup_success`. Always on, no opt-in: a push nobody listens to is
inert, and a new site is trackable from day one.

With [`steps`](#multi-step-forms), "completed" is doing real work: a row is
written to `form_submissions` on every step, but only the step that closes
the lead builds and fires this event — an intermediate `step_saved` never
does, even though it saved real data. The event still fires exactly once, on
whichever request finishes the form, single-step or last-step alike.

Emitted from inside `tracking_head()`, which every theme already calls, so
existing installs get this with no theme change. Consumed one-shot — a refresh
can't fire it twice.

**The payload contract.** This is what makes a reusable GTM container
possible: build one, import it on every Velk site, swap only the conversion ID.

```js
window.velkFormSuccess = {
  form_id:       "orcamento",
  submission_id: 1234,
  params:        { utm_source: "google", gclid: "…", … },         // only captured keys
  user_data:     { email: "…", phone_number: "…" }                // only what's mapped
};
window.dataLayer = window.dataLayer || [];
window.dataLayer.push(Object.assign({ event: "velk_form_success" }, window.velkFormSuccess));
```

`velk_signup_success` has the same shape with `member_id` and `type` in place
of `form_id`/`submission_id`, under `window.velkSignupSuccess` — the variable
name is the event name in camelCase, so the rule holds for future producers.

**When it does NOT fire**, and these are guarantees, not best-effort:

| Case | Fires? |
|---|---|
| Submission/registration completed | yes, exactly once |
| Intermediate step of a multi-step form saved | **no** — the row is written, the event isn't; see above |
| Honeypot caught a bot | **no** — the branch returns before the enqueue |
| Validation error, CSRF failure, rate limit | **no** |
| Email or webhook dispatch failed | **yes** — a lead is a lead |
| Page refreshed afterwards | no, already consumed |

The event name is fixed and not configurable. The dataLayer event name and the
GA4/Ads conversion name are different things — in GTM you fire a
`generate_lead` tag *from* a `velk_form_success` trigger. Two forms needing
different conversions means two tags on the same trigger, keyed on `form_id`.

**Configuration** (`/admin/forms/{id}` per form; a section of *Scripts &
rastreamento* for registration — present only when `members.enabled` is true,
the same gate as the sidebar's "Membros", since with members off there is no
registration to convert):

- **Field mapping** — which field is the email, which is the phone. Nothing is
  mapped by default. Only these two exist because Google's enhanced conversions
  only consume named keys; anything else would be exposed to every script on
  the page and ignored by Google. Values go in plain text: the Google tag
  normalises and hashes them in the browser, and hashing server-side against
  the wrong normalisation silently matches nothing.
- **Snippet** — optional raw JS for sites without a tag manager. Runs alongside
  the push, never instead of it, in its own `<script>` block, and reads its
  data from `window.velkFormSuccess` (no placeholder syntax). The library
  itself (gtag, fbq) still comes from the tracking fields. **Not executed by
  the engine when the theme submits over AJAX** — the JSON body carries
  `event.snippet` as a string and the theme decides what to do with it (see
  [Content Security Policy](#content-security-policy-public-site) for
  running it under a strict policy). The
  exception is [`_event=queue`](#ajax-submission): then the engine queues the
  snippet together with the event instead of returning either in the body,
  and emits both itself — push first, snippet right after, in its own
  `<script>` — at the next page's `tracking_head()`, same as redirect mode.

## Payment webhook

A single global hook, same pattern as the forms webhook above, that fires
when a Stripe payment event is CONFIRMED — an order transitions to paid/
failed/expired, or a subscription sync is applied. Declared as a top-level
`site.json` key, string or list:

```json
"payment_webhook": "studioweb_payment_event"
"payment_webhook": ["deliver_ebook", "notify_crm"]
```

Handlers live in `theme/functions.php`. One signature for every event — the
handler FILTERS on `$event`:

```php
function studioweb_payment_event(string $event, array $data, array $context): void
```

**Orders** — `order.paid`, `order.failed`, `order.expired`. Fires only on a
real status TRANSITION: a Stripe retry, a byte-identical replay, or a second
event confirming the same outcome (e.g. `checkout.session.completed` then
`checkout.session.async_payment_succeeded`, both landing on `paid`) does NOT
re-fire.

- `$data = ['order' => ['id', 'member_id' (nullable, guest checkout), 'email', 'status', 'currency', 'total_amount', 'stripe_session_id', 'stripe_payment_intent', 'created_at'], 'items' => [['item_id', 'item_type', 'title', 'unit_amount', 'quantity'], ...]]`
- `$context = ['stripe_event', 'event_ts', 'session_id', 'payment_intent']`

**Subscriptions** — `subscription.<status>`, status verbatim from Stripe
(`active`, `canceled`, `past_due`, `trialing`, `unpaid`, `incomplete`…).
Fires when the sync is APPLIED and (the status changed OR the event
timestamp strictly advanced) — renewals (same status, later period end) DO
fire; filter on `$data['previous_status']` to tell "became active" from
"renewed" apart. A tie replay (same status, same timestamp) does NOT re-fire.
A same-status re-fire isn't necessarily a billing renewal, though — a
`cancel_at_period_end` toggle or a `checkout.session.completed` landing
after `customer.subscription.created` also re-fires with `previous_status`
equal to `status`; handlers that need TRUE renewals should compare
`current_period_end` across events instead of relying on the status alone.

- `$data = ['member_id', 'plan_key', 'status', 'previous_status', 'billing_interval', 'current_period_end', 'cancel_at_period_end']`
- `$context = ['stripe_event', 'event_ts', 'stripe_subscription_id', 'stripe_customer_id']`

Handlers run SYNCHRONOUSLY inside the Stripe webhook request — keep them
fast (Stripe times out slow endpoints); queue/defer heavy work yourself. A
missing function or a handler that throws is caught and logged
(`[Velk payment_webhook] ...`) — never breaks webhook processing, same
best-effort contract as the forms webhook. `schema:validate` errors when
`payment_webhook` is present but isn't a non-empty string or a non-empty
list of non-empty strings.

Example — deliver a digital product by email when an order is paid:

```php
function studioweb_payment_event(string $event, array $data, array $context): void
{
    if ($event !== 'order.paid') {
        return;
    }
    foreach ($data['items'] as $item) {
        if ($item['item_type'] === 'ebook') {
            \Velk\Mailer::fromEnv()->send(
                $data['order']['email'],
                'Seu ebook está pronto',
                '<p>Obrigado pela compra! Baixe seu ebook: https://example.com/download/...</p>'
            );
        }
    }
}
```

`\Velk\Mailer::fromEnv()->send($to, $subject, $htmlBody, $textBody = null)`
is the engine's own mail primitive (SMTP when `SMTP_*` env vars are set,
`mail()` fallback otherwise) — the same class password-reset and form
notification emails use. `core/` is autoloaded by namespace, so
`theme/functions.php` can call `Velk\*` classes directly by their
fully-qualified name. Note that `\Velk\Mailer` is an internal engine
class — wholesale-replaced on `velk:upgrade`, with no compatibility
contract across versions — so while it works today and is the engine's
own mail path, a stabilized `mail()` theme helper may formalize this
later.

## Site members

Members are end-user accounts for the public site — separate from
admin-panel `users`. Two distinct realms with their own tables, auth
classes, session keys, password-reset tokens, and rate-limit tables.
There are NO predefined roles like WordPress; each site defines its
own `types` in `site.json` and the theme decides what each type can
access.

### Enabling

Add a `members` block to `site.json`:

```json
"members": {
  "enabled": true,
  "public_registration": false,
  "fields": [
    { "name": "phone", "type": "text", "label": "Telefone" }
  ],
  "types": {
    "lead": {
      "label": "Lead",
      "fields": [
        { "name": "company", "type": "text", "label": "Empresa" }
      ]
    },
    "client": {
      "label": "Cliente",
      "fields": [
        { "name": "cnpj", "type": "text", "label": "CNPJ" }
      ]
    }
  },
  "routes": {
    "login": "/entrar",
    "register": "/cadastro"
  }
}
```

- `enabled: false` (or absent): the entire feature is invisible
- `public_registration: false`: only admins can create accounts (default)
- `types` is optional — when omitted, members are a single flat list
- `fields` are global (apply to all members); each type can add its own
- `routes` overrides the default `/member/*` paths (one or more keys)

Default route paths if `routes` is omitted: `/member/login`,
`/member/logout`, `/member/register`, `/member/password-reset`,
`/member/password-new`.

### Theme integration

The theme implements its own login/register/password-reset forms.
Velk just exposes POST endpoints that the forms target via `action=`.
All POSTs require `<?= csrf_field() ?>`, same as public forms.

Theme helpers (in `helpers.php`):

```php
member_enabled()                     // is the members feature on?
member_check()                       // is someone logged in?
member()                             // returns Member|null
member_field('phone')                // current member's custom field
member_is('client')                  // type check
member_route('login')                // resolves to /entrar (or the default)
member_require()                     // gate the page; redirects to login
member_require_type('client')        // gate by type; redirects to /
member_status()                      // read-and-clear last flash message
```

Example login form in a theme template:

```php
<form method="post" action="<?= e(member_route('login')) ?>">
  <?= csrf_field() ?>
  <?php $s = member_status(); ?>
  <?php if ($s !== null): ?>
    <p class="<?= $s['status'] === 'error' ? 'error' : 'ok' ?>"><?= e(theme_message($s)) ?></p>
  <?php endif; ?>
  <input type="email"    name="email"    required>
  <input type="password" name="password" required>
  <button type="submit">Entrar</button>
</form>
```

Restricting a page:

```php
<?php member_require(); ?>             <!-- redirects to login if not auth'd -->
<?php member_require_type('client'); ?> <!-- 'client' type only -->
```

### Admin panel

When members are enabled, a "Membros" link appears in the admin
sidebar. Both admins and editors can create, edit, and delete
members — it's content management, not user management of the
admin panel itself.

### Why separate from `users`

Different domains, different threat models, different security
requirements. Mixing them (WordPress-style) creates problems:

- A spray attack against member emails could lock out an admin's IP
- Password-reset tokens from one realm could theoretically be used
  against the other if both are stored in the same table
- Admin role evolution (admin/editor) is independent of site role
  evolution (lead/client/whatever the site needs)

Separation makes each realm's security state independent and the
data model cleaner.

### Subscriptions and the store — setting Stripe up

Two Stripe integrations share one secret key and use **two separate webhook
endpoints**, each with its own signing secret:

| Feature | Declared by | Webhook endpoint | Signing secret |
|---|---|---|---|
| Member subscriptions | `members.plans` in `site.json` | `POST /member/stripe-webhook` | `STRIPE_WEBHOOK_SECRET` |
| Store (one-off sales) | `item_types.<type>.sellable: true` | `POST /store/stripe-webhook` | `STRIPE_STORE_WEBHOOK_SECRET` |

```
STRIPE_SECRET_KEY="sk_test_..."           # one key for both
STRIPE_WEBHOOK_SECRET="whsec_..."         # subscriptions endpoint
STRIPE_STORE_WEBHOOK_SECRET="whsec_..."   # store endpoint
```

**A feature only turns on when its webhook secret is set too.** With the
secret key alone the checkout would open and Stripe would charge — but every
webhook would arrive unsigned, be rejected with 400, and the member would pay
without ever getting access (or the order would sit in `pending` forever).
Half-configured has to look *off*, not broken. `schema:validate` reports the
gap by name when `site.json` declares plans or sellable types and the matching
secret is missing.

In the Stripe dashboard, create one endpoint per feature and subscribe it to:

- subscriptions: `checkout.session.completed`, `customer.subscription.created`,
  `customer.subscription.updated`, `customer.subscription.deleted`
- store: `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
  `checkout.session.async_payment_failed`, `checkout.session.expired`

Prices and the active flag are **not** in `site.json`. The engine builds an
options screen (`/admin/options/subscriptions`) from the declared plans, and
that is where the price per interval, the active toggle and the currency live.
`site.json` declares identity only: `name`, `intervals` (`month`/`year`),
`features`, `description`. Products and prices are created in Stripe on first
use and cached; changing a price in the panel creates a new Stripe price and
leaves existing subscriptions on the old one, which is what you want.

That screen has no language tabs, even on a multilingual site: a price, the
active toggle and the currency have no translation, and the engine reads only
the default-language values. Its labels follow the admin's language (the
`subscriptions.*` keys of the admin catalog).

**Two currencies, set in two places.** Subscriptions use the **Currency**
field of that screen (blank = `BRL`). The store uses `store.currency` in
`site.json` (a 3-letter ISO code, default `BRL`), and every sellable item's
`price` is read in it:

```json
"store": { "currency": "USD" }
```

They are independent: a site that sells in dollars and charges subscriptions
in dollars sets both.

Things that follow from the design and are worth knowing before the first
client asks:

- **How many subscriptions a member can hold is a `site.json` decision.**
  By default one: a second `subscribe` from a member who already has one
  (status `active`, `trialing` or `past_due`) answers `already_subscribed`
  and does not touch Stripe; changing plan is done in the Stripe Customer
  Portal (`/member/subscription/portal`). With `members.multiple_plans: true`
  a member can hold several — **one per plan** (`basic` + `pro` yes, `pro` +
  `pro` no), and `already_subscribed` then means "already on *this* plan".
  With `multiple_plans`, configure the Stripe Customer Portal **without plan
  switching**: a switch there changes the price of an existing subscription,
  which is exactly the `pro` + `pro` the form guard cannot see — the engine
  records it, logs it and flags it in the members list, but does not undo it.
  Theme side: `member_subscriptions()` lists the active ones (most recent
  first), `member_subscribed_plans()` their plan definitions, and
  `member_can_subscribe($key)` is the guard's answer, so the button can be
  hidden before the click. `member_on_plan()` and `member_require_plan()`
  pass when *any* active subscription matches; `member_subscription()` and
  `member_plan()` keep returning the most recent one.
- **`repeat_purchase` decides whether an item can be bought twice.** Default
  yes. With `item_types.<type>.repeat_purchase: false`, a logged-in member
  who already has the item in a paid order is not charged for it again — the
  line is dropped from the cart, and if nothing is left the checkout answers
  `already_purchased` — and quantity is always 1 for those types. Guests are
  not tracked: the rule is per member, so require login in the theme if it
  matters. `item_purchasable($item)` is the "show the buy button?" helper.
- **The guard runs at the form; the record is written by the webhook.** Two
  tabs that both finish checkout before the first webhook arrives both pass.
  The engine never cancels or refunds on its own: the webhook writes the row,
  logs a line starting with `[Velk] ATENÇÃO:` (again on every subscription
  event for that member until the anomaly is resolved in Stripe), and the
  members list shows every active subscription with a red `!` when they exceed
  the rule. A duplicate paid order simply shows up as two orders. Resolve in
  the Stripe dashboard.
- **The webhook is the only source of truth.** The success page just flashes
  `subscription_paid`; it grants nothing. The visitor usually lands on it a
  few seconds *before* the webhook arrives, so `member_subscription()` can be
  `null` for a moment. A theme that reads it on the success page should say
  "we're confirming your payment" rather than "not subscribed".
- **`past_due` still has access.** Stripe keeps that status while it retries
  a failed card; cutting access on the first failed charge is harsher than
  most sites want. `canceled` and `unpaid` do not.
- A member cancelling "at period end" keeps `status = active` until the
  period actually ends — Stripe flips it to `canceled` then, and the engine
  follows. No date math in the engine.
- If webhooks stop arriving (endpoint removed, secret rotated), the engine's
  state freezes at the last event: access does not expire on its own.
  Stripe's dashboard shows failed deliveries; there is no panel indicator yet.

Use Stripe's **test mode** keys during development; the flows are identical
and the dashboard lets you replay events. Reactions to a confirmed payment
(e-mail, CRM, unlocking content) go through the `payment_webhook` hook —
see the section above.

## Internationalization (i18n) — Phase 1

The i18n system has three phases. Phase 1 ships the translation
runtime for hardcoded strings (theme + engine). Phases 2 and 3 will
add URL prefix routing and per-language content fields.

### Phase 1 status: SHIPPED

What's working:
- Source strings wrapped in `t()` get translated at render time
- Admin manages additional languages at `/admin/languages` (default
  comes from `site.json → i18n.default` — not editable in admin)
- Admin translates strings at `/admin/translations` with inline edit
- CLI scan: `./bin/velk i18n:scan` finds `t()` calls in theme+core
  and registers them in the catalog
- `?lang=<code>` query param previews translations until Phase 2
  adds proper `/en/`, `/es/` URL prefixes

### Enabling

```json
"i18n": {
  "enabled": true,
  "default": "pt-BR",
  "default_label": "Português"
}
```

Then in `/admin/languages` add the extra languages with their
url_prefix (used in Phase 2) and labels.

**`default_label` is required whenever `enabled` is true**, and
`schema:validate` enforces it. It's the display name of the default language
— what shows in the site's language switcher and on the admin's language
tabs. It lives here rather than in the panel because the default language is
the only one with no row in `languages`: the admin creates *additional*
languages and refuses a code equal to the default. Every language has exactly
one owner for its name — the default's is the theme, the others' is the panel.

Pick whatever reads right for the site: `Português`, `Brasil`, `PT`. A
single-language site (`enabled: false`) never renders a language label and
doesn't need the key.

### Theme usage

```php
echo t('Read more');
echo t('Hello, {name}!', ['name' => 'World']);
echo t('Post', context: 'noun');   // disambiguate from verb
```

**Write the string in whatever language the theme is written in.** `t()` looks
it up in the catalog (read once per request, then cached in memory) and falls
back to the source string when there is no translation — which is exactly what
the site's default language always gets, because the panel refuses to register
the default as a translatable language (`/admin/languages` manages the ones
declared *on top of* the default). So a Portuguese theme on a Portuguese site
needs no translation at all: you register `en`, fill that column, and the
Portuguese visitor keeps reading the literal in the template.

There is deliberately **no shortcut keyed on a language**. One existed until
v3.7.8: it returned the source without consulting the catalog when the
visitor's language matched a constant hardcoded to `en` — which made a
Portuguese theme show *Portuguese* to an English visitor, with the registered
translation never consulted and no way to fix it from the panel.

Helpers:

```php
lang()                  // current language code, e.g., "en"
lang_is('en')           // bool
default_language()      // from site.json
available_languages()   // list of all enabled languages
i18n_enabled()          // bool
```

### What the engine itself puts through `t()`

Almost nothing, since v3.7.1: visitor-facing engine messages are **codes**
now, and the theme writes the words — see [Messages: the engine returns
codes](#messages-the-engine-returns-codes-the-theme-writes-the-words) above.

What remains is `success_message` from `site.json → forms.<id>.success_message`
— wrapped so the panel can translate it without touching site.json — and three
default button labels in `core/helpers.php` (`member_subscribe_button()`,
`member_portal_button()`, `store_checkout_button()`), each overridable by
passing your own `$label`, which is what most themes do.

### What's NOT in Phase 1

- ~~URL prefix routing~~ (added in Phase 2 — see below)
- Per-language content fields (item title in en/pt, etc.). Item
  fields are still single-language. Phase 3 will add this.
- Hardcoded strings in templates that aren't wrapped with `t()`
  are not translated — wrap them first.
- Auto-detection of language from `Accept-Language` header.
- Language switcher in the theme — write one yourself using
  `available_languages()`.

## i18n Phase 2 — URL routing + config translations

Phase 2 adds proper language-aware URLs. `?lang=en` still works as
a fallback when calling `url()` without a request prefix.

### URL structure

```
Default language:  /sobre, /blog/post-x, /categoria/tag-x
en (with prefix):  /en/about, /en/news/post-x, /en/category/tag-x
```

Two layers of translation behind those URLs:

1. **Page slugs** — stored in `pages.slug_translations` JSON column.
   `pages.sobre.slug_translations.en = "about"` makes `/en/about`
   resolve to the JSON page `sobre`. Edit via the page editor in
   Phase 3 (Phase 2 just adds the column + routing; admin UI inline
   in the page editor follows in Phase 3).

2. **Item type / taxonomy prefixes** — stored in
   `config_translations` table. The slug `blog` (from
   `item_types.post.slug` in site.json) can be translated to `news`
   in en, `noticias` in es. Edit via the "Traduções" button at
   `/admin/items/{type}` and `/admin/taxonomies/{taxonomy}`.

### One URL per language

In a non-default language, a page, item or term answers at its
**translated slug** — or, when it has no translated slug in that
language (missing or blank), at its **default-language slug**:

```
no English slug:   /sobre (pt)  and  /en/sobre (en)
English "about":   /sobre (pt)  and  /en/about (en) — /en/sobre is a 404
```

Each level of a hierarchical path follows the rule on its own
(`/en/services/consultoria` when only the parent is translated), the
same way the type/taxonomy prefix already fell back to the
site.json `slug`. Each record keeps a single URL per language, so
there's no duplicate content: once translated, the default slug stops
answering in that language.

When two records land on the same URL — one's translated slug is
another's (untranslated) default slug — **the translated one wins**, and
the other has **no URL in that language**.
In a hierarchy that's decided level by level from the root: the
translated record takes the level, and its children come first below
it; a child that only exists under the record that lost is still
reachable at its own URL. A draft never takes a published item's URL:
between items, the published one wins first, and the translated-slug
rule decides between two of the same status.

The record that lost is left out where it matters: `the_hreflangs()`
and the sitemap skip that language for it, and `lang_url()` (the
language switcher) sends the visitor to the language's home.
`$item->url()` still builds the path from its slug — it doesn't run the
router's query, so it stays cheap in listings — and that path opens the
winner; `$item->ownsUrlIn('en')` (also on `Term`, and
`Page::ownsUrlIn($key, $config, 'en')`) says whether the record is the
one that answers there. The admin warns on save when a translated slug
collides with another record's slug in a language, naming who lost.

`url()` / `$item->url()`, `lang_url()` (the language switcher,
`the_hreflangs()`, `the_canonical()`) and the sitemap's alternates all
build these same URLs. Until v3.8.x the rule was strict: without a
translated slug the content had no URL in that language at all
(`url()` returned `''`, the switcher pointed at `/`, the sitemap
skipped it).

The home URL `/` always resolves to the home page regardless of
language, since "/" has no meaningful translation.

### Theme helpers (Phase 2 additions)

```php
url('/sobre')              // /sobre in pt, /en/sobre in en — only adds the
                           // language prefix; it doesn't translate the slug
url('/sobre', 'en')        // /en/sobre — explicit language
$page->url()               // the page's URL in the active language, with its
                           // translated slug: /en/about (Page::findByKey('sobre'))
lang_url('en')             // URL of the CURRENT page in English
lang_switcher()            // [['code', 'label', 'url', 'is_current'], …]
the_html_lang()            // for <html lang="...">
the_hreflangs()            // <link rel="alternate" hreflang="…" href="…">
the_canonical()            // absolute URL of current page in current language
```

Typical theme `<head>`:

```php
<html lang="<?= e(the_html_lang()) ?>">
<head>
  <link rel="canonical" href="<?= e(the_canonical()) ?>">
  <?= the_hreflangs() ?>
</head>
```

### What Phase 2 does NOT do yet

- ~~Item slug translation~~ (added in Phase 3 — see below)
- ~~Title and other content field translations~~ (Phase 3)
- ~~Page editor UI for `slug_translations`~~ (Phase 3 partial)

## i18n Phase 3 — content translation (titles, slugs, fields)

Per-language content for items, pages, terms, and options. The
shape is `column + column_translations` everywhere: defaults in the
existing columns (zero-impact on existing code), translations in
parallel JSON columns.

### Schema additions (migration 2026_05_11_000006)

```
items:    title_translations, slug_translations, fields_translations
pages:    title_translations, fields_translations
          (slug_translations already added in Phase 2)
terms:    name_translations, slug_translations, fields_translations
settings: value_translations  (for option groups)
```

### Model API

Each translatable model exposes lookup helpers with default fallback:

```php
$item->titleIn('en');       // translated title or fallback to $item->title
$item->slugIn('en');         // translated slug or fallback to $item->slug
$item->hasSlugIn('en');      // bool — true when the item has its own English slug
$item->fieldIn('content', 'en');  // single field with fallback
$item->fieldsIn('en');       // entire fields array merged with default

// Terms: same but with nameIn instead of titleIn
$term->nameIn('en');
$term->slugIn('en');
$term->fieldIn('description', 'en');

// Pages: titleIn + fieldIn + fieldsIn (slugTranslationFor for slug)
$page->titleIn('en');
```

"No translation" is a missing key, `null`, `''`, or — for a richtext — the
HTML of an editor with nothing written (`<p></p>`: the admin's editor never
sends an empty string). A richtext with only an image, a table, a rule
(`<hr>`) or a theme block is a translation. The admin applies the same rule
when saving, so a blank translation tab stores nothing.

Theme code typically calls the i18n-aware helpers in helpers.php
(`field()`, `the_title()`) which auto-resolve to the active
language — themes don't need to call `titleIn()` directly.

### Routing

FrontRouter now matches against translated slugs:

```
/blog/<pt-slug>           → item by pt slug
/en/news/<en-slug>        → same item, looked up by slug_translations.en
/en/news/<pt-slug>        → the item, when it has no English slug;
                            404 when it has one
```

See "One URL per language" above: without a slug translation in
language X, the item answers there at its default slug; once
translated, only the translated slug does.

### Admin UI

Items editor (`/admin/items/{type}/{id}`) has language tabs at the
top of the form. Default tab uses the existing inputs (title, slug,
fields[…]). Each additional language tab renders the same fields
with the input names prefixed for translation storage:

```
title              → items.title (default)
title_translations[en]   → items.title_translations.en
slug_translations[en]    → items.slug_translations.en
fields_translations[en][content] → items.fields_translations.en.content
```

A green dot on a language tab indicates the item has content in
that language. Empty translation inputs are silently dropped so
the fallback chain stays clean.

Pages and Terms get the same treatment in a follow-up (UI only —
the schema, model API, and routing already work for them).

### What Phase 3 does NOT do yet

- ~~Page editor UI for translations~~ (added in Phase 3b)
- ~~Term editor UI for translations~~ (added in Phase 3b)
- ~~Options editor UI for value_translations~~ (added in Phase 3b)
- ~~Sitemap <xhtml:link rel="alternate">~~ (added in Phase 3b)
- Item slug regeneration on title change in non-default language —
  currently slug must be set manually for non-default languages
- Auto-translation suggestions / LLM integration

## i18n Phase 3b — admin UI completion + sitemap alternates

Phase 3b finishes the admin UI surface so the entire i18n feature is
usable without touching the database directly.

### What Phase 3b adds

**Editor UIs with language tabs** (same pattern as Phase 3a items):

- **Pages** (both JSON-defined and custom) — `/admin/pages/{key}`
  has language tabs at the top. For JSON pages, the default tab
  shows title/url as read-only (they come from site.json) and only
  the custom fields are editable. Translation tabs let you set
  title/slug/fields per language.
- **Terms** — `/admin/taxonomies/{taxonomy}/{id}` gets name + slug
  translation tabs.
- **Options** — `/admin/options/{key}` gets per-language field
  panels. Translations stored in `settings.value_translations`.

**Sitemap with hreflang alternates** — `/sitemap.xml` now emits
`<xhtml:link rel="alternate" hreflang="…" href="…">` tags for every
URL when i18n is enabled. Includes `hreflang="x-default"` pointing at
the default-language version. Every language gets an alternate — the
translated slug, or the default one where there's none — the same URL
the router answers at, so Google never sees a broken URL. A record that
lost a slug conflict in a language gets no alternate there. Paths are
built in memory, once per item type and taxonomy, so the number of
queries doesn't grow with the number of items.

**i18n-aware `option()` and `options()` helpers** — `option()` reads
from `Option::getPathIn($path, $lang)` when on a non-default language,
with field-level fallback to the default; `options()` reads the whole
group from `Option::getGroupIn($key, $lang)` by the same rule (since
v3.9.1 — before it always returned the default language). Theme code
calling `option('footer.tagline')` or `options('footer')` automatically
gets the translation when the active language has one, without any code
change.

### Pattern summary

For all four editor surfaces (items, pages, terms, options), the
form structure is:

1. Default-language tab: existing inputs (`name="title"`, `name="slug"`,
   `name="fields[X]"`). Backward compatible — saves to the original
   columns.
2. Each additional-language tab: same fields rendered with prefixed
   input names (`title_translations[en]`, `slug_translations[en]`,
   `fields_translations[en][X]`). Default-language value shown as
   placeholder/hint so the translator has context.
3. JS swaps panel visibility on tab click; single form submit covers
   all languages at once.
4. Empty translation inputs are dropped on save, keeping the JSON
   tidy and the fallback chain predictable.

### What Phase 3 (a+b) does NOT do yet

- Item slug auto-generation from translated title — slug must be
  set manually for non-default languages (or via SQL)
- Auto-translation suggestions / LLM integration
- Cron to clean orphan translations (rare, low value)
- Email templates per language (members.password_reset email
  currently uses single-language hardcoded HTML — improvement for
  future when a real client demands it)

## Admin-panel i18n (`__()`) vs. content i18n (`t()`)

Everything above (`t()`, Phases 1-3b) is content i18n — theme strings and
item/page/term/option values, translated at render time via the DB catalog.
The admin panel UI itself (buttons, labels, validation messages) is
translated separately by **`__()`**, a file-based catalog at
`core/Admin/lang/{en,pt,es}.php` — not editable from the UI, linted by
`./bin/velk i18n:scan-admin`. A variant, `__raw()`, skips HTML-escaping
params for plain-text sinks (e.g. an email Subject header). Don't confuse
the two systems: a string translated with `t()` never shows up for `__()`
and vice versa.

**`ADMIN_LOCALE`** (`.env`, default `en`) sets the default admin-panel
locale — used on pre-login screens and for any user with no personal
`locale` preference. Fresh installs are English-first; existing
Portuguese installs should set `ADMIN_LOCALE=pt` on upgrade. It's also the
fallback language for 2FA one-time-code emails and password-reset emails
when the recipient has no locale of their own, and the language
member-facing (site-visitor) auth emails render in.

## Future / stubs

These are designed-but-not-built; do not pretend they exist:

- i18n: email templates per language (members + admin reset emails
  currently use a single hardcoded language)
- i18n: auto-slug from translated title in the item/term editors
- Static page cache + invalidation
- Multi-site
- Field types: `relationship`, `color`
- Migrations CLI scaffolder (`make:migration`)
- Member email verification (schema-ready: `email_verified_at`
  column exists, but no UI/flow yet)
- Social login (Google/GitHub/etc.) for members

When asked to add any of the above, treat as a real feature design task.

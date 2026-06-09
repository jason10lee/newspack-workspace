# Comments Panel Block

A Gutenberg block that pairs a trigger button with a sliding panel containing the post's comments. The panel supports inline pagination, inline comment submission, and auto-open behaviors.

## Overview

The Comments Panel is a three-block system: a parent container (`newspack/comments-panel`) that holds a Comments Button (`newspack/comments-panel-trigger`) and a Comments Panel Content (`newspack/comments-panel-content`). The parent is `templateLock="all"` — both child blocks are always present and cannot be removed or reordered. The block is only available in block themes.

**Single-panel design**: Unlike overlay-menu, comments are page-level, so only one panel is output per page. The panel has a fixed id (`newspack-comments-panel`) and a fixed body class (`comments-panel-open`). There is no per-instance `instanceId` or block context — all trigger buttons control the same panel via the shared fixed id. The PHP content block uses a `static $rendered` guard to output the panel only once; subsequent instances return an empty string. The editor mirrors this with a first-instance check using `getBlocksByName`.

## Block attributes

### `newspack/comments-panel` (parent)

No configurable attributes beyond standard block supports (`anchor`, `spacing.margin`).

### `newspack/comments-panel-trigger` (Comments Button)

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `triggerText` | string | `"Comments"` | Label text on the trigger button. Always present in the DOM; may be visually hidden depending on the active block style. |

**Block styles:**

| Style | Description |
|-------|-------------|
| Default | Comments icon + label text |
| Icon only | Icon only; label hidden with `screen-reader-text` |
| Text only | Label only; no icon |

The icon uses the `comments` entry from `Newspack_UI_Icons` on the frontend (PHP render) and the `postComments` export from `@wordpress/icons` in the editor.

**Block supports:** `color.text`, `color.background`, `typography.fontSize`, `spacing.padding`, `border.radius`.

### `newspack/comments-panel-content` (Comments Panel Content)

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `overlayColor` | string | `""` | Color of the overlay backdrop. Supports RGBA for transparency. Read by `view.js` from a `data-overlay-color` attribute on the panel element. This is the only configurable setting, mirroring the Overlay Search block. |

The panel is a fixed right-side drawer sized from `--wp--style--global--content-size` (680px on the block theme) plus horizontal padding — matching the theme's `.overlay-contents` base, which is what the original comments overlay used. It has an opaque default background/text (`base`/`contrast`). Slide direction, panel width, full-screen, and panel background/text colors are intentionally not exposed for now to keep the block simple; they can be added later (the `--left` / `--right` CSS modifiers are retained for that).

**InnerBlocks:** restricted to `['core/comments']`. On first insert the block is pre-populated with the default comments template (comments form, title, comment list with avatar/author/date/content/reply, and pagination) mirroring the theme's `comments-contents.html`. `templateLock` is `false` so the template contents are editable.

## Editor behavior

**Preview toggle**: All three blocks include a `PanelPreviewToggle` button (shared component at `panel-preview-toggle.js`). Activating it opens the panel in the editor for design purposes without navigating to the frontend.

Preview open/close state is managed as local React state inside `content/edit.js` and coordinated across blocks via `preview-refs.js`, a module-level pub/sub system keyed by the parent block's `clientId`:

- `panelToggles` (exported Map) — the content block registers a toggle function here so sibling/parent blocks can call it without a shared reactive store or block attributes.
- `subscribers` (module-private Map) — open-state listeners. External code interacts with it only through the exported `subscribeToPanel()` (to register a React state setter) and `notifySubscribers()` (to broadcast state changes). Multiple blocks can subscribe to the same panel.

The preview state is entirely ephemeral and is never persisted to block attributes or saved markup.

**First-instance dedup**: When a second Comments Panel block is inserted, the content block detects it is not the first instance (via `getBlocksByName`) and renders an info notice placeholder instead of the panel preview. This matches the PHP `static $rendered` guard.

**Trigger edit**: `triggerText` is edited inline via a plain-text `RichText` field inside the button. The icon is not configurable from the editor.

**Overlay color**: The panel's sidebar exposes a single `ColorGradientSettingsDropdown` "Overlay" control (the scrim backdrop color), supporting the theme palette and custom colors including alpha — mirroring the Overlay Search block.

## Rendering

All three blocks are dynamic — each has a PHP render callback.

**Parent block** (`class-comments-panel-block.php`): Renders the outer wrapper. Calls `Newspack::load_common_assets()` to ensure the webpack split chunk (`dist/commons.js`) is enqueued.

**Trigger block** (`class-comments-panel-trigger-block.php`): `save: () => null` — no static markup is saved. The PHP callback renders the button with `aria-expanded="false"`, `aria-controls="newspack-comments-panel"`. Icon visibility and label class follow the active block style (`is-style-icon-only`, `is-style-text-only`). The comments icon is rendered via `Newspack_UI_Icons::print_svg( 'comments' )`.

**Content block** (`class-comments-panel-content-block.php`): `save()` persists a minimal wrapper `<div>` containing the `InnerBlocks` content. The PHP callback builds the panel wrapper with ARIA attributes (`role="dialog"`, `aria-modal="true"`, `aria-hidden="true"`, `inert="true"`, `aria-label="Comments"`), a fixed `id="newspack-comments-panel"`, and a `data-overlay-color` attribute used by `view.js` at runtime. A `static $rendered` guard ensures only the first instance per page request outputs the panel HTML.

**Frontend script** (`view.js`, webpack entry `comments-panel-block`): Divided into two parts.

*Part A* — open/close shell: locates the single panel by `id="newspack-comments-panel"` and all trigger buttons by `.comments-panel__trigger`. Moves the panel to `document.body` once (to avoid stacking context issues). On open: slides the panel in, sets ARIA states (`aria-expanded`, `aria-hidden`, `inert`), adds `comments-panel-open` to `<body>`, creates a scrim from `data-overlay-color`, activates a focus trap, and listens for Escape. Focus returns to the last focused element on close.

*Part B* — comment behaviors (ported from the block theme's `comments.js`): intercepts pagination link clicks to load the next/previous page inline via `fetch` and swap the `.wp-block-comments` element without a full reload; intercepts comment form submission to keep the panel open after posting; surfaces a user-friendly notice on 429 rate-limit responses; auto-opens the panel when the URL contains `?cpage=`, `/comment-page-N/`, or a `#comment-N` hash.

**CSS note**: Panel and overlay styles are written at root scope (not nested inside the block wrapper selector) because `view.js` moves the panel to `document.body` on open and loses its ancestor context.

## Availability

Block theme only. Registration in `includes/class-blocks.php` is conditional on `wp_is_block_theme()`.

> **Future consideration: restrict to Site Editor** — Because the Comments Panel is designed for template parts (e.g., a post single template), it could be restricted to the Site Editor only by adding `"allowedBlocks": []` or an editor context check. This is deferred until usage patterns are established.

## Usage with block theme templates

The block is typically placed in a post single template or a reusable template part. Multiple trigger buttons can be placed by inserting the block multiple times — each adds a new trigger button but all control the same single panel.

> **Assumption: one comment form per page.** The panel provides the post's comment form, so the template must **not** also render the post's comments/comment form inline elsewhere. WordPress's `comment-reply.js` finds the form by ID (`#respond` / `#commentform`), so a second comment form on the page produces duplicate IDs and breaks the per-comment "Reply" link (it targets the wrong form). This isn't guarded against in code — the panel is meant to be the single home for comments, and rendering them twice is a misconfiguration.

```html
<!-- wp:newspack/comments-panel -->
<div class="wp-block-newspack-comments-panel">
  <!-- wp:newspack/comments-panel-trigger {"triggerText":"Comments"} /-->
  <!-- wp:newspack/comments-panel-content -->
  <div class="wp-block-newspack-comments-panel-content">
    <!-- wp:comments {"className":"wp-block-comments-query-loop"} -->
    ...
    <!-- /wp:comments -->
  </div>
  <!-- /wp:newspack/comments-panel-content -->
</div>
<!-- /wp:newspack/comments-panel -->
```

## Related

- [Comments Panel block source](.) — Parent block, `view.js` frontend controller, `panel-preview-toggle.js` shared component, and `preview-refs.js` pub/sub module.
- [Trigger block source](./trigger/) — Button sub-block with style variations and PHP renderer.
- [Content block source](./content/) — Panel sub-block with the overlay color control and PHP renderer.
- [`includes/class-blocks.php`](../../../includes/class-blocks.php) — Conditional block registration (block theme check).
- [Overlay Menu block](../overlay-menu/) — The architectural model for this block trio.

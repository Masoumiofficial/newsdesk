# Marketplace images

Two marketplaces, two sets of requirements. Nothing here ships inside the
plugin zip.

## CodeCanyon (this folder)

| File | Where it goes |
|---|---|
| `codecanyon-thumbnail-80x80.png` | Item thumbnail |
| `codecanyon-preview-590x300.png` | Item preview / inline preview |
| `codecanyon-preview-1180x600@2x.png` | Retina source for the above |

CodeCanyon also requires at least one screenshot and a self-contained
documentation file. The documentation already ships at
`newsdesk-ai/documentation/index.html`. For screenshots see below.

## wordpress.org (`../.wordpress-org/`)

| File | Notes |
|---|---|
| `banner-1544x500.png` | Listing header, retina |
| `banner-772x250.png` | Listing header, standard |
| `icon-256x256.png` | Plugin icon, retina |
| `icon-128x128.png` | Plugin icon, standard |

wordpress.org serves these from the SVN `assets/` directory, **not** from the
plugin zip, which is why they are excluded by `.distignore`.

## Screenshots

There is no headless browser in the build environment, so screenshots are not
generated automatically — and fabricating mock-ups would misrepresent the
product. Instead the real admin screens render to standalone HTML:

```bash
php tools/render-previews.php     # -> previews/*.html
```

Open each file in a browser at roughly 1440px wide and capture it. The
`readme.txt` screenshot captions map to these files:

| Caption | Render |
|---|---|
| 1. Dashboard | `previews/01-dashboard.html` |
| 2. News inbox | `previews/02-news-inbox.html` |
| 3. Draft review | `previews/05-draft-review.html` |
| 4. Sources | `previews/03-sources.html` |
| 5. Settings | `previews/09-settings.html` |

Save them as `screenshot-1.png` … `screenshot-5.png` in `../.wordpress-org/`.
The numbering must match the caption order in `readme.txt`; a gap makes every
later caption describe the wrong image. `tests/test-u5-commercial-readiness.php`
checks the numbering.

# De Lijsterij website — agentrichtlijnen

Korte context zodat eenvoudige opdrachten in één keer goed landen.

## Waar de site staat

- **Alles wat live hoort** zit onder **`site/`** (HTML, CSS, JS, afbeeldingen).
- Werk **direct in `site/`**; dupliceer geen parallelle kopie elders tenzij de gebruiker dat vraagt.

## Stylesheet-cache

- `site/assets/css/main.css` wordt met **`?v=YYYYMMDD`** ingeladen.
- Na **elke** wijziging in `main.css`: verhoog `?v=` op **alle** HTML-bestanden die `main.css` linken, of minstens op elke pagina die je aanpast (anders blijft oude CSS in de browser hangen).

## JavaScript

- `site/assets/js/main.js` — alleen op **`index.html`** geladen. Na wijziging: **`?v=`** op die script-tag zetten of verhogen.

## Shell (Windows)

- Paden met **apostrof** (bijv. `Foto's`) breken snel in **PowerShell-one-liners**. Gebruik een **`tools/*.mjs`**-script met `path.join(...)` of `Copy-Item -LiteralPath`.

## Patronen die al in de codebase zitten

- **Inner pages:** `body` heeft een pagina-class (bijv. `page-workshop`, `page-portfolio`), hero vaak `page-hero page-hero--compact`.
- **Workshop-hero tekst verticaal centreren:** `body.page-workshop .page-hero--compact .page-hero-copy` met `align-self: center; min-height: auto;` (zie `main.css`, sectie WORKSHOP).
- **Portfolio “Delicate werken”:** sectie `#delicate-werken`, beelden onder `site/assets/images/portfolio/delicate-werken/`.

## Contactformulier

- Alleen op **`site/index.html`** (`#contact`). Verzenden gebeurt via **`main.js`** (mailto met ingevulde velden); geen server nodig op statische hosting.

## Live bekijken

- Server of “Open with Live Server” moet **`site/`** als root hebben (of `index.html` uit `site/` openen), anders kloppen paden naar `assets/` niet.

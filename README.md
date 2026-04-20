# De Lijsterij website

Deze map is de werkbasis voor de nieuwe website van De Lijsterij.

## Leidende structuur

- `concept/`
  Hier staat het oorspronkelijke HTML-concept. Dit is de creatieve en inhoudelijke basis.
- `./` (repo-root)
  Hier staat de uiteindelijke website (HTML, `assets/`, `beheer/`, `favicon/`). **Dit is de bron:** alle bewerkingen en commits gebeuren in de root.
- `site-upload/`
  Kopie van de livebestanden uit de repo-root om handmatig te uploaden naar **YourHosting** (FTP / bestandsbeheer). Geen aparte “waarheid”.
- `current-site/`
  Hier bewaren we materiaal uit de huidige WordPress-site als referentie.
- `content/`
  CMS-content en contentnotities. `content/content.json` wordt live op server beheerd; `content/content.json.example` staat in Git als template.
- `docs/`
  Hier houden we planning, sitemap en migratienotities bij.

## Huidige status

- Het originele concept staat in `concept/original/`.
- De bestaande fotomap staat in `assets/images/original-import/`.
- De website staat in de repo-root (`index.html`, `assets/`, `beheer/`, `favicon/`).

## YourHosting-upload

- **Bron = repo-root** — altijd daar wijzigen en committen.
- **`site-upload/`** alleen vullen vlak vóór je naar de server uploadt (optioneel als handmatige fallback naast Git deploy).

PowerShell (vanuit de projectmap `De Lijsterij - website`):

```powershell
Copy-Item -Path "assets","beheer","favicon","content","*.html","robots.txt","sitemap.xml" -Destination "site-upload\" -Recurse -Force
```

Daarna upload je de **inhoud** van `site-upload/` naar de webroot bij YourHosting (bijv. `public_html`).

## GitHub workflow

- De GitHub-repository voor dit project is gekoppeld aan:
  `https://github.com/Mikbit-VS/delijsterij-website`
- We werken lokaal in deze map en pushen wijzigingen pas wanneer een stap stabiel genoeg is.
- De branch is momenteel `main`.

Handige basiscommando's:

```bash
git status
git add .
git commit -m "Korte beschrijving van de wijziging"
git push
```

Praktische afspraak:

- Eerst lokaal bouwen en controleren in Live Server.
- Daarna pas committen.
- Pas pushen naar GitHub als de stap logisch afgerond is.

## Automatische deploy naar YourHosting (Plesk Git)

1. Koppel de GitHub-repository in Plesk via **Git** aan deze site en laat deployen naar `httpdocs`.
2. Elke `git push` naar de gekoppelde branch (bijv. `main`) wordt daarna automatisch gedeployed.
3. Bij de eerste setup op server:
   - zet `content/content.json.example` om naar `content/content.json`.
4. `content/content.json` staat in `.gitignore` en wordt niet naar GitHub gepusht.
   - Daardoor worden CMS-wijzigingen van de eigenaar op de live server niet overschreven door deploys.
5. Deploy pad in Plesk staat op `/httpdocs` (geen extra submap), zodat pushes direct live gaan.

## Eerstvolgende logische stap

1. Content schrijven en onderhouden via `beheer/` met server-side `content/content.json`.
2. Visuele finetuning in `assets/css/main.css`.
3. Afbeeldingen structureren en koppelen zonder embedded data.
4. Content uit WordPress vergelijken en aanvullen.

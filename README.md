# De Lijsterij website

Deze map is de werkbasis voor de nieuwe website van De Lijsterij.

## Leidende structuur

- `concept/`
  Hier staat het oorspronkelijke HTML-concept. Dit is de creatieve en inhoudelijke basis.
- `site/`
  Hier bouwen we de uiteindelijke website die we verder opschonen, opdelen en publiceren. **Dit is de bron:** alle bewerkingen en commits gebeuren hier.
- `site-upload/`
  Kopie van `site/` om te uploaden naar **YourHosting** (FTP / bestandsbeheer). Geen aparte “waarheid”: vóór elke upload eerst `site/` hierheen kopiëren (zie hieronder).
- `current-site/`
  Hier bewaren we materiaal uit de huidige WordPress-site als referentie.
- `content/`
  Hier verzamelen we teksten, pagina-inhoud en SEO-notities los van de code.
- `docs/`
  Hier houden we planning, sitemap en migratienotities bij.

## Huidige status

- Het originele concept staat in `concept/original/`.
- De bestaande fotomap staat in `site/assets/images/original-import/`.
- De `site/` map is de plek waar we verder gaan bouwen.

## YourHosting-upload

- **Bron = `site/`** — altijd hier wijzigen en committen.
- **`site-upload/`** alleen vullen vlak vóór je naar de server uploadt.

PowerShell (vanuit de projectmap `De Lijsterij - website`):

```powershell
Copy-Item -Path "site\*" -Destination "site-upload\" -Recurse -Force
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

## Eerstvolgende logische stap

1. Het concept omzetten naar een schone `site/index.html`.
2. Inline CSS loshalen naar `site/assets/css/main.css`.
3. Afbeeldingen structureren en koppelen zonder embedded data.
4. Content uit WordPress vergelijken en aanvullen.

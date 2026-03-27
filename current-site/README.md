# Huidige website (referentie)

In `current-site/` staat een **lokale snapshot** van de live WordPress-site van De Lijsterij, bedoeld als **referentie** voor migratie van teksten, SEO en beeldmateriaal.

- Snapshot-map: `current-site/snapshot/`
- Bron: `https://www.delijsterij.nl`

## Updaten van de snapshot

Run vanuit de repo-root:

```powershell
node tools/snapshot-site.mjs
```

Dit downloadt interne pagina’s + assets (best-effort) en schrijft een samenvatting naar `current-site/snapshot/_snapshot-summary.json`.


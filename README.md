# Module Dolibarr lmdbwebsite

`lmdbwebsite` gere le site public et le tunnel d'abonnement du site Les Metiers du Batiment.

Il depend du module Stancer `mapiolca/stancer` pour :

- les cles API Stancer ;
- la creation ou reutilisation client Stancer ;
- les paiements carte ;
- les prelevements SEPA ;
- la reconciliation des paiements.

## Installation

1. Copier ce dossier dans `htdocs/custom/lmdbwebsite`.
2. Installer et activer le module `stancer`.
3. Activer le module `lmdbwebsite`.
4. Configurer les references produits et l'utilisateur technique dans `Configuration > Modules > LMDB Website`.

## Pages publiques

- `/custom/lmdbwebsite/public/subscribe.php`
- `/custom/lmdbwebsite/public/return.php`
- `/custom/lmdbwebsite/public/onboarding.php`

## Site Dolibarr Website

Les sources du site sont dans ce module :

- `content/site.json`
- `src/styles.css`
- `src/main.js`
- `scripts/build-site.mjs`

Les exports pre-generes sont fournis dans `resources/dolibarr-website`. Depuis `htdocs/custom/lmdbwebsite`, `npm run build` les regenere et produit aussi une previsualisation dans `dist/site`.

# Module Dolibarr lmdbwebsite

`lmdbwebsite` gere le site public et le tunnel d'abonnement du site Les Metiers du Batiment.

Il depend du module Stancer `mapiolca/stancer` pour :

- les cles API Stancer ;
- la creation ou reutilisation client Stancer ;
- les paiements carte ;
- les prelevements SEPA ;
- la reconciliation des paiements.

## Installation

1. Deposer ce dossier comme module custom Dolibarr `lmdbwebsite`.
2. Installer et activer les modules `Website` et `stancer`.
3. Activer le module `lmdbwebsite`.
4. Configurer les URL, choisir les services du catalogue Dolibarr, l'utilisateur technique et le compte bancaire dans `Configuration > Modules > LMDB Website`.
5. Cliquer sur **Creer le site web** dans la configuration du module pour creer ou synchroniser le site natif Dolibarr Website.

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

Les exports pre-generes sont fournis dans `resources/dolibarr-website`. Depuis le dossier `lmdbwebsite`, `npm run build` les regenere et produit aussi une previsualisation dans `dist/site`.

Le bouton **Creer le site web** copie ces exports vers le repertoire Dolibarr `documents/website/lmdbwebsite`, cree le site `lmdbwebsite` dans le module natif Website si necessaire, puis cree ou met a jour les pages gerees par le module. Les pages ajoutees manuellement dans Dolibarr Website sont conservees.

Si le site Website est servi sur un domaine different de Dolibarr, renseigner `LMDBWEBSITE_SITE_URL` avec l'URL du site public et `LMDBWEBSITE_DOLIBARR_URL` avec l'URL publique de Dolibarr. Recliquez ensuite sur **Creer le site web** pour resynchroniser les liens d'abonnement.

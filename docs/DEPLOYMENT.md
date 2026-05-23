# Deploiement

## Site public

1. Importer les contenus pre-generes de `resources/dolibarr-website/pages` dans le module Website de Dolibarr.
2. Publier les assets de `resources/dolibarr-website/assets` dans le site Dolibarr ou dans le repertoire public choisi.
3. Optionnel : depuis `htdocs/custom/lmdbwebsite`, lancer `npm run build` pour regenerer les exports et controler le rendu dans `dist/site`.
4. Verifier que les URL historiques restent disponibles :
   - `/`
   - `/fonctionnement/`
   - `/tarifs/`
   - `/contact/`
   - `/mentions-legales/`

## Module Dolibarr

1. Installer et configurer le module Stancer `mapiolca/stancer`.
2. Copier le dossier `lmdbwebsite` vers `htdocs/custom/lmdbwebsite`.
3. Activer le module `LMDB Website` dans Dolibarr.
4. Renseigner la configuration du module :
   - `LMDBWEBSITE_SITE_URL`
   - `LMDBWEBSITE_PRODUCT_REF_BASE`
   - `LMDBWEBSITE_PRODUCT_REF_STANDARD`
   - `LMDBWEBSITE_PRODUCT_REF_PRO`
   - `LMDBWEBSITE_PRODUCT_REF_BASE_ANNUAL` si un produit annuel dedie existe
   - `LMDBWEBSITE_PRODUCT_REF_STANDARD_ANNUAL` si un produit annuel dedie existe
   - `LMDBWEBSITE_PRODUCT_REF_PRO_ANNUAL` si un produit annuel dedie existe
   - `LMDBWEBSITE_USER_ID`
   - `LMDBWEBSITE_BANK_ACCOUNT_ID`
5. Creer ou verifier les produits/services Dolibarr correspondant aux offres.
6. Activer les taches planifiees du module :
   - reconciliation des paiements Stancer ;
   - generation des echeances recurrentes.

## Parcours de test Stancer

1. Passer le module en mode test.
2. Lancer un abonnement depuis `/custom/lmdbwebsite/public/subscribe.php`.
3. Verifier la creation du tiers, contact, contrat, facture initiale et facture modele.
4. Payer via la page hebergee Stancer.
5. Verifier le retour paiement, le paiement Dolibarr, l'activation du contrat et l'ouverture du formulaire SIRET/logo.
6. Soumettre un SIRET et un logo PNG/JPG/WebP.
7. Verifier la mise a jour du tiers, le stockage du logo et la creation de l'action interne "Creer l'entite Dolibarr".

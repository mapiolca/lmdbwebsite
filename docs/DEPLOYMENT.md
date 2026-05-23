# Deploiement

## Site public

1. Activer le module natif `Website` de Dolibarr.
2. Ouvrir `Configuration > Modules > LMDB Website`.
3. Cliquer sur **Creer le site web**.
4. Verifier que le site `lmdbwebsite` existe dans le module Website et que les fichiers ont ete copies dans `documents/website/lmdbwebsite`.
5. Optionnel : depuis le dossier `lmdbwebsite`, lancer `npm run build` pour regenerer les exports embarques dans `resources/dolibarr-website` et controler le rendu dans `dist/site`.
6. Relancer **Creer le site web** apres chaque regeneration pour synchroniser les pages et assets Dolibarr.
7. Verifier que les URL historiques restent disponibles :
   - `/`
   - `/fonctionnement/`
   - `/tarifs/`
   - `/contact/`
   - `/mentions-legales/`

Le bouton est idempotent : il met a jour les pages gerees par `lmdbwebsite`, garde les pages manuelles intactes, et cree les nouvelles pages en brouillon.

## Module Dolibarr

1. Installer et configurer le module Stancer `mapiolca/stancer`.
2. Deposer le dossier `lmdbwebsite` dans le repertoire custom de Dolibarr.
3. Activer le module `LMDB Website` dans Dolibarr.
4. Renseigner la configuration du module :
   - `LMDBWEBSITE_SITE_URL`
   - `LMDBWEBSITE_DOLIBARR_URL`
   - `LMDBWEBSITE_PRODUCT_REF_BASE`
   - `LMDBWEBSITE_PRODUCT_REF_STANDARD`
   - `LMDBWEBSITE_PRODUCT_REF_PRO`
   - `LMDBWEBSITE_PRODUCT_REF_BASE_ANNUAL` si un produit annuel dedie existe
   - `LMDBWEBSITE_PRODUCT_REF_STANDARD_ANNUAL` si un produit annuel dedie existe
   - `LMDBWEBSITE_PRODUCT_REF_PRO_ANNUAL` si un produit annuel dedie existe
   - `LMDBWEBSITE_USER_ID`
   - `LMDBWEBSITE_BANK_ACCOUNT_ID`
5. Creer ou verifier les services Dolibarr correspondant aux offres, puis les choisir dans les selecteurs de la configuration du module.
6. Activer les taches planifiees du module :
   - reconciliation des paiements Stancer ;
   - generation des echeances recurrentes.

Si le site Website utilise un virtualhost separe, `LMDBWEBSITE_SITE_URL` doit pointer vers ce site et `LMDBWEBSITE_DOLIBARR_URL` vers l'URL publique de Dolibarr. Apres modification d'une de ces URL, relancer **Creer le site web** pour resynchroniser les liens d'abonnement.

## Parcours de test Stancer

1. Passer le module en mode test.
2. Lancer un abonnement depuis `/custom/lmdbwebsite/public/subscribe.php`.
3. Verifier la creation du tiers, contact, contrat, facture initiale et facture modele.
4. Payer via la page hebergee Stancer.
5. Verifier le retour paiement, le paiement Dolibarr, l'activation du contrat et l'ouverture du formulaire SIRET/logo.
6. Soumettre un SIRET et un logo PNG/JPG/WebP.
7. Verifier la mise a jour du tiers, le stockage du logo et la creation de l'action interne "Creer l'entite Dolibarr".

# Checklist — Site Master WooCommerce Algérie 🇩🇿

> Objectif : monter **une seule fois** un site WordPress/WooCommerce complet,
> localisé Algérie (FR/AR, COD, Chargily, Yalidine, 58 wilayas), puis le
> **dupliquer** pour chaque nouveau client.

---

## PHASE 0 — Hébergement & prérequis

- [ ] Hébergement mutualisé compatible (PHP **8.1+**, MySQL 5.7+/MariaDB 10.4+)
- [ ] Au moins **512 Mo** mémoire PHP (idéalement 1 Go), `max_execution_time` ≥ 120s
- [ ] Nom de domaine + **certificat SSL/HTTPS** activé (obligatoire pour le paiement)
- [ ] Accès cPanel / FTP / phpMyAdmin notés

---

## PHASE 1 — Installation de base

- [ ] Installer **WordPress** (dernière version)
- [ ] Réglages > Permaliens : choisir **« Titre de la publication »**
- [ ] Supprimer thèmes/plugins inutiles livrés par défaut
- [ ] Créer un compte admin avec **mot de passe fort** (pas « admin »)
- [ ] Réglages > Général : fuseau **Africa/Algiers**, langue selon cible

---

## PHASE 2 — WooCommerce

- [ ] Installer le plugin **WooCommerce**
- [ ] Assistant de configuration :
  - [ ] Pays : **Algérie**
  - [ ] Devise : **Dinar algérien (DZD)** — symbole `دج` / `DA`
  - [ ] Unités : kg / cm
- [ ] Désactiver les méthodes de paiement étrangères par défaut (Stripe/PayPal)

---

## PHASE 3 — Thème (RTL + rapide)

- [ ] Installer un thème léger : **Astra** ou **Botiga** (wordpress.org)
- [ ] Créer/activer un **child theme** (pour garder les personnalisations aux mises à jour)
- [ ] Importer un modèle de démo « boutique » si fourni
- [ ] Vérifier l'affichage **mobile** (la majorité du trafic DZ est mobile)

---

## PHASE 4 — Localisation FR / AR

- [ ] Définir la langue principale (FR ou AR)
- [ ] Si bilingue : installer **Polylang** (gratuit)
- [ ] Si site arabe : vérifier que le thème passe bien en **RTL**
- [ ] Traduire les pages clés : Accueil, Boutique, Panier, Commande, Contact
- [ ] Pages légales : Mentions, CGV, Politique de retour/remboursement

---

## PHASE 5 — Paiement (Algérie)

- [ ] Installer **Chargily ePay** (`Chargily/chargily-epay-woocommerce`)
  - [ ] Créer un compte marchand Chargily + récupérer les **clés API**
  - [ ] Activer **CIB** et **Edahabia**
  - [ ] Tester un paiement en **mode test** puis basculer en **production**
- [ ] Activer le **Paiement à la livraison (COD)** — natif WooCommerce
  - [ ] WooCommerce > Réglages > Paiements > « Paiement à la livraison »
  - [ ] (Optionnel) limiter le COD à certaines zones/wilayas

---

## PHASE 6 — Livraison (58 wilayas + Yalidine)

- [ ] WooCommerce > Réglages > Expédition
- [ ] Option simple (recommandé au début) :
  - [ ] Créer une zone par groupe de wilayas OU une zone « Algérie »
  - [ ] Définir tarifs **Domicile** et **Stop Desk** (bureau)
- [ ] Option avancée : installer le plugin **Yalidine** (`essambarghsh/yalidine`)
  - [ ] ⚠️ Tester soigneusement (plugin récent, mono-auteur)
  - [ ] Connecter les clés API Yalidine
- [ ] Vérifier le **calcul des frais au panier** pour quelques wilayas

---

## PHASE 7 — Confiance & conversion (spécifique DZ)

- [ ] **Formulaire de commande simplifié** (Nom, Tél, Wilaya, Commune, Adresse)
  - [ ] Le **téléphone** doit être obligatoire (le COD se confirme par appel)
  - [ ] Rendre l'email **facultatif** (beaucoup d'acheteurs n'en mettent pas)
- [ ] Champ **Wilaya / Commune** en liste déroulante (plugin de checkout DZ)
- [ ] Notifications de commande : envisager **SMS / WhatsApp** plutôt qu'email
- [ ] Bouton **WhatsApp** flottant pour le support
- [ ] Badges de confiance + photos produits de qualité

---

## PHASE 8 — Performance & SEO

- [ ] Plugin de **cache** (WP Super Cache / LiteSpeed Cache)
- [ ] **Compression des images** (Smush / ShortPixel)
- [ ] **SEO** : Rank Math ou Yoast
- [ ] Tester la vitesse (PageSpeed) — viser < 3s sur mobile

---

## PHASE 9 — Sécurité & sauvegarde

- [ ] Plugin de sécurité (Wordfence / Solid Security)
- [ ] Limiter les tentatives de connexion + 2FA admin
- [ ] **Sauvegardes automatiques** (UpdraftPlus)
- [ ] Forcer HTTPS partout

---

## PHASE 10 — Faire le « MASTER » réutilisable

- [ ] Vérifier que tout fonctionne (commande test COD + commande test Chargily)
- [ ] Installer **All-in-One WP Migration** ou **Duplicator**
- [ ] **Exporter** le site complet → c'est ton **modèle réutilisable**
- [ ] Pour chaque nouveau client :
  - [ ] Nouveau domaine + WP vierge
  - [ ] **Importer** le master
  - [ ] Changer logo, couleurs, produits, clés API paiement/livraison
  - [ ] Livrer 🚀

---

## Récapitulatif des composants

| Besoin | Solution | Source |
|---|---|---|
| CMS + boutique | WordPress + WooCommerce | wordpress.org |
| Thème | Astra / Botiga (+ child theme) | wordpress.org |
| Paiement CIB/Edahabia | Chargily ePay | github.com/Chargily/chargily-epay-woocommerce |
| Paiement COD | Natif WooCommerce | inclus |
| Livraison | Yalidine + zones wilayas | github.com/essambarghsh/yalidine |
| Bilingue FR/AR | Polylang | wordpress.org |
| Duplication | All-in-One WP Migration / Duplicator | wordpress.org |

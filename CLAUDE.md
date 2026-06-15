# CLAUDE.md

> Ce dépôt est un **template e-commerce réutilisable** (WordPress + WooCommerce,
> COD / paiement à la livraison, Algérie), **cloné** pour chaque nouveau client.
> Le guide détaillé est dans **`TEMPLATE.md`** (importé ci-dessous) — **lis-le en
> entier avant de toucher au code.**

@TEMPLATE.md

---

## Règles non-négociables (rappel — détail dans TEMPLATE.md)

1. **Template générique.** Rien de spécifique à un seul client en dur (nom,
   couleurs, clés API) — tout se configure depuis l'interface. Minimal, pas de
   fonctionnalité superflue.
2. **Côté public = exactement 3 pages :** Accueil · Boutique · Contact. La nav ne
   montre que ces 3 entrées.
3. **JAMAIS de panier ni de compte** côté acheteur. L'achat passe **uniquement**
   par le formulaire COD (`aod-cod-form`) sur la page produit.
4. **FR ⇄ AR + RTL** sur tout le visible. Après toute chaîne UI, lancer
   `plugins-dev/aod-client-dashboard/bin/make-translations.sh` et traduire l'AR
   (manuel — pas d'internet sortant). Cf. TEMPLATE.md §7.
5. **Mobile d'abord.** Tester en responsive avant de dire « fini ».
6. **Isolation Docker.** Chaque clone a SA stack. Avant toute installation :
   `pwd` (bon dossier), `docker ps -a` (ne pas toucher aux conteneurs d'un autre
   projet), `PROJECT_SLUG`/ports **uniques** dans `.env`, et piloter via
   `docker compose exec <service>` depuis le dossier du clone — **jamais**
   `docker exec wp_cli_c1 …`. Cf. TEMPLATE.md §3.
7. **Choix du thème :** si le client n'impose rien, choisir le kit le plus adapté
   dans la bibliothèque Elementor en **analysant les aperçus toi-même** — voir
   TEMPLATE.md §4 (procédure + commandes).

---

## Méthode de travail — BMAD

Ce projet utilise la méthode **BMAD** (dossier `_bmad/`, skills `bmad-*` dans
`.claude/skills/`). Sortie des documents en **français**
(`document_output_language = "French"`).

**Construire / refondre les 3 pages d'un nouveau client = flux BMAD planifié**, pas
du code à l'arrache :

1. `bmad-agent-analyst` — cerner le besoin et le **secteur d'activité** du client.
2. `bmad-agent-ux` — **choisir le kit** (TEMPLATE.md §4) + définir l'UX des 3 pages.
3. `bmad-agent-architect` — plan d'intégration (thème de base + Elementor, child
   theme, branding via Personnalisateur, RTL).
4. `bmad-create-story` → `bmad-dev-story` — découper puis implémenter.

**Petites retouches** (un bug, un libellé, un ajustement CSS) : édition directe ou
`bmad-quick-dev`, sans dérouler tout le flux.

Quoi qu'il arrive, le flux BMAD **ne dispense pas** des règles non-négociables
ci-dessus : elles priment sur toute proposition d'un agent BMAD.

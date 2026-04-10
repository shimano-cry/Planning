# PLANNING C.C.P.D. Tournai — Leçons apprises

## Format : [date] | ce qui a mal tourné | règle pour l'éviter

---

[2026-04] | Migration v3 a écrasé le wrapper `create_vacation_overrides_v1` lors de l'insertion | Toujours vérifier avec `grep -n "create_vacation_overrides_v1"` après toute insertion de migration

[2026-04] | Modifications perdues car fichier v1.43 re-uploadé à la place du fichier modifié | Toujours télécharger le fichier outputs avant de fermer la session

[2026-04] | Couleurs douane non appliquées car migrations déjà en `app_meta` | Créer une nouvelle migration (vX+1) plutôt que modifier une migration existante déjà exécutée

[2026-04] | `buildVacOverrideSection` disparaissait pour NUIT/direction | La section vacation override est appelée uniquement depuis `openCongeModal` (isPerm=false) — toujours l'appeler aussi dans le bloc isPerm

[2026-04] | `font-weight:bold` non appliqué sur cellules congés | Le style CSS `.conge{}` est écrasé par le style inline — ajouter `font-weight:bold` directement dans `$styleCg` PHP

[2026-04] | Numérotation des fichiers : underscore vs point | Convention retenue : `planning_v1.XX.php` (avec point), v1.99 → v2.00

[2026-04] | Demandes appliquées sur mauvaise version | Toujours demander confirmation de la version avant de travailler — le fichier uploadé fait foi

---

## Architecture clé du fichier

### PHP
- **Migrations** : `runMigrationOnce()` — s'exécutent une seule fois via `app_meta`
- **Rendu cellules** : `renderCellsHtml()` (AJAX) + `ligne()` (rendu initial) — les deux doivent être synchronisés
- **Couleurs** : `$CODES_POLICE_DEFAUT` et `$CODES_DOUANE_DEFAUT` = fallback si BDD vide

### JS
- **`IS_ADMIN`** / **`IS_COGE`** / **`IS_DOUANE`** : constantes de droits exportées depuis PHP
- **`openModal()`** → route vers `openCongeModal()` ou `openTirModal()`
- **`buildVacOverrideSection()`** : doit être appelé dans les deux branches (isPerm true ET false)
- **`ADMIN_CONGE_CODES`** : ordre et contenu de la grille de congés admin

### Groupes agents
| Groupe | Agents |
|---|---|
| direction_police | CoGe ROUSSEL |
| direction_mokadem | Cne MOKADEM |
| nuit | BC MASSON, BC SIGAUD, BC DAINOTTI |
| equipe | BC BOUXOM, BC ARNAULT, BC HOCHARD, BC ANTHONY, BC BASTIEN, BC DUPUIS |
| gie | ADJ LEFEBVRE, ADJ CORRARD |
| douane / douane_j | IR MOREAU, ACP1 LOIN, ACP1 DEMERVAL |
| standard_police | GP DHALLEWYN, BC DELCROIX, BC DRUEZ |
| lcl_parent | LCL PARENT |
| adc_lambert | ADC LAMBERT |
| standard_j | AA MAES |

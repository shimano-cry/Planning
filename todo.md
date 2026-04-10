# PLANNING C.C.P.D. Tournai — État du projet

## Fichier actuel
- **Version** : planning_v1.97.php (~7650 lignes)
- **Stack** : PHP + MySQL (XAMPP/Windows) + JS/CSS monolithique
- **Déploiement** : upload manuel via GitHub → XAMPP

---

## Modifications récentes (dernières sessions)

### v1.88 — Fenêtre permanences NUIT / Direction
- Suppression boutons M/AM/NUIT dans la grille pour NUIT et CoGe/Cne
- Ajout NUIT dans "Modifier la vacation affichée" pour direction
- Fix : `buildVacOverrideSection` appelé dans le bloc `isPerm`

### v1.89 — Couleurs douane M/AM
- M et AM douane alignés sur équipes 1&2 (`#ffd200` / `#2f5597`)

### v1.90 — Largeur colonnes planning
- `table-layout:fixed` + `width:42px` pour uniformiser toutes les cellules

### v1.91-v1.93 — Couleurs congés douane + bold
- CA douane : `#FF6633` fond / blanc texte / bold
- `font-weight:bold` ajouté dans le style inline des cellules congés (renderCellsHtml + ligne())

### v1.94 — Organisation interne
- Ajout séparateurs de sections dans le fichier (MIGRATIONS, AJAX, CSS, JS...)

### v1.95-v1.97 — Couleurs finales douane
- CET/NC/CM/GEM/AEA/RC/DA/PR : `#ffcc99` / blanc
- RH : `#ffff00` / noir
- Migration `update_couleurs_douane_v4` pour forcer la BDD

---

## Prochaines tâches
- [ ] _(à compléter selon les besoins)_

---

## Notes importantes
- Toujours travailler sur le dernier fichier uploadé
- Sauvegarder sous `planning_v1.XX.php` (v1.99 → v2.00)
- Après chaque modification, **télécharger le fichier outputs avant de fermer la session**
- La migration `update_couleurs_douane_v4` s'exécutera automatiquement au 1er chargement

# PLANNING C.C.P.D. Tournai — État du projet

## Version actuelle
- **Fichier** : planning_v2.97.php
- **Constante** : define('PLANNING_VERSION','2.97')
- **Stack** : PHP + MySQL (XAMPP/Windows) + JS/CSS monolithique

## Corrections majeures v2.92→v2.97
- v2.92 : Rafraîchissement groupe cyclique après save/delete congé
- v2.95 : ignFe pour nuit dans renderCellsHtml (FERIE fantômes)
- v2.97 : masq exclu pour nuit dans renderCellsHtml (RC/RL invisibles)

## Fonctionnalités complètes
- Planning mensuel adaptatif (clamp CSS)
- Congés tous groupes + workflow DA/PR validation admin
- Permanences M/AM/IM/IAM/IJ selon groupes
- GIE : M/AM/J/P/R semaine + M/AM/IM/IAM/IJ WE/Férié
- Plan de rappel (tableau groupé, numéros internationaux)
- Recherche & Archives (années archivables)
- Sauvegarde 1 clic (SQL + PHP)
- Onglet Verrous compact (grille 22 agents × 12 mois)
- Tuto groupes (bouton ℹ️)
- Touche Échap ferme toutes les modales

## PENDING
- [ ] Double congé M+AM (session dédiée — $cg[$a][$ds][$per])
- [ ] Scission période congé (retirer un jour dans une plage)
- [ ] Compléter plan de rappel (tableau agents à fournir)

## Convention
- Incrémenter PLANNING_VERSION à chaque modification
- Sauvegarder todo.md + lessons.md + .php + .sql sur GitHub

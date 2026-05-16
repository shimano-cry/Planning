# LEÇONS APPRISES — Planning C.C.P.D. Tournai

## Format : [date] | ce qui a mal tourné | règle pour l'éviter

[2026-04] | Fichier uploadé = ancienne version | Toujours télécharger depuis /outputs/ avant de fermer la session
[2026-04] | Migration écrase le wrapper suivant | Après str_replace migration, vérifier que les wrappers sont intacts
[2026-04] | Migration déjà exécutée ne se ré-applique pas | Toujours créer vN+1 — jamais modifier une migration existante
[2026-04] | ob_start() externe conflicte avec renderCellsHtml() | Ne jamais wrapper renderCellsHtml() avec ob_start()
[2026-04] | reset() sur expression temporaire = erreur PHP | Stocker dans variable avant appel reset()
[2026-04] | function ligne() disparaît après str_replace | Après str_replace dans cette zone, grep "function ligne"
[2026-04] | Polling race condition après delete | Mettre à jour _pollToken immédiatement après toute action
[2026-04] | $groupeAgent statique | Charger depuis agents_history (date_fin IS NULL)
[2026-04] | PLANNING_VERSION pas mis à jour | Mettre à jour define('PLANNING_VERSION','X.XX') à chaque cp vers outputs
[2026-04] | Listener imbriqué dans un autre → inactif | Ne jamais imbriquer des addEventListener — toujours au niveau racine
[2026-04] | overlay-rappel perdait sa balise div | Après str_replace modales, vérifier div.overlay-perm présente
[2026-05] | renderCellsHtml $masq=true pour nuit → RC/RL blanc invisible | Exclure 'nuit' de $masq — nuit a masqueRC=false dans ligne()
[2026-05] | renderCellsHtml ignorait ignFe pour nuit → FERIE fantômes | $ignFe=($grp==='nuit') dans renderCellsHtml, comme ligne(...,ignFe=true)
[2026-05] | Bug AJAX cycles nuit : analyse 10+ versions debug | renderCellsHtml DOIT être identique à ligne() — masqueRC, ignFe, masq

## Règle CRITIQUE renderCellsHtml
Ces deux fonctions doivent produire HTML identique pour le même agent/date :
- Nuit : masqueRC=false, ignFe=true, masq=false (RC/RL visibles, fériés ignorés)
- Équipe/GIE/Standard_police : masqueRC=true, ignFe=false, masq=true (RC/RL masqués)
Toute divergence entre ligne() et renderCellsHtml = bug AJAX garanti.

## Règles permanentes
- PLANNING_VERSION : incrémenter à chaque version
- Jamais modifier une migration existante — créer vN+1
- IS_DOUANE : toujours exempté comme IS_COGE et IS_ADMIN
- addEventListener : niveau racine uniquement
- Après session : mettre à jour todo.md et lessons.md

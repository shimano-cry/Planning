# LEÇONS APPRISES — Planning C.C.P.D. Tournai

## Format : [date] | ce qui a mal tourné | règle pour l'éviter

---

[2026-04] | Fichier uploadé = ancienne version | Toujours télécharger depuis /outputs/ avant de fermer la session

[2026-04] | Migration écrase le wrapper runMigrationOnce() suivant | Après str_replace migration, vérifier que les wrappers sont intacts

[2026-04] | Migration déjà exécutée ne se ré-applique pas | Toujours créer vN+1 — jamais modifier une migration existante

[2026-04] | buildVacOverrideSection() disparaissait | Toujours l'appeler dans les deux chemins isPerm=true ET false

[2026-04] | IS_DOUANE non exempté comme IS_COGE | Vérifier IS_DOUANE partout où IS_COGE est exempté

[2026-04] | Double ;; → erreur PHP silencieuse | Après str_replace PHP : grep ";;" pour détecter

[2026-04] | ob_start() externe conflicte avec renderCellsHtml() | Ne jamais wrapper renderCellsHtml() avec ob_start()

[2026-04] | reset() sur expression temporaire = erreur PHP | Stocker dans variable avant : $tmp=$arr??[]; reset($tmp)

[2026-04] | function ligne() a disparu après str_replace | Après str_replace dans cette zone, grep "function ligne" pour confirmer

[2026-04] | Polling écrasait les cellules après delete (race condition) | Mettre à jour _pollToken immédiatement après toute action

[2026-04] | Double congé M+AM en cascade a tout cassé | $cg[$a][$ds][$per] impacte trop de fonctions — faire une fonction à la fois

[2026-04] | $groupeAgent statique → modif PHP manuelle après renommage | Charger depuis agents_history (date_fin IS NULL)

[2026-04] | str_replace échoue silencieusement si chaîne non trouvée | Toujours view avant str_replace critique

[2026-04] | /mnt/user-data/outputs/ peut tomber en erreur I/O | Sauvegarder aussi dans /tmp/ en backup

[2026-04] | PLANNING_VERSION pas mis à jour → mauvais nom de fichier sauvegardé | Mettre à jour define('PLANNING_VERSION','X.XX') à chaque cp vers outputs

[2026-04] | Listener btn-backup imbriqué dans btn-archiver → inactif | Ne jamais imbriquer des addEventListener — toujours au niveau racine

[2026-04] | overlay-rappel perdait sa balise div d'ouverture | Après str_replace sur les modales, vérifier que la div.overlay-perm est bien présente

[2026-04] | function ligne() déclaration manquante après str_replace | Après tout str_replace dans la zone fonction ligne(), vérifier grep "function ligne"

---

## Règles permanentes

- **Convention fichiers** : planning_v2.XX.php — incrémenter +1 à chaque modification
- **PLANNING_VERSION** : mettre à jour `define('PLANNING_VERSION','X.XX')` à chaque version
- **Jamais** modifier une migration déjà exécutée — créer une vN+1
- **Toujours** inclure IS_DOUANE dans les exceptions au même titre que IS_COGE et IS_ADMIN
- **Toujours** vérifier les doubles ;; après str_replace PHP
- **Toujours** vérifier que function ligne() existe après str_replace dans cette zone
- **Toujours** mettre à jour _pollToken après delete/save pour éviter la race condition
- reset() : stocker l'expression dans une variable avant d'appeler reset()
- ob_start() : ne jamais imbriquer avec renderCellsHtml()
- Le style inline PHP écrase la classe CSS sauf si !important
- Pour CSS ciblé par groupe : utiliser td[data-groupe="xxx"]
- $groupeAgent est dynamique depuis agents_history — ne plus le modifier statiquement
- addEventListener : toujours au niveau racine, jamais imbriqué dans un autre listener
- Après chaque session : mettre à jour todo.md et lessons.md dans outputs/

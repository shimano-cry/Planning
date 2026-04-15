# LEÇONS APPRISES — Planning C.C.P.D. Tournai

## Format : [date] | ce qui a mal tourné | règle pour l'éviter

---

[2026-04] | Le fichier uploadé était l'ancienne version sans les modifs de la session précédente | Toujours télécharger le fichier de sortie /outputs/ avant de fermer la session

[2026-04] | L'insertion d'une migration écrasait le wrapper runMigrationOnce() suivant | Après chaque str_replace qui insère une migration, vérifier grep pour s'assurer que les wrappers sont intacts

[2026-04] | Les migrations déjà exécutées ne se ré-appliquent pas | Toujours créer une migration vN+1 pour forcer une mise à jour BDD — jamais modifier une existante

[2026-04] | buildVacOverrideSection() disparaissait après certaines actions | Toujours l'appeler dans les deux chemins isPerm=true ET isPerm=false

[2026-04] | IS_DOUANE non exempté au même titre que IS_COGE | Vérifier IS_DOUANE partout où IS_COGE est exempté

[2026-04] | Double ;; dans renderCellsHtml causait une erreur PHP silencieuse | Après tout str_replace PHP : grep ";;" pour détecter les doubles point-virgules

[2026-04] | ob_start() externe dans delete_conge conflictait avec ob_start() interne de renderCellsHtml | Ne jamais wrapper renderCellsHtml() avec ob_start() — elle gère son propre buffer

[2026-04] | reset() sur expression temporaire = erreur PHP "cannot be passed by reference" | Toujours stocker le tableau dans une variable avant d'appeler reset() : $tmp=$arr??[]; reset($tmp)

[2026-04] | La déclaration function ligne() a disparu lors d'un str_replace raté | Après tout str_replace sur une fonction PHP, vérifier grep "function ligne" pour confirmer que la déclaration est intacte

[2026-04] | Le polling écrasait les cellules juste après une suppression (race condition) | Après toute action de modification/suppression, mettre à jour _pollToken immédiatement avec le token retourné par le serveur

[2026-04] | La tentative double congé M+AM a cassé save/delete en cascade | L'indexation $cg[$a][$ds][$per] impacte renderCellsHtml, ligne(), loadConges(), save_conge, delete_conge ET le JS — faire une fonction à la fois, tester après chaque changement

[2026-04] | $groupeAgent statique nécessitait une modif PHP manuelle après renommage d'agent | Charger $groupeAgent depuis agents_history (date_fin IS NULL) — le fallback statique reste en cas de table vide

[2026-04] | str_replace échoue silencieusement si la chaîne n'est pas trouvée exactement | Toujours view le fichier juste avant un str_replace critique, et vérifier le retour "Successfully replaced"

[2026-04] | /mnt/user-data/outputs/ peut tomber en erreur I/O en cours de session | Sauvegarder aussi dans /tmp/ en backup, et tenter une nouvelle copie en outputs dès que possible

---

## Règles permanentes

- **Convention fichiers** : planning_v2.XX.php — incrémenter +1 à chaque modification
- **Jamais** modifier une migration déjà exécutée — créer une vN+1
- **Toujours** inclure IS_DOUANE dans les exceptions au même titre que IS_COGE et IS_ADMIN
- **Toujours** vérifier les doubles ;; après str_replace PHP
- **Toujours** vérifier que function ligne() existe après tout str_replace dans cette zone
- **Toujours** mettre à jour _pollToken après delete/save pour éviter la race condition polling
- reset() : stocker l'expression dans une variable avant d'appeler reset()
- ob_start() : ne jamais imbriquer avec renderCellsHtml()
- Le style inline PHP écrase la classe CSS sauf si !important
- Pour CSS ciblé par groupe : utiliser td[data-groupe="xxx"]
- $groupeAgent est dynamique depuis agents_history — ne plus le modifier statiquement

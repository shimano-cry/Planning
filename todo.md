# PLANNING C.C.P.D. Tournai — État du projet

## Fichier actuel
- **Version** : planning_v2.54.php (~8300+ lignes)
- **Stack** : PHP + MySQL (XAMPP/Windows) + JS + CSS monolithique
- **Dernière version stable** : v2.54 (outputs/)

---

## Groupes & agents (chargés dynamiquement depuis agents_history)
| Groupe | Agents |
|---|---|
| direction_police | CoGe ROUSSEL |
| direction_mokadem | Cne MOKADEM |
| lcl_parent | LCL PARENT |
| nuit | BC MASSON, BC SIGAUD, BC DAINOTTI |
| equipe | BC BOUXOM, BC ARNAULT, BC HOCHARD, BC ANTHONY, BC BASTIEN, BC DUPUIS |
| gie | ADJ LEFEBVRE, ADJ CORRARD |
| douane | ACP1 LOIN |
| douane_j | IR MOREAU, ACP1 DEMERVAL |
| standard_police | GP DHALLEWYN, BC DELCROIX, BC DRUEZ |
| standard_j | AA MAES |
| adc_lambert | ADC LAMBERT |

> $groupeAgent chargé dynamiquement depuis agents_history (date_fin IS NULL = actif)

---

## Migrations en base (app_meta)
- create_user_rights_v1, create_tir_notes_v1, alter_permanences_type_v1
- add_conges_maladie_v1 à v6, update_couleurs_conges_v1 à v6
- update_couleurs_douane_v1 à v6
- create_vacation_overrides_v1, create_agent_quotas_v2, create_agents_history_v1
- alter_periode_nuit_v1, add_conges_valide_v1
- create_plan_rappel_v1, update_plan_rappel_v2

---

## Fonctionnalités implémentées

### Planning
- Cellules 52px, couleurs police/douane/GIE
- Congés fériés masqués pour nuit/équipe/standard
- Polling rafraîchissement silencieux (token mis à jour après delete)

### Congés
- Workflow DA/PR : validation admin (onglet Validations)
- User simple : Supprimer masqué si DA/PR validé
- Section Période masquée pour users simples
- Fenêtres 700px

### Permanences
- Boutons IM/IAM/IJ supprimés
- Tableau agents × jours (M/AM)
- Légende restitutions : Samedi/Dimanche/Férié
- Bouton Imprimer

### Plan de rappel
- Table plan_rappel : Prénom, Nom, Domicile, GSM, NEO, Fonction/Rôle
- Bouton 📞 Rappel en premier, animation pulsante rouge
- Visible tous, éditable admin, téléphones cliquables, Imprimer

### Admin
- Onglet Validations DA/PR par mois (développer/réduire, réduit par défaut)
- $groupeAgent dynamique (plus de modif PHP manuel après renommage)

---

## PENDING
- [ ] Double congé M+AM (session dédiée — refonte $cg[$a][$ds][$per])
- [ ] Compléter plan de rappel (tableau à fournir)
- [ ] Tester workflow DA/PR validation en production

---

## Historique versions
- v2.27 : Base stable
- v2.33 : Réintégration modifs stables
- v2.34 : Workflow validation DA/PR
- v2.41 : Validations mois réduits par défaut
- v2.42 : Période masquée users simples
- v2.44 : Fix congé sur férié + fix function ligne()
- v2.45-47 : LCL PARENT/GIE fenêtres + NUIT
- v2.48 : $groupeAgent dynamique
- v2.49-51 : Légende + Imprimer permanences
- v2.52-53 : Plan de rappel
- v2.54 : Fenêtres congés 700px

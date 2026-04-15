# PLANNING C.C.P.D. Tournai — État du projet

## Fichier actuel
- **Version** : planning_v2.85.php
- **Constante** : `define('PLANNING_VERSION','2.85')` — à incrémenter à chaque modif
- **Stack** : PHP + MySQL (XAMPP/Windows) + JS + CSS monolithique
- **Dernière version stable** : v2.85 (outputs/)

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

---

## Migrations en base (app_meta)
- create_user_rights_v1, create_tir_notes_v1, alter_permanences_type_v1
- add_conges_maladie_v1 à v6, update_couleurs_conges_v1 à v6
- update_couleurs_douane_v1 à v6
- create_vacation_overrides_v1, create_agent_quotas_v2, create_agents_history_v1
- alter_periode_nuit_v1, add_conges_valide_v1
- create_plan_rappel_v1, update_plan_rappel_v2, v3, v4, v5, v6
- create_archives_annees_v1

---

## Fonctionnalités implémentées

### Planning
- Cellules adaptatives `clamp()` — s'ajustent à la largeur écran sans déborder sur Notes
- Couleurs police/douane/GIE, symboles ☀🌙 dans cellules
- Congés fériés masqués pour nuit/équipe/standard
- Polling rafraîchissement silencieux (token mis à jour après delete)
- Bannière ARCHIVÉ si année archivée

### Congés
- Workflow DA/PR : validation admin (onglet ✅ Validations, groupé par mois, réduit par défaut)
- DA/PR validé : curseur 🚫, modal bloqué pour user, seul admin peut supprimer
- Année archivée : lecture seule pour tous
- Section Période masquée pour users simples (sauf admin/CoGe/douane)
- Fenêtres congés 700-900px selon groupe
- Grille admin : 6 colonnes, PREV en 1ère position, sections colorées

### GIE (LEFEBVRE, CORRARD)
- En semaine : M/AM/J/P/R dans fenêtre congés
- WE/Férié : M + AM + IM + IAM + IJ dans mode permanences
- Libellés sous boutons, couleurs P=vert, R=gris

### Permanences
- Admin : M+AM (équipe) + IM+IAM+IJ sur 2 lignes
- Users : IM+IAM+IJ uniquement (sauf GIE)
- Détail mensuel : tableau agents × jours (M/AM uniquement)
- Légende restitutions horaires (Samedi/Dimanche/Férié)
- Bouton Imprimer (iframe, pas de fenêtre parasite)

### Plan de rappel
- Table plan_rappel : Prénom, Nom, Domicile, GSM, NEO, Fonction/Rôle, Groupe
- Bouton 📞 Rappel en premier, animation pulsante rouge
- Tableau groupé par section (Direction/Équipe 1.../GIE...)
- Direction : ordre ROUSSEL→PARENT→MOREAU→MOKADEM
- Numéros belges : BOUXOM GSM +32 mobile, DRUEZ Domicile +32 fixe
- Impression via iframe (paysage, pas de fenêtre parasite)
- Migration v5/v6 pour groupes automatiques

### Archivage & Recherche
- Table archives_annees, bouton admin "🗄️ Archiver"
- Bouton 🔍 Recherche dans toolbar (filtres agent/type/dates/année)
- Impression résultats recherche

### Sauvegarde
- Bouton 💾 Sauvegarder (vert, toolbar admin)
- Télécharge SQL (toutes tables) + PHP en un clic
- Constante PLANNING_VERSION dans le fichier

### Admin
- Onglet Validations DA/PR groupé par mois (réduit par défaut, reste ouvert après action)
- $groupeAgent dynamique depuis agents_history
- Tuto groupes : bouton ℹ️ dans formulaire historique agents
- Bouton 💾 Sauvegarder dans toolbar

---

## PENDING
- [ ] Double congé M+AM (session dédiée — refonte $cg[$a][$ds][$per])
- [ ] Tester workflow DA/PR en production
- [ ] Compléter plan de rappel (tableau à fournir)

---

## Convention versions
- Incrémenter `PLANNING_VERSION` dans le PHP à chaque modification
- Sauvegarder sous planning_v2.XX.php dans outputs/
- Mettre à jour todo.md et lessons.md après chaque session

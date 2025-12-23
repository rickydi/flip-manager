# Milestones

## milestone-fiscal-v1 (2025-12-23)
**Commit:** 2d873ee

### Fonctionnalités
- Tableau de bord fiscal sur page principale
- Sélecteur d'année basé sur les dates de vente
- Calcul d'impôt progressif (12,2% → 26,5% après 500k$)
- Taux dynamique selon profit cumulatif de l'année
- Projections pour projets en cours
- Design moderne et épuré

### Fichiers clés
- `flip/includes/calculs.php` - Fonctions fiscales
- `flip/admin/index.php` - Dashboard fiscal
- `flip/admin/projets/detail.php` - Taux dynamique par projet

### Pour revenir à ce point
```bash
git checkout milestone-fiscal-v1
```

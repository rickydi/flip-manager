<?php
/**
 * Système de traduction multilingue
 * Flip Manager
 */

// Langues disponibles
define('AVAILABLE_LANGUAGES', ['fr', 'es']);
define('DEFAULT_LANGUAGE', 'fr');

// Traductions
$TRANSLATIONS = [
    'fr' => [
        // Navigation
        'home' => 'Accueil',
        'new_invoice' => 'Nouvelle facture',
        'my_invoices' => 'Mes factures',
        'timesheet' => 'Feuille de temps',
        'logout' => 'Déconnexion',

        // Feuille de temps
        'add_hours' => 'Ajouter des heures',
        'employee' => 'Employé',
        'project' => 'Projet',
        'select' => '-- Sélectionnez --',
        'date' => 'Date',
        'hours' => 'heures',
        'number_of_hours' => 'Nombre d\'heures',
        'calculate_from_hours' => 'Calculer à partir des heures',
        'arrival' => 'Arrivée',
        'end' => 'Fin',
        'or' => 'OU',
        'description' => 'Description',
        'work_done' => 'Travaux effectués...',
        'save' => 'Enregistrer',
        'cancel' => 'Annuler',
        'delete' => 'Supprimer',
        'edit' => 'Modifier',
        'edit_entry' => 'Modifier l\'entrée',
        'delete_confirm' => 'Supprimer cette entrée ?',
        'my_entries' => 'Mes dernières entrées',
        'all_entries' => 'Dernières entrées (tous employés)',
        'no_entries' => 'Aucune entrée de temps',
        'start_adding' => 'Commencez par ajouter des heures de travail.',
        'status' => 'Statut',
        'amount' => 'Montant',
        'total_hours' => 'Total heures',
        'pending' => 'En attente',
        'approved' => 'Approuvées',
        'total_value' => 'Valeur totale',
        'foreman' => 'Contremaître',
        'your_rate' => 'Votre taux horaire',
        'not_defined' => 'Non défini - Contactez l\'admin',
        'hours_saved' => 'Heures enregistrées avec succès',
        'hours_saved_for' => 'Heures enregistrées pour',
        'entry_modified' => 'Entrée modifiée avec succès.',
        'entry_deleted' => 'Entrée supprimée.',
        'cannot_modify' => 'Impossible de modifier cette entrée.',
        'cannot_delete' => 'Impossible de supprimer cette entrée.',

        // Factures
        'invoices' => 'Factures',
        'new' => 'Nouvelle',
        'supplier' => 'Fournisseur',
        'category' => 'Catégorie',
        'invoice_date' => 'Date de la facture',
        'amount_before_tax' => 'Montant avant taxes',
        'total' => 'TOTAL',
        'submit_invoice' => 'Soumettre la facture',
        'no_invoices' => 'Aucune facture',

        // Général
        'dashboard' => 'Tableau de bord',
        'language' => 'Langue',
        'french' => 'Français',
        'spanish' => 'Español',
    ],

    'es' => [
        // Navigation
        'home' => 'Inicio',
        'new_invoice' => 'Nueva factura',
        'my_invoices' => 'Mis facturas',
        'timesheet' => 'Hoja de tiempo',
        'logout' => 'Cerrar sesión',

        // Feuille de temps
        'add_hours' => 'Agregar horas',
        'employee' => 'Empleado',
        'project' => 'Proyecto',
        'select' => '-- Seleccionar --',
        'date' => 'Fecha',
        'hours' => 'horas',
        'number_of_hours' => 'Número de horas',
        'calculate_from_hours' => 'Calcular desde las horas',
        'arrival' => 'Llegada',
        'end' => 'Fin',
        'or' => 'O',
        'description' => 'Descripción',
        'work_done' => 'Trabajos realizados...',
        'save' => 'Guardar',
        'cancel' => 'Cancelar',
        'delete' => 'Eliminar',
        'edit' => 'Editar',
        'edit_entry' => 'Editar entrada',
        'delete_confirm' => '¿Eliminar esta entrada?',
        'my_entries' => 'Mis últimas entradas',
        'all_entries' => 'Últimas entradas (todos los empleados)',
        'no_entries' => 'Sin entradas de tiempo',
        'start_adding' => 'Comienza agregando horas de trabajo.',
        'status' => 'Estado',
        'amount' => 'Monto',
        'total_hours' => 'Total horas',
        'pending' => 'Pendiente',
        'approved' => 'Aprobadas',
        'total_value' => 'Valor total',
        'foreman' => 'Capataz',
        'your_rate' => 'Tu tarifa por hora',
        'not_defined' => 'No definido - Contacta al admin',
        'hours_saved' => 'Horas guardadas con éxito',
        'hours_saved_for' => 'Horas guardadas para',
        'entry_modified' => 'Entrada modificada con éxito.',
        'entry_deleted' => 'Entrada eliminada.',
        'cannot_modify' => 'No se puede modificar esta entrada.',
        'cannot_delete' => 'No se puede eliminar esta entrada.',

        // Factures
        'invoices' => 'Facturas',
        'new' => 'Nueva',
        'supplier' => 'Proveedor',
        'category' => 'Categoría',
        'invoice_date' => 'Fecha de factura',
        'amount_before_tax' => 'Monto antes de impuestos',
        'total' => 'TOTAL',
        'submit_invoice' => 'Enviar factura',
        'no_invoices' => 'Sin facturas',

        // Général
        'dashboard' => 'Panel de control',
        'language' => 'Idioma',
        'french' => 'Français',
        'spanish' => 'Español',
    ]
];

/**
 * Obtenir la langue actuelle
 */
function getCurrentLanguage() {
    return $_SESSION['lang'] ?? DEFAULT_LANGUAGE;
}

/**
 * Définir la langue
 */
function setLanguage($lang) {
    if (in_array($lang, AVAILABLE_LANGUAGES)) {
        $_SESSION['lang'] = $lang;
        return true;
    }
    return false;
}

/**
 * Traduire une clé
 */
function __($key, $default = null) {
    global $TRANSLATIONS;
    $lang = getCurrentLanguage();

    if (isset($TRANSLATIONS[$lang][$key])) {
        return $TRANSLATIONS[$lang][$key];
    }

    // Fallback au français
    if (isset($TRANSLATIONS['fr'][$key])) {
        return $TRANSLATIONS['fr'][$key];
    }

    return $default ?? $key;
}

/**
 * Afficher une traduction échappée
 */
function _e($key, $default = null) {
    echo e(__($key, $default));
}

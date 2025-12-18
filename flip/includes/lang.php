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
        'departure' => 'Départ',
        'end' => 'Fin',
        'entry' => 'Entrée',
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

        // Page d'accueil employé
        'hello' => 'Bonjour',
        'what_to_do' => 'Que souhaitez-vous faire?',
        'add_invoice' => 'Ajouter une facture',
        'add_hours' => 'Ajouter des heures',
        'my_hours' => 'Mes heures',
        'no_hours_yet' => 'Vous n\'avez pas encore soumis d\'heures.',
        'submitted_invoices' => 'Factures soumises',
        'active_projects' => 'Projets actifs',
        'no_active_projects' => 'Aucun projet actif',
        'no_projects_msg' => 'Il n\'y a pas de projet en cours pour le moment.',
        'my_last_invoices' => 'Mes dernières factures',
        'see_all' => 'Voir tout',
        'no_invoice_yet' => 'Vous n\'avez pas encore soumis de facture.',
        'submit_invoice' => 'Soumettre une facture',
        'total_approved' => 'Total approuvé',

        // Mes factures
        'all_projects' => 'Tous les projets',
        'all_statuses' => 'Tous les statuts',
        'filter' => 'Filtrer',
        'reset' => 'Réinitialiser',
        'invoices_found' => 'facture(s) trouvée(s)',
        'no_match' => 'Aucune facture ne correspond à vos critères.',
        'rejected' => 'Rejetée',
        'refund' => 'Remb.',
        'back' => 'Retour',

        // Nouvelle facture
        'select_project' => 'Sélectionner un projet...',
        'select_supplier' => 'Sélectionner un fournisseur...',
        'other_new_supplier' => '➕ Autre (nouveau fournisseur)',
        'enter_new_supplier' => 'Entrez le nom du nouveau fournisseur...',
        'select_category' => 'Sélectionner une catégorie...',
        'items_description' => 'Description des articles/services',
        'refund_toggle' => 'Remboursement',
        'refund_reduces_cost' => 'réduit le coût du projet',
        'without_taxes' => 'Sans taxes',
        'taxes_auto_calc' => 'Les taxes sont calculées automatiquement. Utilisez "Sans taxes" pour les cas particuliers.',
        'invoice_photo' => 'Photo/PDF de la facture',
        'drag_file' => 'Glisser un fichier ou cliquer pour sélectionner',
        'file_formats' => 'JPG, PNG, PDF - Max 5 MB',
        'notes' => 'Notes',
        'notes_optional' => 'Notes (optionnel)',
        'additional_notes' => 'Notes supplémentaires...',
        'errors' => 'Erreur(s)',

        // Photos
        'take_photos' => 'Prendre des photos',
        'project_photos' => 'Photos de projet',
        'take_project_photos' => 'Prenez des photos de vos projets en cours',
        'add_photos' => 'Ajouter des photos',
        'photos' => 'Photos',
        'take_photo' => 'Prendre une photo',
        'choose_from_gallery' => 'Choisir dans la galerie',
        'upload_photos' => 'Téléverser les photos',
        'photos_uploaded' => 'photo(s) téléversée(s)',
        'no_photos_uploaded' => 'Aucune photo n\'a pu être téléversée.',
        'file_too_large' => 'Le fichier est trop volumineux. Veuillez réduire la taille de la photo.',
        'select_photos' => 'Veuillez sélectionner au moins une photo.',
        'select_project_error' => 'Veuillez sélectionner un projet.',
        'photo_description_placeholder' => 'Description des photos (optionnel)...',
        'current_group' => 'Groupe actuel',
        'new_photo_group' => 'Nouveau groupe de photos',
        'my_photo_groups' => 'Mes groupes de photos',
        'no_photos_yet' => 'Aucune photo',
        'start_taking_photos' => 'Commencez par prendre des photos de vos projets.',
        'photo_deleted' => 'Photo supprimée.',
        'delete_photo_confirm' => 'Supprimer cette photo ?',

        // Catégories de photos
        'photo_category' => 'Catégorie',
        'select_category_photo' => 'Sélectionner une catégorie...',
        'cat_interior_finishing' => 'Finition intérieure',
        'cat_exterior' => 'Extérieur',
        'cat_plumbing' => 'Plomberie',
        'cat_electrical' => 'Électricité',
        'cat_structure' => 'Structure',
        'cat_foundation' => 'Fondation',
        'cat_roofing' => 'Toiture',
        'cat_windows_doors' => 'Fenêtres et portes',
        'cat_painting' => 'Peinture',
        'cat_flooring' => 'Plancher',
        'cat_before_work' => 'Avant travaux',
        'cat_after_work' => 'Après travaux',
        'cat_progress' => 'En cours',
        'cat_other' => 'Autre',
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
        'departure' => 'Salida',
        'end' => 'Fin',
        'entry' => 'Entrada',
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

        // Page d'accueil employé
        'hello' => 'Hola',
        'what_to_do' => '¿Qué quieres hacer?',
        'add_invoice' => 'Agregar una factura',
        'add_hours' => 'Agregar horas',
        'my_hours' => 'Mis horas',
        'no_hours_yet' => 'Aún no has enviado horas.',
        'submitted_invoices' => 'Facturas enviadas',
        'active_projects' => 'Proyectos activos',
        'no_active_projects' => 'Sin proyectos activos',
        'no_projects_msg' => 'No hay proyectos en curso por el momento.',
        'my_last_invoices' => 'Mis últimas facturas',
        'see_all' => 'Ver todo',
        'no_invoice_yet' => 'Aún no has enviado ninguna factura.',
        'submit_invoice' => 'Enviar una factura',
        'total_approved' => 'Total aprobado',

        // Mes factures
        'all_projects' => 'Todos los proyectos',
        'all_statuses' => 'Todos los estados',
        'filter' => 'Filtrar',
        'reset' => 'Reiniciar',
        'invoices_found' => 'factura(s) encontrada(s)',
        'no_match' => 'Ninguna factura corresponde a tus criterios.',
        'rejected' => 'Rechazada',
        'refund' => 'Reemb.',
        'back' => 'Volver',

        // Nouvelle facture
        'select_project' => 'Seleccionar un proyecto...',
        'select_supplier' => 'Seleccionar un proveedor...',
        'other_new_supplier' => '➕ Otro (nuevo proveedor)',
        'enter_new_supplier' => 'Ingresa el nombre del nuevo proveedor...',
        'select_category' => 'Seleccionar una categoría...',
        'items_description' => 'Descripción de artículos/servicios',
        'refund_toggle' => 'Reembolso',
        'refund_reduces_cost' => 'reduce el costo del proyecto',
        'without_taxes' => 'Sin impuestos',
        'taxes_auto_calc' => 'Los impuestos se calculan automáticamente. Usa "Sin impuestos" para casos particulares.',
        'invoice_photo' => 'Foto/PDF de la factura',
        'drag_file' => 'Arrastra un archivo o haz clic para seleccionar',
        'file_formats' => 'JPG, PNG, PDF - Máx 5 MB',
        'notes' => 'Notas',
        'notes_optional' => 'Notas (opcional)',
        'additional_notes' => 'Notas adicionales...',
        'errors' => 'Error(es)',

        // Photos
        'take_photos' => 'Tomar fotos',
        'project_photos' => 'Fotos del proyecto',
        'take_project_photos' => 'Toma fotos de tus proyectos en curso',
        'add_photos' => 'Agregar fotos',
        'photos' => 'Fotos',
        'take_photo' => 'Tomar una foto',
        'choose_from_gallery' => 'Elegir de la galería',
        'upload_photos' => 'Subir fotos',
        'photos_uploaded' => 'foto(s) subida(s)',
        'no_photos_uploaded' => 'No se pudo subir ninguna foto.',
        'file_too_large' => 'El archivo es demasiado grande. Por favor reduce el tamaño de la foto.',
        'select_photos' => 'Por favor selecciona al menos una foto.',
        'select_project_error' => 'Por favor selecciona un proyecto.',
        'photo_description_placeholder' => 'Descripción de las fotos (opcional)...',
        'current_group' => 'Grupo actual',
        'new_photo_group' => 'Nuevo grupo de fotos',
        'my_photo_groups' => 'Mis grupos de fotos',
        'no_photos_yet' => 'Sin fotos',
        'start_taking_photos' => 'Comienza tomando fotos de tus proyectos.',
        'photo_deleted' => 'Foto eliminada.',
        'delete_photo_confirm' => '¿Eliminar esta foto?',

        // Catégories de photos
        'photo_category' => 'Categoría',
        'select_category_photo' => 'Seleccionar una categoría...',
        'cat_interior_finishing' => 'Acabado interior',
        'cat_exterior' => 'Exterior',
        'cat_plumbing' => 'Plomería',
        'cat_electrical' => 'Electricidad',
        'cat_structure' => 'Estructura',
        'cat_foundation' => 'Cimentación',
        'cat_roofing' => 'Techo',
        'cat_windows_doors' => 'Ventanas y puertas',
        'cat_painting' => 'Pintura',
        'cat_flooring' => 'Piso',
        'cat_before_work' => 'Antes del trabajo',
        'cat_after_work' => 'Después del trabajo',
        'cat_progress' => 'En progreso',
        'cat_other' => 'Otro',
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

/**
 * Générer le bouton de changement de langue (pour employés)
 */
function renderLanguageToggle() {
    $currentLang = getCurrentLanguage();
    $otherLang = $currentLang === 'fr' ? 'es' : 'fr';
    $otherLabel = $currentLang === 'fr' ? 'ES' : 'FR';
    $flagIcon = $currentLang === 'fr' ? '🇪🇸' : '🇫🇷';

    return '<a href="' . url('/set-language.php?lang=' . $otherLang) . '"
               class="btn btn-outline-secondary btn-sm"
               title="' . __('language') . '">
               ' . $flagIcon . ' ' . $otherLabel . '
            </a>';
}

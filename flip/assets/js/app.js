/**
 * Flip Manager - JavaScript personnalisé
 */

document.addEventListener('DOMContentLoaded', function() {
    
    // Initialisation des tooltips Bootstrap
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Initialisation Flatpickr pour les dates
    initDatePickers();
    
    // Initialisation des champs monétaires
    initMoneyInputs();
    
    // Gestion de l'upload de fichiers avec drag & drop
    initUploadZone();
    
    // Calcul automatique des taxes
    initTaxCalculation();
    
    // Confirmation de suppression
    initDeleteConfirmation();
    
    // Auto-dismiss des alertes
    initAlertAutoDismiss();
    
});

/**
 * Initialisation des date pickers avec Flatpickr
 */
function initDatePickers() {
    if (typeof flatpickr === 'undefined') return;
    
    // Configuration française
    flatpickr.localize(flatpickr.l10ns.fr);
    
    // Initialiser tous les champs avec classe date-picker ou type date
    const dateFields = document.querySelectorAll('.date-picker, input[type="date"]');
    dateFields.forEach(function(input) {
        flatpickr(input, {
            dateFormat: "Y-m-d",
            altInput: true,
            altFormat: "d/m/Y",
            locale: "fr",
            allowInput: true,
            clickOpens: true
        });
    });
}

/**
 * Initialisation des champs monétaires
 */
function initMoneyInputs() {
    document.querySelectorAll('.money-input').forEach(function(input) {
        // Au focus, sélectionner tout le contenu
        input.addEventListener('focus', function() {
            this.select();
        });
        
        // Nettoyer la valeur à la saisie
        input.addEventListener('input', function() {
            // Garder seulement les chiffres et le point/virgule
            let value = this.value.replace(/[^\d.,]/g, '');
            // Remplacer la virgule par un point
            value = value.replace(',', '.');
            // Garder un seul point décimal
            const parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }
            this.value = value;
        });
        
        // Formater au blur
        input.addEventListener('blur', function() {
            let value = parseFloat(this.value) || 0;
            // Si c'est un nombre entier, ne pas afficher les décimales
            if (value === Math.floor(value) && value > 0) {
                this.value = Math.floor(value);
            } else if (value > 0) {
                this.value = value.toFixed(2);
            } else {
                this.value = '';
            }
        });
    });
}

/**
 * Initialisation de la zone d'upload avec drag & drop
 */
function initUploadZone() {
    const uploadZone = document.querySelector('.upload-zone');
    const fileInput = document.getElementById('fichier');
    
    if (!uploadZone || !fileInput) return;
    
    // Click pour ouvrir le sélecteur de fichiers
    uploadZone.addEventListener('click', function() {
        fileInput.click();
    });
    
    // Drag & Drop
    uploadZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });
    
    uploadZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
    });
    
    uploadZone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            updateFilePreview(e.dataTransfer.files[0]);
        }
    });
    
    // Changement de fichier
    fileInput.addEventListener('change', function() {
        if (this.files.length) {
            updateFilePreview(this.files[0]);
        }
    });
}

/**
 * Met à jour l'aperçu du fichier uploadé
 */
function updateFilePreview(file) {
    const uploadZone = document.querySelector('.upload-zone');
    const fileNameEl = uploadZone.querySelector('.file-name');
    const iconEl = uploadZone.querySelector('i');
    
    if (fileNameEl) {
        fileNameEl.textContent = file.name;
    } else {
        const newFileNameEl = document.createElement('div');
        newFileNameEl.className = 'file-name';
        newFileNameEl.textContent = file.name;
        uploadZone.appendChild(newFileNameEl);
    }
    
    // Changer l'icône selon le type
    if (iconEl) {
        if (file.type === 'application/pdf') {
            iconEl.className = 'bi bi-file-earmark-pdf';
            iconEl.style.color = '#ef4444';
        } else if (file.type.startsWith('image/')) {
            iconEl.className = 'bi bi-file-earmark-image';
            iconEl.style.color = '#22c55e';
        }
    }
    
    // Afficher un aperçu pour les images
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            let preview = uploadZone.querySelector('.preview-img');
            if (!preview) {
                preview = document.createElement('img');
                preview.className = 'preview-img facture-preview mt-2';
                uploadZone.appendChild(preview);
            }
            preview.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }
}

/**
 * Initialisation du calcul automatique des taxes
 */
function initTaxCalculation() {
    const montantInput = document.getElementById('montant_avant_taxes');
    const tpsInput = document.getElementById('tps');
    const tvqInput = document.getElementById('tvq');
    const totalDisplay = document.getElementById('montant_total_display');
    const totalInput = document.getElementById('montant_total');
    
    if (!montantInput) return;
    
    montantInput.addEventListener('input', calculateTaxes);
    
    // Si les champs TPS/TVQ sont modifiables, recalculer le total
    if (tpsInput) {
        tpsInput.addEventListener('input', calculateTotal);
    }
    if (tvqInput) {
        tvqInput.addEventListener('input', calculateTotal);
    }
    
    function calculateTaxes() {
        const montant = parseFloat(montantInput.value) || 0;
        const tps = montant * 0.05;
        const tvq = montant * 0.09975;
        
        if (tpsInput) tpsInput.value = tps.toFixed(2);
        if (tvqInput) tvqInput.value = tvq.toFixed(2);
        
        calculateTotal();
    }
    
    function calculateTotal() {
        const montant = parseFloat(montantInput.value) || 0;
        const tps = parseFloat(tpsInput?.value) || 0;
        const tvq = parseFloat(tvqInput?.value) || 0;
        const total = montant + tps + tvq;
        
        if (totalDisplay) {
            totalDisplay.textContent = formatMoney(total);
        }
        if (totalInput) {
            totalInput.value = total.toFixed(2);
        }
    }
}

/**
 * Formate un montant en devise
 */
function formatMoney(amount) {
    return new Intl.NumberFormat('fr-CA', {
        style: 'currency',
        currency: 'CAD'
    }).format(amount);
}

/**
 * Initialisation des confirmations de suppression
 */
function initDeleteConfirmation() {
    document.querySelectorAll('[data-confirm]').forEach(function(element) {
        element.addEventListener('click', function(e) {
            const message = this.dataset.confirm || 'Êtes-vous sûr de vouloir effectuer cette action?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });
    
    // Formulaires de suppression
    document.querySelectorAll('form[data-confirm-delete]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!confirm('Êtes-vous sûr de vouloir supprimer cet élément? Cette action est irréversible.')) {
                e.preventDefault();
            }
        });
    });
}

/**
 * Auto-dismiss des alertes après 5 secondes
 */
function initAlertAutoDismiss() {
    document.querySelectorAll('.alert-dismissible').forEach(function(alert) {
        setTimeout(function() {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
}

/**
 * Validation du formulaire de facture
 */
function validateFactureForm() {
    const form = document.getElementById('factureForm');
    if (!form) return true;
    
    let isValid = true;
    const errors = [];
    
    // Vérifier les champs requis
    const requiredFields = form.querySelectorAll('[required]');
    requiredFields.forEach(function(field) {
        if (!field.value.trim()) {
            isValid = false;
            field.classList.add('is-invalid');
            errors.push(field.dataset.errorMessage || 'Ce champ est requis');
        } else {
            field.classList.remove('is-invalid');
        }
    });
    
    // Vérifier le montant
    const montant = parseFloat(document.getElementById('montant_avant_taxes')?.value);
    if (isNaN(montant) || montant <= 0) {
        isValid = false;
        document.getElementById('montant_avant_taxes')?.classList.add('is-invalid');
        errors.push('Le montant doit être supérieur à 0');
    }
    
    if (!isValid) {
        alert('Veuillez corriger les erreurs suivantes:\n' + errors.join('\n'));
    }
    
    return isValid;
}

/**
 * Formatage automatique des montants dans les champs
 */
function formatMoneyInput(input) {
    input.addEventListener('blur', function() {
        const value = parseFloat(this.value);
        if (!isNaN(value)) {
            this.value = value.toFixed(2);
        }
    });
}

// Appliquer le formatage aux champs de montant
document.querySelectorAll('input[type="number"][step="0.01"]').forEach(formatMoneyInput);

/**
 * Toggle password visibility
 */
function togglePasswordVisibility(inputId, buttonId) {
    const input = document.getElementById(inputId);
    const button = document.getElementById(buttonId);
    
    if (!input || !button) return;
    
    button.addEventListener('click', function() {
        const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
        input.setAttribute('type', type);
        
        const icon = this.querySelector('i');
        if (icon) {
            icon.className = type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
        }
    });
}

/**
 * Filtrage de table
 */
function initTableFilter() {
    const filterInput = document.getElementById('tableFilter');
    const table = document.querySelector('.table-filterable');
    
    if (!filterInput || !table) return;
    
    filterInput.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(function(row) {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    });
}

/**
 * Sélection de tous les checkboxes
 */
function initSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    if (!selectAllCheckbox) return;
    
    selectAllCheckbox.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.checked = selectAllCheckbox.checked;
        });
    });
}

/**
 * Export vers Excel (simulation)
 */
function exportToExcel(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    // Créer un formulaire pour soumettre la demande d'export
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/admin/rapports/factures-excel.php';
    
    // Ajouter les données si nécessaire
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

/**
 * Impression de la page
 */
function printPage() {
    window.print();
}

/**
 * Gestion de la taille du texte (zoom)
 */
let currentTextSize = parseInt(localStorage.getItem('textSize')) || 100;
const MIN_TEXT_SIZE = 70;
const MAX_TEXT_SIZE = 150;
const TEXT_SIZE_STEP = 10;

// Appliquer la taille sauvegardée au chargement
document.addEventListener('DOMContentLoaded', function() {
    applyTextSize();
    updateTextSizeIndicator();
});

function changeTextSize(direction) {
    currentTextSize += direction * TEXT_SIZE_STEP;
    
    // Limiter entre min et max
    if (currentTextSize < MIN_TEXT_SIZE) currentTextSize = MIN_TEXT_SIZE;
    if (currentTextSize > MAX_TEXT_SIZE) currentTextSize = MAX_TEXT_SIZE;
    
    applyTextSize();
    updateTextSizeIndicator();
    
    // Sauvegarder la préférence
    localStorage.setItem('textSize', currentTextSize);
}

function applyTextSize() {
    document.documentElement.style.fontSize = currentTextSize + '%';
}

function updateTextSizeIndicator() {
    // Mettre à jour les deux indicateurs (navbar et menu déroulant)
    const indicator = document.getElementById('textSizeIndicator');
    const indicator2 = document.getElementById('textSizeIndicator2');
    
    if (indicator) {
        indicator.textContent = currentTextSize + '%';
    }
    if (indicator2) {
        indicator2.textContent = currentTextSize + '%';
    }
}

function resetTextSize() {
    currentTextSize = 100;
    applyTextSize();
    updateTextSizeIndicator();
    localStorage.setItem('textSize', currentTextSize);
}

/**
 * =============================================
 * DARK MODE
 * =============================================
 */

// Appliquer le dark mode sauvegardé au chargement (avant DOMContentLoaded pour éviter le flash)
(function() {
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
})();

// Met à jour l'icône du bouton
document.addEventListener('DOMContentLoaded', function() {
    updateDarkModeIcon();
});

/**
 * Bascule entre mode clair et sombre
 */
function toggleDarkMode() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    
    updateDarkModeIcon();
}

/**
 * Met à jour l'icône du bouton dark mode
 */
function updateDarkModeIcon() {
    const icon = document.getElementById('darkModeIcon');
    const btn = document.getElementById('darkModeBtn');
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    
    if (icon) {
        icon.className = isDark ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
    }
    
    if (btn) {
        btn.title = isDark ? 'Passer en mode clair' : 'Passer en mode sombre';
    }
}

/**
 * Définit explicitement un thème
 */
function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
    updateDarkModeIcon();
}

/**
 * Vérifie si le mode sombre est actif
 */
function isDarkMode() {
    return document.documentElement.getAttribute('data-theme') === 'dark';
}

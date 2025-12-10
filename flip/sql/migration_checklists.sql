-- Migration: Checklists et Documents
-- Date: 2024

-- Templates de checklist (gérés par admin)
CREATE TABLE IF NOT EXISTS checklist_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(255) NOT NULL,
    description TEXT,
    ordre INT DEFAULT 0,
    actif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Items de checklist template
CREATE TABLE IF NOT EXISTS checklist_template_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    nom VARCHAR(255) NOT NULL,
    description TEXT,
    ordre INT DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES checklist_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Checklists par projet (instances des templates)
CREATE TABLE IF NOT EXISTS projet_checklists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    template_item_id INT NOT NULL,
    complete TINYINT(1) DEFAULT 0,
    complete_date DATETIME NULL,
    complete_by VARCHAR(100) NULL,
    notes TEXT,
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE,
    FOREIGN KEY (template_item_id) REFERENCES checklist_template_items(id) ON DELETE CASCADE,
    UNIQUE KEY unique_projet_item (projet_id, template_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Documents uploadés par projet
CREATE TABLE IF NOT EXISTS projet_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projet_id INT NOT NULL,
    nom VARCHAR(255) NOT NULL,
    fichier VARCHAR(500) NOT NULL,
    type VARCHAR(100),
    taille INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (projet_id) REFERENCES projets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

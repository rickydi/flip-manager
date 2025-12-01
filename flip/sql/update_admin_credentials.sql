-- Mise à jour des identifiants admin
-- Email: erik.deschenes@gmail.com
-- Mot de passe: zun+668

-- Hash généré avec password_hash('zun+668', PASSWORD_DEFAULT)
UPDATE users 
SET email = 'erik.deschenes@gmail.com',
    password = '$2y$10$K8Xq3.6z5Y1Yw9dmJk0QE.fQ/LxGJ8vYMbGhQjkDEMpVEaV8rCgVu'
WHERE role = 'admin'
LIMIT 1;

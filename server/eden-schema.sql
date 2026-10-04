-- Inscriptions aux cours de mariage Eden.
-- À importer une seule fois dans la base MySQL (phpMyAdmin > Importer).
-- Les noms de colonnes sont en français pour être lisibles directement
-- par l'équipe Eden dans phpMyAdmin.

CREATE TABLE IF NOT EXISTS eden_inscriptions (
  id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference                   VARCHAR(20)  NULL,
  submission_id               CHAR(36)     NOT NULL,
  cree_le                     DATETIME     NOT NULL,

  repondant_email             VARCHAR(254) NOT NULL,
  repondant_nom               VARCHAR(120) NOT NULL,
  repondant_date_naissance    DATE         NOT NULL,
  repondant_nationalite       VARCHAR(80)  NOT NULL,
  repondant_telephone         VARCHAR(20)  NOT NULL,
  repondant_profession        VARCHAR(100) NOT NULL,
  membre_evh                  TINYINT(1)   NOT NULL,
  congregation                VARCHAR(150) NULL,
  baptise_immersion           TINYINT(1)   NOT NULL,
  relation_precedente         TINYINT(1)   NOT NULL,
  deja_marie                  TINYINT(1)   NOT NULL,

  duree_valeur                SMALLINT UNSIGNED NOT NULL,
  duree_unite                 VARCHAR(10)  NOT NULL,

  cheminant_nom               VARCHAR(120) NOT NULL,
  cheminant_date_naissance    DATE         NOT NULL,
  cheminant_nationalite       VARCHAR(80)  NOT NULL,
  cheminant_telephone         VARCHAR(20)  NOT NULL,
  cheminant_profession        VARCHAR(100) NOT NULL,
  cheminant_pays              VARCHAR(80)  NOT NULL,
  cheminant_ville             VARCHAR(80)  NOT NULL,
  cheminant_eglise            VARCHAR(150) NOT NULL,

  bloquants                   TINYINT(1)   NOT NULL,
  pret_classes                TINYINT(1)   NOT NULL,

  frais_confirmes             TINYINT(1)   NOT NULL,
  frais_confirmes_le          DATETIME     NOT NULL,

  -- Photos en attente d'envoi : elles partent en pièces jointes du courriel
  -- à l'équipe, puis sont supprimées du serveur (colonnes remises à NULL).
  photo_repondant             VARCHAR(100) NULL,
  photo_cheminant             VARCHAR(100) NULL,

  -- Le paiement est vérifié à la main par l'équipe (capture WhatsApp).
  paiement_statut             VARCHAR(20)  NOT NULL DEFAULT 'en_attente',

  courriel_equipe_statut      VARCHAR(12)  NOT NULL DEFAULT 'en_attente',
  courriel_candidat_statut    VARCHAR(12)  NOT NULL DEFAULT 'en_attente',
  courriel_erreur             VARCHAR(255) NULL,
  courriel_tentatives         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  courriel_derniere_tentative DATETIME     NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_eden_reference (reference),
  UNIQUE KEY uq_eden_submission (submission_id),
  KEY idx_eden_cree_le (cree_le),
  KEY idx_eden_courriels (courriel_equipe_statut, courriel_candidat_statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

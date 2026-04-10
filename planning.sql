-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : ven. 03 avr. 2026 à 14:28
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `planning`
--

-- --------------------------------------------------------

--
-- Structure de la table `agent_quotas`
--

CREATE TABLE `agent_quotas` (
  `id` int(11) NOT NULL,
  `agent` varchar(80) NOT NULL,
  `annee` int(11) NOT NULL,
  `type_conge` varchar(10) NOT NULL,
  `quota` decimal(6,2) NOT NULL DEFAULT 0.00,
  `unite` enum('jours','heures') NOT NULL DEFAULT 'jours'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `agent_quotas`
--

INSERT INTO `agent_quotas` (`id`, `agent`, `annee`, `type_conge`, `quota`, `unite`) VALUES
(1, 'CoGe ROUSSEL', 2026, 'CA', 25.00, 'jours'),
(2, 'CoGe ROUSSEL', 2026, 'HP', 2.00, 'jours'),
(3, 'CoGe ROUSSEL', 2026, 'RTT', 16.00, 'jours'),
(4, 'Cne MOKADEM', 2026, 'CA', 23.00, 'jours'),
(5, 'Cne MOKADEM', 2026, 'HP', 2.00, 'jours'),
(6, 'Cne MOKADEM', 2026, 'RTT', 19.00, 'jours'),
(7, 'BC MASSON', 2026, 'CA', 23.00, 'jours'),
(8, 'BC MASSON', 2026, 'HP', 2.00, 'jours'),
(9, 'BC MASSON', 2026, 'RTC', 41.75, 'heures'),
(10, 'BC MASSON', 2026, 'CF', 109.20, 'heures'),
(11, 'BC SIGAUD', 2026, 'CA', 23.00, 'jours'),
(12, 'BC SIGAUD', 2026, 'HP', 2.00, 'jours'),
(13, 'BC SIGAUD', 2026, 'RTC', 41.75, 'heures'),
(14, 'BC SIGAUD', 2026, 'CF', 109.20, 'heures'),
(15, 'BC DAINOTTI', 2026, 'CA', 23.00, 'jours'),
(16, 'BC DAINOTTI', 2026, 'HP', 2.00, 'jours'),
(17, 'BC DAINOTTI', 2026, 'RTC', 41.75, 'heures'),
(18, 'BC DAINOTTI', 2026, 'CF', 109.20, 'heures'),
(19, 'BC BOUXOM', 2026, 'CA', 25.00, 'jours'),
(20, 'BC BOUXOM', 2026, 'HP', 2.00, 'jours'),
(21, 'BC BOUXOM', 2026, 'RTT', 16.00, 'jours'),
(22, 'BC ARNAULT', 2026, 'CA', 25.00, 'jours'),
(23, 'BC ARNAULT', 2026, 'HP', 2.00, 'jours'),
(24, 'BC ARNAULT', 2026, 'RTT', 16.00, 'jours'),
(25, 'BC HOCHARD', 2026, 'CA', 25.00, 'jours'),
(26, 'BC HOCHARD', 2026, 'HP', 2.00, 'jours'),
(27, 'BC HOCHARD', 2026, 'RTT', 16.00, 'jours'),
(28, 'BC DUPUIS', 2026, 'CA', 25.00, 'jours'),
(29, 'BC DUPUIS', 2026, 'HP', 2.00, 'jours'),
(30, 'BC DUPUIS', 2026, 'RTT', 16.00, 'jours'),
(31, 'BC BASTIEN', 2026, 'CA', 25.00, 'jours'),
(32, 'BC BASTIEN', 2026, 'HP', 2.00, 'jours'),
(33, 'BC BASTIEN', 2026, 'RTT', 16.00, 'jours'),
(34, 'BC ANTHONY', 2026, 'CA', 25.00, 'jours'),
(35, 'BC ANTHONY', 2026, 'HP', 2.00, 'jours'),
(36, 'BC ANTHONY', 2026, 'RTT', 16.00, 'jours'),
(37, 'GP DHALLEWYN', 2026, 'CA', 25.00, 'jours'),
(38, 'GP DHALLEWYN', 2026, 'HP', 2.00, 'jours'),
(39, 'GP DHALLEWYN', 2026, 'RTT', 16.00, 'jours'),
(40, 'BC DELCROIX', 2026, 'CA', 25.00, 'jours'),
(41, 'BC DELCROIX', 2026, 'HP', 2.00, 'jours'),
(42, 'BC DELCROIX', 2026, 'RTT', 16.00, 'jours'),
(43, 'AA MAES', 2026, 'CA', 25.00, 'jours'),
(44, 'AA MAES', 2026, 'HP', 2.00, 'jours'),
(45, 'AA MAES', 2026, 'RTT', 29.00, 'jours'),
(46, 'BC DRUEZ', 2026, 'CA', 25.00, 'jours'),
(47, 'BC DRUEZ', 2026, 'HP', 2.00, 'jours'),
(48, 'BC DRUEZ', 2026, 'RTT', 16.00, 'jours');

-- --------------------------------------------------------

--
-- Structure de la table `app_meta`
--

CREATE TABLE `app_meta` (
  `cle` varchar(100) NOT NULL,
  `valeur` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `app_meta`
--

INSERT INTO `app_meta` (`cle`, `valeur`, `created_at`) VALUES
('add_conges_maladie_v1', 'done', '2026-03-19 09:46:28'),
('add_locks_mois_v1', 'done', '2026-03-23 10:08:54'),
('add_locks_mois_v2', 'done', '2026-03-23 10:21:31'),
('add_type_aut_v1', 'done', '2026-03-23 09:07:50'),
('add_type_prev_v1', 'done', '2026-03-23 08:04:46'),
('alter_permanences_type_v1', 'done', '2026-03-19 08:54:17'),
('create_agent_quotas_v1', 'done', '2026-03-19 10:43:44'),
('create_agent_quotas_v2', 'done', '2026-03-20 08:02:39'),
('create_user_rights_v1', 'done', '2026-03-19 08:54:17'),
('create_vacation_overrides_v1', 'done', '2026-03-20 08:22:45'),
('update_couleurs_conges_v1', 'done', '2026-03-23 08:27:46');

-- --------------------------------------------------------

--
-- Structure de la table `conges`
--

CREATE TABLE `conges` (
  `id` int(11) NOT NULL,
  `agent` varchar(100) NOT NULL,
  `agent_nom` varchar(100) NOT NULL,
  `date_debut` varchar(10) NOT NULL,
  `date_fin` varchar(10) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'CP',
  `demi_jour` enum('AM','PM','NONE') DEFAULT 'NONE',
  `type_conge` varchar(10) NOT NULL,
  `periode` enum('J','M','AM') DEFAULT 'J',
  `heure` varchar(5) DEFAULT NULL COMMENT 'Heure pour DA/PR (format HH:MM)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `conges`
--

INSERT INTO `conges` (`id`, `agent`, `agent_nom`, `date_debut`, `date_fin`, `type`, `demi_jour`, `type_conge`, `periode`, `heure`) VALUES
(165, 'BC BOUXOM', 'BC BOUXOM', '2027-01-15', '2027-01-15', 'PR', 'NONE', 'PR', 'J', '15:00'),
(166, 'BC BOUXOM', 'BC BOUXOM', '2027-01-12', '2027-01-12', 'DA', 'NONE', 'DA', 'J', '20:00'),
(167, 'BC MASSON', 'BC MASSON', '2027-01-20', '2027-01-20', 'RPS', 'NONE', 'RPS', 'J', NULL),
(168, 'BC SIGAUD', 'BC SIGAUD', '2027-01-21', '2027-01-21', 'CF', 'NONE', 'CF', 'J', NULL),
(169, 'BC DAINOTTI', 'BC DAINOTTI', '2027-01-19', '2027-01-19', 'CET', 'NONE', 'CET', 'J', NULL),
(172, 'ADC LAMBERT', 'ADC LAMBERT', '2027-01-04', '2027-01-04', 'R', 'NONE', 'R', 'J', NULL),
(173, 'LCL PARENT', 'LCL PARENT', '2027-01-04', '2027-01-04', 'P', 'NONE', 'P', 'AM', NULL),
(194, 'BC BOUXOM', 'BC BOUXOM', '2026-01-21', '2026-01-21', 'CA', 'NONE', 'CA', 'J', NULL),
(195, 'LCL PARENT', 'LCL PARENT', '2026-01-02', '2026-01-02', 'P', 'NONE', 'P', 'J', NULL),
(196, 'LCL PARENT', 'LCL PARENT', '2026-01-05', '2026-01-09', 'P', 'NONE', 'P', 'J', NULL),
(197, 'LCL PARENT', 'LCL PARENT', '2026-01-20', '2026-01-20', 'P', 'NONE', 'P', 'AM', NULL),
(198, 'LCL PARENT', 'LCL PARENT', '2026-01-22', '2026-01-22', 'P', 'NONE', 'P', 'J', NULL),
(199, 'LCL PARENT', 'LCL PARENT', '2026-01-30', '2026-01-30', 'P', 'NONE', 'P', 'M', NULL),
(200, 'BC ARNAULT', 'BC ARNAULT', '2026-01-20', '2026-01-20', 'CA', 'NONE', 'CA', 'J', NULL),
(201, 'BC HOCHARD', 'BC HOCHARD', '2026-01-19', '2026-01-19', 'CA', 'NONE', 'CA', 'J', NULL),
(202, 'BC HOCHARD', 'BC HOCHARD', '2026-01-05', '2026-01-05', 'CA', 'NONE', 'CA', 'J', NULL),
(203, 'BC ARNAULT', 'BC ARNAULT', '2026-01-07', '2026-01-07', 'CA', 'NONE', 'CA', 'J', NULL),
(204, 'BC HOCHARD', 'BC HOCHARD', '2026-01-07', '2026-01-07', 'CA', 'NONE', 'CA', 'J', NULL),
(205, 'BC DUPUIS', 'BC DUPUIS', '2026-01-13', '2026-01-13', 'CA', 'NONE', 'CA', 'J', NULL),
(206, 'BC DUPUIS', 'BC DUPUIS', '2026-01-15', '2026-01-15', 'CA', 'NONE', 'CA', 'J', NULL),
(207, 'BC BASTIEN', 'BC BASTIEN', '2026-01-14', '2026-01-14', 'ASA', 'NONE', 'ASA', 'J', NULL),
(208, 'BC ANTHONY', 'BC ANTHONY', '2026-01-02', '2026-01-02', 'HPA', 'NONE', 'HPA', 'J', NULL),
(209, 'BC ANTHONY', 'BC ANTHONY', '2026-01-05', '2026-01-05', 'HPA', 'NONE', 'HPA', 'J', NULL),
(210, 'BC ANTHONY', 'BC ANTHONY', '2026-01-06', '2026-01-16', 'CA', 'NONE', 'CA', 'J', NULL),
(211, 'Cne MOKADEM', 'Cne MOKADEM', '2026-01-05', '2026-01-09', 'CA', 'NONE', 'CA', 'J', NULL),
(212, 'BC BASTIEN', 'BC BASTIEN', '2026-01-29', '2026-01-29', 'RTT', 'NONE', 'RTT', 'J', NULL),
(214, 'BC MASSON', 'BC MASSON', '2026-01-06', '2026-01-07', 'CF', 'NONE', 'CF', 'J', NULL),
(215, 'BC DUPUIS', 'BC DUPUIS', '2026-01-22', '2026-01-22', 'CA', 'NONE', 'CA', 'J', NULL),
(216, 'BC BASTIEN', 'BC BASTIEN', '2026-01-21', '2026-01-21', 'CA', 'NONE', 'CA', 'J', NULL),
(217, 'ACP1 LOIN', 'ACP1 LOIN', '2026-01-02', '2026-01-02', 'NC', 'NONE', 'NC', 'J', NULL),
(218, 'ACP1 LOIN', 'ACP1 LOIN', '2026-01-12', '2026-01-12', 'NC', 'NONE', 'NC', 'J', NULL),
(219, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-02', '2026-01-02', 'J', 'NONE', 'J', 'J', NULL),
(220, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-01-02', '2026-01-02', 'P', 'NONE', 'P', 'J', NULL),
(221, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-05', '2026-01-05', 'R', 'NONE', 'R', 'J', NULL),
(222, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-06', '2026-01-08', 'P', 'NONE', 'P', 'J', NULL),
(223, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-09', '2026-01-09', 'J', 'NONE', 'J', 'J', NULL),
(224, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-12', '2026-01-13', 'P', 'NONE', 'P', 'J', NULL),
(225, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-14', '2026-01-15', 'R', 'NONE', 'R', 'J', NULL),
(226, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-16', '2026-01-16', 'J', 'NONE', 'J', 'J', NULL),
(227, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-19', '2026-01-19', 'P', 'NONE', 'P', 'J', NULL),
(228, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-20', '2026-01-22', 'M', 'NONE', 'M', 'J', NULL),
(229, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-23', '2026-01-23', 'J', 'NONE', 'J', 'J', NULL),
(230, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-26', '2026-01-26', 'P', 'NONE', 'P', 'J', NULL),
(231, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-27', '2026-01-29', 'AM', 'NONE', 'AM', 'J', NULL),
(232, 'ADJ CORRARD', 'ADJ CORRARD', '2026-01-30', '2026-01-30', 'P', 'NONE', 'P', 'J', NULL),
(233, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-01-05', '2026-01-08', 'J', 'NONE', 'J', 'J', NULL),
(234, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-01-09', '2026-01-09', 'R', 'NONE', 'R', 'J', NULL),
(235, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-01-12', '2026-01-15', 'J', 'NONE', 'J', 'J', NULL),
(236, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-01-16', '2026-01-16', 'P', 'NONE', 'R', 'J', NULL),
(237, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-01-19', '2026-01-19', 'J', 'NONE', 'J', 'J', NULL),
(238, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-01-20', '2026-01-22', 'AM', 'NONE', 'AM', 'J', NULL),
(239, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-01-23', '2026-01-23', 'P', 'NONE', 'P', 'J', NULL),
(240, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-01-26', '2026-01-26', 'J', 'NONE', 'J', 'J', NULL),
(241, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-01-27', '2026-01-29', 'M', 'NONE', 'M', 'J', NULL),
(242, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-01-30', '2026-01-30', 'J', 'NONE', 'J', 'J', NULL),
(243, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-01-02', '2026-01-02', 'HS', 'NONE', 'HS', 'J', NULL),
(244, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-01-23', '2026-01-23', 'HS', 'NONE', 'HS', 'J', NULL),
(245, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-01-30', '2026-01-30', 'HS', 'NONE', 'HS', 'J', NULL),
(246, 'BC DELCROIX', 'BC DELCROIX', '2026-01-02', '2026-01-02', 'HS', 'NONE', 'HS', 'J', NULL),
(247, 'BC DELCROIX', 'BC DELCROIX', '2026-01-05', '2026-01-05', 'RTT', 'NONE', 'RTT', 'J', NULL),
(248, 'BC DELCROIX', 'BC DELCROIX', '2026-01-07', '2026-01-07', 'RTT', 'NONE', 'RTT', 'J', NULL),
(249, 'BC DELCROIX', 'BC DELCROIX', '2026-01-21', '2026-01-21', 'HS', 'NONE', 'HS', 'J', NULL),
(250, 'BC DELCROIX', 'BC DELCROIX', '2026-01-27', '2026-01-30', 'CMO', 'NONE', 'CMO', 'J', NULL),
(251, 'AA MAES', 'AA MAES', '2026-01-02', '2026-01-09', 'CA', 'NONE', 'CA', 'J', NULL),
(252, 'BC DRUEZ', 'BC DRUEZ', '2026-01-02', '2026-01-02', 'HS', 'NONE', 'HS', 'J', NULL),
(253, 'AA MAES', 'AA MAES', '2026-01-23', '2026-01-23', 'CA', 'NONE', 'CA', 'J', NULL),
(254, 'ACP1 DEMERVAL', 'ACP1 DEMERVAL', '2026-01-02', '2026-01-02', 'CA', 'NONE', 'CA', 'J', NULL),
(255, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-01-05', '2026-01-05', 'DA', 'NONE', 'DA', 'J', '15:30'),
(256, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-01-07', '2026-01-07', 'HS', 'NONE', 'HS', 'AM', NULL),
(257, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-01-28', '2026-01-28', 'DA', 'NONE', 'DA', 'J', '15:30'),
(258, 'BC DELCROIX', 'BC DELCROIX', '2026-01-09', '2026-01-09', 'HS', 'NONE', 'HS', 'AM', NULL),
(259, 'BC DELCROIX', 'BC DELCROIX', '2026-01-14', '2026-01-14', 'DA', 'NONE', 'DA', 'J', '15:00'),
(260, 'BC DRUEZ', 'BC DRUEZ', '2026-01-13', '2026-01-13', 'VEM', 'NONE', 'VEM', 'AM', NULL),
(261, 'BC DRUEZ', 'BC DRUEZ', '2026-01-26', '2026-01-26', 'DA', 'NONE', 'DA', 'J', '16:45'),
(262, 'BC DRUEZ', 'BC DRUEZ', '2026-01-28', '2026-01-28', 'DA', 'NONE', 'DA', 'J', '16:15'),
(263, 'BC DRUEZ', 'BC DRUEZ', '2026-01-29', '2026-01-29', 'DA', 'NONE', 'DA', 'J', '16:15'),
(272, 'Cne MOKADEM', 'Cne MOKADEM', '2026-01-13', '2026-02-12', 'AUT', 'NONE', 'AUT', 'J', NULL),
(273, 'LCL PARENT', 'LCL PARENT', '2026-02-02', '2026-02-02', 'P', 'NONE', 'P', 'M', NULL),
(274, 'LCL PARENT', 'LCL PARENT', '2026-02-11', '2026-02-11', 'P', 'NONE', 'P', 'M', NULL),
(275, 'LCL PARENT', 'LCL PARENT', '2026-02-12', '2026-02-12', 'P', 'NONE', 'P', 'J', NULL),
(276, 'LCL PARENT', 'LCL PARENT', '2026-02-17', '2026-02-19', 'P', 'NONE', 'P', 'J', NULL),
(277, 'CoGe ROUSSEL', 'CoGe ROUSSEL', '2026-02-23', '2026-02-27', 'CA', 'NONE', 'CA', 'J', NULL),
(278, 'IR MOREAU', 'IR MOREAU', '2026-02-02', '2026-02-02', 'CA', 'NONE', 'CA', 'J', NULL),
(279, 'BC MASSON', 'BC MASSON', '2026-02-17', '2026-02-20', 'CA', 'NONE', 'CA', '', NULL),
(280, 'BC SIGAUD', 'BC SIGAUD', '2026-02-01', '2026-02-01', 'CF', 'NONE', 'CF', '', NULL),
(281, 'BC SIGAUD', 'BC SIGAUD', '2026-02-27', '2026-02-28', 'CA', 'NONE', 'CA', '', NULL),
(282, 'BC DAINOTTI', 'BC DAINOTTI', '2026-02-15', '2026-02-16', 'RPS', 'NONE', 'RPS', '', NULL),
(283, 'BC DAINOTTI', 'BC DAINOTTI', '2026-02-21', '2026-02-22', 'CA', 'NONE', 'CA', '', NULL),
(284, 'BC BOUXOM', 'BC BOUXOM', '2026-02-04', '2026-02-04', 'RTT', 'NONE', 'RTT', 'J', NULL),
(285, 'BC BOUXOM', 'BC BOUXOM', '2026-02-23', '2026-02-27', 'CA', 'NONE', 'CA', 'J', NULL),
(286, 'BC ARNAULT', 'BC ARNAULT', '2026-02-20', '2026-02-20', 'HS', 'NONE', 'HS', 'J', NULL),
(287, 'BC HOCHARD', 'BC HOCHARD', '2026-02-02', '2026-02-02', 'CA', 'NONE', 'CA', 'J', NULL),
(288, 'BC DUPUIS', 'BC DUPUIS', '2026-02-06', '2026-02-06', 'HS', 'NONE', 'HS', 'J', NULL),
(289, 'BC DUPUIS', 'BC DUPUIS', '2026-02-16', '2026-02-19', 'CA', 'NONE', 'CA', 'J', NULL),
(290, 'BC DUPUIS', 'BC DUPUIS', '2026-02-20', '2026-02-20', 'HS', 'NONE', 'HS', 'J', NULL),
(291, 'BC DUPUIS', 'BC DUPUIS', '2026-02-23', '2026-02-23', 'CA', 'NONE', 'CA', 'J', NULL),
(292, 'BC BASTIEN', 'BC BASTIEN', '2026-02-13', '2026-02-13', 'HS', 'NONE', 'HS', 'J', NULL),
(293, 'BC BASTIEN', 'BC BASTIEN', '2026-02-11', '2026-02-11', 'CMO', 'NONE', 'VEM', 'M', NULL),
(294, 'BC ANTHONY', 'BC ANTHONY', '2026-02-10', '2026-02-10', 'HS', 'NONE', 'HS', 'J', NULL),
(295, 'BC ANTHONY', 'BC ANTHONY', '2026-02-13', '2026-02-13', 'HS', 'NONE', 'HS', 'J', NULL),
(296, 'BC ANTHONY', 'BC ANTHONY', '2026-02-16', '2026-02-16', 'HS', 'NONE', 'HS', 'J', NULL),
(297, 'BC ANTHONY', 'BC ANTHONY', '2026-02-23', '2026-02-25', 'HS', 'NONE', 'HS', 'J', NULL),
(298, 'BC ANTHONY', 'BC ANTHONY', '2026-02-26', '2026-02-26', 'DA', 'NONE', 'DA', 'J', '20:00'),
(299, 'ADJ CORRARD', 'ADJ CORRARD', '2026-02-02', '2026-02-02', 'P', 'NONE', 'P', 'J', NULL),
(300, 'ADJ CORRARD', 'ADJ CORRARD', '2026-02-03', '2026-02-03', 'P', 'NONE', 'P', 'J', NULL),
(301, 'ADJ CORRARD', 'ADJ CORRARD', '2026-02-05', '2026-02-05', 'R', 'NONE', 'R', 'J', NULL),
(302, 'ADJ CORRARD', 'ADJ CORRARD', '2026-02-06', '2026-02-06', 'J', 'NONE', 'J', 'J', NULL),
(303, 'ADJ CORRARD', 'ADJ CORRARD', '2026-02-12', '2026-02-12', 'P', 'NONE', 'P', 'J', NULL),
(304, 'ADJ CORRARD', 'ADJ CORRARD', '2026-02-13', '2026-02-13', 'R', 'NONE', 'R', 'J', NULL),
(305, 'ADJ CORRARD', 'ADJ CORRARD', '2026-02-16', '2026-02-16', 'P', 'NONE', 'P', 'J', NULL),
(306, 'ADJ CORRARD', 'ADJ CORRARD', '2026-02-20', '2026-02-20', 'P', 'NONE', 'P', 'J', NULL),
(307, 'ADJ CORRARD', 'ADJ CORRARD', '2026-02-23', '2026-02-27', 'J', 'NONE', 'J', 'J', NULL),
(308, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-02-02', '2026-02-03', 'J', 'NONE', 'J', 'J', NULL),
(309, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-02-05', '2026-02-05', 'J', 'NONE', 'J', 'J', NULL),
(310, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-02-06', '2026-02-06', 'R', 'NONE', 'R', 'J', NULL),
(311, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-02-09', '2026-02-09', 'R', 'NONE', 'R', 'J', NULL),
(312, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-02-12', '2026-02-13', 'J', 'NONE', 'J', 'J', NULL),
(313, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-02-16', '2026-02-16', 'J', 'NONE', 'J', 'J', NULL),
(314, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-02-20', '2026-02-20', 'J', 'NONE', 'J', 'J', NULL),
(315, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-02-23', '2026-02-27', 'P', 'NONE', 'P', 'J', NULL),
(316, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-02-09', '2026-02-10', 'ASA', 'NONE', 'ASA', 'J', NULL),
(317, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-02-12', '2026-02-12', 'HS', 'NONE', 'HS', 'J', NULL),
(318, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-02-16', '2026-03-01', 'CA', 'NONE', 'CA', 'J', NULL),
(319, 'BC DELCROIX', 'BC DELCROIX', '2026-02-04', '2026-02-04', 'DA', 'NONE', 'DA', 'J', '15:00'),
(320, 'BC DELCROIX', 'BC DELCROIX', '2026-02-10', '2026-02-10', 'DA', 'NONE', 'DA', 'J', '16:00'),
(321, 'BC DELCROIX', 'BC DELCROIX', '2026-02-16', '2026-02-20', 'CA', 'NONE', 'CA', 'J', NULL),
(322, 'ACP1 DEMERVAL', 'ACP1 DEMERVAL', '2026-02-16', '2026-02-20', 'CA', 'NONE', 'CA', 'J', NULL),
(323, 'ACP1 DEMERVAL', 'ACP1 DEMERVAL', '2026-02-23', '2026-02-27', 'NC', 'NONE', 'NC', 'J', NULL),
(324, 'ACP1 LOIN', 'ACP1 LOIN', '2026-02-09', '2026-02-09', 'RH', 'NONE', 'RH', 'J', NULL),
(325, 'ACP1 LOIN', 'ACP1 LOIN', '2026-02-10', '2026-02-10', 'RH', 'NONE', 'RH', 'J', NULL),
(326, 'ACP1 LOIN', 'ACP1 LOIN', '2026-02-11', '2026-02-11', 'NC', 'NONE', 'NC', 'J', NULL),
(327, 'AA MAES', 'AA MAES', '2026-02-02', '2026-02-02', 'RTT', 'NONE', 'RTT', 'J', NULL),
(328, 'AA MAES', 'AA MAES', '2026-02-13', '2026-02-13', 'CA', 'NONE', 'CA', 'J', NULL),
(329, 'AA MAES', 'AA MAES', '2026-02-16', '2026-02-16', 'RTT', 'NONE', 'RTT', 'J', NULL),
(330, 'AA MAES', 'AA MAES', '2026-02-20', '2026-02-20', 'RTT', 'NONE', 'RTT', 'J', NULL),
(331, 'BC DRUEZ', 'BC DRUEZ', '2026-02-06', '2026-02-06', 'HS', 'NONE', 'HS', 'J', NULL),
(332, 'BC DRUEZ', 'BC DRUEZ', '2026-02-11', '2026-02-11', 'DA', 'NONE', 'DA', 'J', '15:45'),
(333, 'BC DRUEZ', 'BC DRUEZ', '2026-02-16', '2026-02-16', 'DA', 'NONE', 'DA', 'J', '16:15'),
(334, 'BC DRUEZ', 'BC DRUEZ', '2026-02-23', '2026-02-26', 'CA', 'NONE', 'CA', 'J', NULL),
(335, 'BC DRUEZ', 'BC DRUEZ', '2026-02-27', '2026-02-27', 'HS', 'NONE', 'HS', 'J', NULL),
(336, 'LCL PARENT', 'LCL PARENT', '2026-03-02', '2026-03-06', 'P', 'NONE', 'P', 'J', NULL),
(337, 'BC SIGAUD', 'BC SIGAUD', '2026-03-21', '2026-03-22', 'CF', 'NONE', 'CF', '', NULL),
(338, 'BC DAINOTTI', 'BC DAINOTTI', '2026-03-06', '2026-03-06', 'CF', 'NONE', 'CF', '', NULL),
(339, 'BC DAINOTTI', 'BC DAINOTTI', '2026-03-07', '2026-03-08', 'CA', 'NONE', 'CA', '', NULL),
(340, 'IR MOREAU', 'IR MOREAU', '2026-03-06', '2026-03-06', 'CA', 'NONE', 'CA', 'J', NULL),
(341, 'IR MOREAU', 'IR MOREAU', '2026-03-09', '2026-03-13', 'CA', 'NONE', 'CA', 'J', NULL),
(342, 'BC BOUXOM', 'BC BOUXOM', '2026-03-04', '2026-03-04', 'RTT', 'NONE', 'RTT', 'J', NULL),
(343, 'BC BOUXOM', 'BC BOUXOM', '2026-03-18', '2026-03-18', 'RTT', 'NONE', 'RTT', 'J', NULL),
(344, 'BC BOUXOM', 'BC BOUXOM', '2026-03-20', '2026-03-20', 'RTT', 'NONE', 'RTT', 'J', NULL),
(345, 'BC ARNAULT', 'BC ARNAULT', '2026-03-03', '2026-03-03', 'HS', 'NONE', 'HS', 'J', NULL),
(346, 'BC ARNAULT', 'BC ARNAULT', '2026-03-12', '2026-03-12', 'RTT', 'NONE', 'RTT', 'J', NULL),
(347, 'BC ARNAULT', 'BC ARNAULT', '2026-03-31', '2026-03-31', 'CA', 'NONE', 'CA', 'J', NULL),
(348, 'BC HOCHARD', 'BC HOCHARD', '2026-03-02', '2026-03-02', 'CA', 'NONE', 'CA', 'J', NULL),
(349, 'BC HOCHARD', 'BC HOCHARD', '2026-03-05', '2026-03-05', 'RTT', 'NONE', 'RTT', 'J', NULL),
(350, 'BC HOCHARD', 'BC HOCHARD', '2026-03-30', '2026-03-30', 'CA', 'NONE', 'CA', 'J', NULL),
(351, 'BC DUPUIS', 'BC DUPUIS', '2026-03-16', '2026-03-16', 'HS', 'NONE', 'HS', 'J', NULL),
(352, 'BC DUPUIS', 'BC DUPUIS', '2026-03-18', '2026-03-18', 'HS', 'NONE', 'HS', 'J', NULL),
(353, 'BC BASTIEN', 'BC BASTIEN', '2026-03-06', '2026-03-06', 'CA', 'NONE', 'CA', 'J', NULL),
(354, 'BC BASTIEN', 'BC BASTIEN', '2026-03-12', '2026-03-12', 'VEM', 'NONE', 'VEM', 'J', NULL),
(355, 'BC BASTIEN', 'BC BASTIEN', '2026-03-27', '2026-03-27', 'HS', 'NONE', 'HS', 'J', NULL),
(356, 'BC ANTHONY', 'BC ANTHONY', '2026-03-12', '2026-03-12', 'HS', 'NONE', 'HS', 'J', NULL),
(357, 'BC ANTHONY', 'BC ANTHONY', '2026-03-16', '2026-03-16', 'PREV', 'NONE', 'HS', 'J', NULL),
(358, 'BC ANTHONY', 'BC ANTHONY', '2026-03-23', '2026-03-23', 'HS', 'NONE', 'HS', 'J', NULL),
(359, 'BC ANTHONY', 'BC ANTHONY', '2026-03-24', '2026-03-24', 'HS', 'NONE', 'HS', 'J', NULL),
(360, 'ACP1 LOIN', 'ACP1 LOIN', '2026-03-16', '2026-03-16', 'NC', 'NONE', 'NC', 'J', NULL),
(361, 'BC DELCROIX', 'BC DELCROIX', '2026-03-02', '2026-03-02', 'HS', 'NONE', 'HS', 'J', NULL),
(362, 'BC DELCROIX', 'BC DELCROIX', '2026-03-16', '2026-03-16', 'HS', 'NONE', 'HS', 'J', NULL),
(363, 'BC DELCROIX', 'BC DELCROIX', '2026-03-20', '2026-03-20', 'HS', 'NONE', 'HS', 'J', NULL),
(364, 'Cne MOKADEM', 'Cne MOKADEM', '2026-03-20', '2026-03-20', 'CAA', 'NONE', 'CAA', 'AM', NULL),
(365, 'BC DRUEZ', 'BC DRUEZ', '2026-03-02', '2026-03-02', 'CA', 'NONE', 'CA', 'J', NULL),
(366, 'BC DRUEZ', 'BC DRUEZ', '2026-03-10', '2026-03-10', 'DA', 'NONE', 'DA', 'J', '15:15'),
(367, 'BC DRUEZ', 'BC DRUEZ', '2026-03-11', '2026-03-11', 'DA', 'NONE', 'DA', 'J', '16:15'),
(368, 'BC DRUEZ', 'BC DRUEZ', '2026-03-12', '2026-03-12', 'DA', 'NONE', 'DA', 'J', '15:15'),
(369, 'BC DRUEZ', 'BC DRUEZ', '2026-03-13', '2026-03-13', 'DA', 'NONE', 'DA', 'J', '14:25'),
(370, 'BC DRUEZ', 'BC DRUEZ', '2026-03-17', '2026-03-17', 'DA', 'NONE', 'DA', 'J', '15:15'),
(371, 'BC DRUEZ', 'BC DRUEZ', '2026-03-20', '2026-03-20', 'DA', 'NONE', 'DA', 'J', '14:25'),
(372, 'BC DRUEZ', 'BC DRUEZ', '2026-03-18', '2026-03-18', 'DA', 'NONE', 'DA', 'J', '16:15'),
(373, 'BC DRUEZ', 'BC DRUEZ', '2026-03-19', '2026-03-19', 'DA', 'NONE', 'DA', 'J', '15:15'),
(374, 'BC DRUEZ', 'BC DRUEZ', '2026-03-24', '2026-03-24', 'DA', 'NONE', 'DA', 'J', '15:15'),
(375, 'BC DRUEZ', 'BC DRUEZ', '2026-03-27', '2026-03-27', 'DA', 'NONE', 'DA', 'J', '14:25'),
(376, 'BC DRUEZ', 'BC DRUEZ', '2026-03-31', '2026-03-31', 'DA', 'NONE', 'DA', 'J', '15:15'),
(377, 'ADJ CORRARD', 'ADJ CORRARD', '2026-03-03', '2026-03-03', 'J', 'NONE', 'J', 'J', NULL),
(378, 'ADJ CORRARD', 'ADJ CORRARD', '2026-03-05', '2026-03-05', 'R', 'NONE', 'R', 'J', NULL),
(379, 'ADJ CORRARD', 'ADJ CORRARD', '2026-03-06', '2026-03-06', 'J', 'NONE', 'J', 'J', NULL),
(380, 'ADJ CORRARD', 'ADJ CORRARD', '2026-03-09', '2026-03-09', 'R', 'NONE', 'R', 'J', NULL),
(381, 'ADJ CORRARD', 'ADJ CORRARD', '2026-03-13', '2026-03-13', 'J', 'NONE', 'J', 'J', NULL),
(382, 'ADJ CORRARD', 'ADJ CORRARD', '2026-03-20', '2026-03-20', 'J', 'NONE', 'J', 'J', NULL),
(383, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-03-03', '2026-03-03', 'J', 'NONE', 'J', 'J', NULL),
(384, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-03-05', '2026-03-05', 'J', 'NONE', 'J', 'J', NULL),
(385, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-03-06', '2026-03-06', 'R', 'NONE', 'R', 'J', NULL),
(386, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-03-09', '2026-03-09', 'J', 'NONE', 'J', 'J', NULL),
(387, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-03-13', '2026-03-13', 'R', 'NONE', 'R', 'J', NULL),
(388, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-03-20', '2026-03-20', 'P', 'NONE', 'P', 'J', NULL),
(389, 'LCL PARENT', 'LCL PARENT', '2026-03-27', '2026-03-27', 'P', 'NONE', 'P', 'AM', NULL),
(390, 'BC HOCHARD', 'BC HOCHARD', '2026-03-16', '2026-03-16', 'CA', 'NONE', 'CA', 'J', NULL),
(391, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-03-25', '2026-03-25', 'HS', 'NONE', 'HS', 'AM', NULL),
(392, 'BC DELCROIX', 'BC DELCROIX', '2026-03-24', '2026-03-24', 'DA', 'NONE', 'DA', 'J', '16:00'),
(393, 'BC SIGAUD', 'BC SIGAUD', '2026-04-16', '2026-04-16', 'CF', 'NONE', 'CF', '', NULL),
(394, 'BC SIGAUD', 'BC SIGAUD', '2026-04-17', '2026-04-17', 'CF', 'NONE', 'CF', '', NULL),
(395, 'BC BOUXOM', 'BC BOUXOM', '2026-04-01', '2026-04-01', 'PREV', 'NONE', 'RTT', 'J', NULL),
(396, 'BC BOUXOM', 'BC BOUXOM', '2026-04-15', '2026-04-15', 'PREV', 'NONE', 'RTT', 'J', NULL),
(397, 'BC ARNAULT', 'BC ARNAULT', '2026-04-01', '2026-04-03', 'CA', 'NONE', 'CA', 'J', NULL),
(398, 'BC ARNAULT', 'BC ARNAULT', '2026-04-14', '2026-04-14', 'HS', 'NONE', 'HS', 'J', NULL),
(399, 'BC DUPUIS', 'BC DUPUIS', '2026-04-13', '2026-04-19', 'PREV', 'NONE', 'PREV', 'J', NULL),
(400, 'BC BASTIEN', 'BC BASTIEN', '2026-04-08', '2026-04-08', 'HS', 'NONE', 'HS', 'J', NULL),
(401, 'BC BASTIEN', 'BC BASTIEN', '2026-04-20', '2026-04-20', 'PREV', 'NONE', 'CA', 'J', NULL),
(402, 'BC ANTHONY', 'BC ANTHONY', '2026-04-13', '2026-04-15', 'PREV', 'NONE', 'PREV', 'J', NULL),
(403, 'ACP1 LOIN', 'ACP1 LOIN', '2026-04-07', '2026-04-10', 'CA', 'NONE', 'CA', 'J', NULL),
(404, 'ACP1 LOIN', 'ACP1 LOIN', '2026-04-29', '2026-04-30', 'CA', 'NONE', 'CA', 'J', NULL),
(405, 'ADJ CORRARD', 'ADJ CORRARD', '2026-04-10', '2026-04-10', 'J', 'NONE', 'J', 'J', NULL),
(406, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-04-10', '2026-04-10', 'R', 'NONE', 'R', 'J', NULL),
(407, 'ADJ CORRARD', 'ADJ CORRARD', '2026-04-13', '2026-04-13', 'R', 'NONE', 'R', 'J', NULL),
(408, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-04-13', '2026-04-13', 'J', 'NONE', 'J', 'J', NULL),
(409, 'ADJ CORRARD', 'ADJ CORRARD', '2026-04-17', '2026-04-17', 'J', 'NONE', 'J', 'J', NULL),
(410, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-04-17', '2026-04-17', 'R', 'NONE', 'R', 'J', NULL),
(411, 'ADJ CORRARD', 'ADJ CORRARD', '2026-04-20', '2026-04-24', 'J', 'NONE', 'J', 'J', NULL),
(412, 'ADJ LEFEBVRE', 'ADJ LEFEBVRE', '2026-04-20', '2026-04-24', 'R', 'NONE', 'R', 'J', NULL),
(413, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-04-27', '2026-04-30', 'PREV', 'NONE', 'PREV', 'J', NULL),
(414, 'BC DELCROIX', 'BC DELCROIX', '2026-04-28', '2026-04-28', 'VEM', 'NONE', 'VEM', 'M', NULL),
(415, 'BC DRUEZ', 'BC DRUEZ', '2026-04-27', '2026-04-30', 'PREV', 'NONE', 'PREV', 'J', NULL),
(416, 'BC HOCHARD', 'BC HOCHARD', '2026-04-27', '2026-04-27', 'CA', 'NONE', 'CA', 'J', NULL),
(417, 'CoGe ROUSSEL', 'CoGe ROUSSEL', '2026-04-20', '2026-04-24', 'CA', 'NONE', 'CA', 'J', NULL),
(420, 'BC DELCROIX', 'BC DELCROIX', '2026-03-13', '2026-03-13', 'DA', 'NONE', 'DA', 'J', '15:25'),
(421, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-03-30', '2026-03-30', 'DA', 'NONE', 'DA', 'J', '15:00'),
(422, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-03-31', '2026-03-31', 'HS', 'NONE', 'HS', 'J', NULL),
(423, 'BC DUPUIS', 'BC DUPUIS', '2026-04-03', '2026-04-03', 'HS', 'NONE', 'HS', 'J', NULL),
(424, 'BC ANTHONY', 'BC ANTHONY', '2026-04-10', '2026-04-10', 'PREV', 'NONE', 'PREV', 'J', NULL),
(425, 'GP DHALLEWYN', 'GP DHALLEWYN', '2026-04-01', '2026-04-01', 'HS', 'NONE', 'HS', 'J', NULL),
(426, 'BC DELCROIX', 'BC DELCROIX', '2026-04-03', '2026-04-03', 'HS', 'NONE', 'HS', 'AM', NULL),
(427, 'BC DELCROIX', 'BC DELCROIX', '2026-04-02', '2026-04-02', 'DA', 'NONE', 'DA', 'J', '15:00');

-- --------------------------------------------------------

--
-- Structure de la table `feries`
--

CREATE TABLE `feries` (
  `id` int(11) NOT NULL,
  `date` date NOT NULL,
  `libelle` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `feries`
--

INSERT INTO `feries` (`id`, `date`, `libelle`) VALUES
(9, '2027-01-01', 'Jour de l’an'),
(10, '2027-04-05', 'Lundi de Pâques'),
(11, '2027-05-01', 'Fête du Travail'),
(12, '2027-05-08', 'Victoire 1945'),
(13, '2027-05-13', 'Ascension'),
(14, '2027-05-24', 'Lundi de Pentecôte'),
(15, '2027-07-14', 'Fête nationale'),
(16, '2027-08-15', 'Assomption'),
(17, '2027-11-01', 'Toussaint'),
(18, '2027-11-11', 'Armistice'),
(19, '2027-12-25', 'Noël'),
(20, '2028-01-01', NULL),
(21, '2028-05-01', NULL),
(22, '2028-05-08', NULL),
(23, '2028-07-14', NULL),
(24, '2028-08-15', NULL),
(25, '2028-11-01', NULL),
(26, '2028-11-11', NULL),
(27, '2028-12-25', NULL),
(28, '2028-04-17', NULL),
(29, '2028-05-25', NULL),
(30, '2028-06-05', NULL),
(31, '2029-01-01', NULL),
(32, '2029-05-01', NULL),
(33, '2029-05-08', NULL),
(34, '2029-07-14', NULL),
(35, '2029-08-15', NULL),
(36, '2029-11-01', NULL),
(37, '2029-11-11', NULL),
(38, '2029-12-25', NULL),
(39, '2029-04-02', NULL),
(40, '2029-05-10', NULL),
(41, '2029-05-21', NULL),
(42, '2026-01-01', NULL),
(43, '2026-05-01', NULL),
(44, '2026-05-08', NULL),
(45, '2026-07-14', NULL),
(46, '2026-08-15', NULL),
(47, '2026-11-01', NULL),
(48, '2026-11-11', NULL),
(49, '2026-12-25', NULL),
(50, '2026-04-06', NULL),
(51, '2026-05-14', NULL),
(52, '2026-05-25', NULL),
(53, '2025-01-01', NULL),
(54, '2025-05-01', NULL),
(55, '2025-05-08', NULL),
(56, '2025-07-14', NULL),
(57, '2025-08-15', NULL),
(58, '2025-11-01', NULL),
(59, '2025-11-11', NULL),
(60, '2025-12-25', NULL),
(61, '2025-04-21', NULL),
(62, '2025-05-29', NULL),
(63, '2025-06-09', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `locks`
--

CREATE TABLE `locks` (
  `id` int(11) NOT NULL,
  `scope` enum('global','agent') NOT NULL,
  `agent` varchar(100) DEFAULT NULL,
  `locked_by` int(11) NOT NULL,
  `locked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `mois` varchar(7) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `locks`
--

INSERT INTO `locks` (`id`, `scope`, `agent`, `locked_by`, `locked_at`, `mois`) VALUES
(30, '', 'CoGe ROUSSEL', 23, '2026-03-23 10:09:54', '2026-01'),
(31, '', 'LCL PARENT', 23, '2026-03-23 10:09:54', '2026-01'),
(32, '', 'IR MOREAU', 23, '2026-03-23 10:09:55', '2026-01'),
(33, '', 'Cne MOKADEM', 23, '2026-03-23 10:09:56', '2026-01'),
(34, '', 'BC MASSON', 23, '2026-03-23 10:09:56', '2026-01'),
(35, '', 'BC SIGAUD', 23, '2026-03-23 10:09:57', '2026-01'),
(36, '', 'BC DAINOTTI', 23, '2026-03-23 10:09:58', '2026-01'),
(37, '', 'BC BOUXOM', 23, '2026-03-23 10:09:58', '2026-01'),
(38, '', 'BC ARNAULT', 23, '2026-03-23 10:09:59', '2026-01'),
(39, '', 'BC HOCHARD', 23, '2026-03-23 10:09:59', '2026-01'),
(40, '', 'BC DUPUIS', 23, '2026-03-23 10:10:00', '2026-01'),
(41, '', 'BC BASTIEN', 23, '2026-03-23 10:10:00', '2026-01'),
(42, '', 'BC ANTHONY', 23, '2026-03-23 10:10:02', '2026-01'),
(43, '', 'ADJ LEFEBVRE', 23, '2026-03-23 10:10:02', '2026-01'),
(44, '', 'ADJ CORRARD', 23, '2026-03-23 10:10:03', '2026-01'),
(45, '', 'GP DHALLEWYN', 23, '2026-03-23 10:10:03', '2026-01'),
(46, '', 'BC DELCROIX', 23, '2026-03-23 10:10:04', '2026-01'),
(47, '', 'ADC LAMBERT', 23, '2026-03-23 10:10:04', '2026-01'),
(48, '', 'ACP1 DEMERVAL', 23, '2026-03-23 10:10:05', '2026-01'),
(49, '', 'ACP1 LOIN', 23, '2026-03-23 10:10:05', '2026-01'),
(50, '', 'BC DRUEZ', 23, '2026-03-23 10:10:07', '2026-01'),
(51, '', 'AA MAES', 23, '2026-03-23 10:10:07', '2026-01'),
(52, '', 'CoGe ROUSSEL', 23, '2026-03-23 10:11:08', '2026-02'),
(53, '', 'LCL PARENT', 23, '2026-03-23 10:11:08', '2026-02'),
(54, '', 'CoGe ROUSSEL', 23, '2026-03-23 10:11:23', '2026-02'),
(55, '', 'LCL PARENT', 23, '2026-03-23 10:11:24', '2026-02'),
(56, '', 'IR MOREAU', 23, '2026-03-23 10:11:25', '2026-02'),
(57, '', 'Cne MOKADEM', 23, '2026-03-23 10:11:30', '2026-02'),
(58, '', 'CoGe ROUSSEL', 23, '2026-03-23 10:17:00', '2026-01'),
(59, '', 'LCL PARENT', 23, '2026-03-23 10:17:00', '2026-01'),
(60, '', 'IR MOREAU', 23, '2026-03-23 10:17:01', '2026-01'),
(61, '', 'Cne MOKADEM', 23, '2026-03-23 10:17:01', '2026-01'),
(62, '', 'BC MASSON', 23, '2026-03-23 10:17:02', '2026-01'),
(63, '', 'BC DAINOTTI', 23, '2026-03-23 10:17:02', '2026-01'),
(64, '', 'BC SIGAUD', 23, '2026-03-23 10:17:04', '2026-01'),
(65, '', 'BC DRUEZ', 23, '2026-03-23 10:17:05', '2026-01'),
(66, '', 'AA MAES', 23, '2026-03-23 10:17:07', '2026-01'),
(67, '', 'ACP1 LOIN', 23, '2026-03-23 10:17:07', '2026-01'),
(68, '', 'ADC LAMBERT', 23, '2026-03-23 10:17:09', '2026-01'),
(69, '', 'BC DELCROIX', 23, '2026-03-23 10:17:09', '2026-01'),
(70, '', 'GP DHALLEWYN', 23, '2026-03-23 10:17:10', '2026-01'),
(71, '', 'ACP1 DEMERVAL', 23, '2026-03-23 10:17:10', '2026-01'),
(72, '', 'ADJ LEFEBVRE', 23, '2026-03-23 10:17:11', '2026-01'),
(73, '', 'ADJ CORRARD', 23, '2026-03-23 10:17:12', '2026-01'),
(74, '', 'BC ANTHONY', 23, '2026-03-23 10:17:13', '2026-01'),
(75, '', 'BC BASTIEN', 23, '2026-03-23 10:17:13', '2026-01'),
(76, '', 'BC DUPUIS', 23, '2026-03-23 10:17:14', '2026-01'),
(77, '', 'BC HOCHARD', 23, '2026-03-23 10:17:14', '2026-01'),
(78, '', 'BC ARNAULT', 23, '2026-03-23 10:17:15', '2026-01'),
(79, '', 'BC BOUXOM', 23, '2026-03-23 10:17:15', '2026-01'),
(80, '', 'CoGe ROUSSEL', 23, '2026-03-23 10:17:19', '2026-02'),
(81, '', 'LCL PARENT', 23, '2026-03-23 10:17:20', '2026-02'),
(82, '', 'IR MOREAU', 23, '2026-03-23 10:17:21', '2026-02'),
(83, '', 'Cne MOKADEM', 23, '2026-03-23 10:17:21', '2026-02'),
(84, '', 'BC MASSON', 23, '2026-03-23 10:17:22', '2026-02'),
(85, '', 'BC SIGAUD', 23, '2026-03-23 10:17:22', '2026-02'),
(86, '', 'BC DAINOTTI', 23, '2026-03-23 10:17:23', '2026-02'),
(87, '', 'BC BOUXOM', 23, '2026-03-23 10:17:23', '2026-02'),
(88, '', 'BC ARNAULT', 23, '2026-03-23 10:17:24', '2026-02'),
(89, '', 'BC HOCHARD', 23, '2026-03-23 10:17:24', '2026-02'),
(90, '', 'BC DUPUIS', 23, '2026-03-23 10:21:33', '2026-02'),
(91, '', 'BC HOCHARD', 23, '2026-03-23 10:21:36', '2026-02'),
(92, '', 'BC ARNAULT', 23, '2026-03-23 10:21:37', '2026-02'),
(93, '', 'BC BOUXOM', 23, '2026-03-23 10:21:37', '2026-02'),
(94, '', 'BC DAINOTTI', 23, '2026-03-23 10:21:38', '2026-02'),
(95, '', 'BC MASSON', 23, '2026-03-23 10:21:39', '2026-02'),
(96, '', 'BC SIGAUD', 23, '2026-03-23 10:21:39', '2026-02'),
(97, '', 'Cne MOKADEM', 23, '2026-03-23 10:21:40', '2026-02'),
(98, '', 'IR MOREAU', 23, '2026-03-23 10:21:41', '2026-02'),
(99, '', 'LCL PARENT', 23, '2026-03-23 10:21:42', '2026-02'),
(100, '', 'CoGe ROUSSEL', 23, '2026-03-23 10:21:42', '2026-02');

-- --------------------------------------------------------

--
-- Structure de la table `locks_mois`
--

CREATE TABLE `locks_mois` (
  `id` int(11) NOT NULL,
  `agent` varchar(80) NOT NULL,
  `mois` varchar(7) NOT NULL,
  `locked_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `locks_mois`
--

INSERT INTO `locks_mois` (`id`, `agent`, `mois`, `locked_by`, `created_at`) VALUES
(19, 'CoGe ROUSSEL', '2026-01', 23, '2026-03-23 11:28:35'),
(20, 'IR MOREAU', '2026-01', 23, '2026-03-23 11:28:36'),
(21, 'LCL PARENT', '2026-01', 23, '2026-03-23 11:28:36'),
(22, 'Cne MOKADEM', '2026-01', 23, '2026-03-23 11:28:37'),
(23, 'BC MASSON', '2026-01', 23, '2026-03-23 11:28:38'),
(24, 'BC SIGAUD', '2026-01', 23, '2026-03-23 11:28:38'),
(25, 'BC DAINOTTI', '2026-01', 23, '2026-03-23 11:28:39'),
(26, 'BC BOUXOM', '2026-01', 23, '2026-03-23 11:28:39'),
(27, 'BC ARNAULT', '2026-01', 23, '2026-03-23 11:28:39'),
(28, 'BC HOCHARD', '2026-01', 23, '2026-03-23 11:28:40'),
(29, 'BC DUPUIS', '2026-01', 23, '2026-03-23 11:28:40'),
(30, 'BC BASTIEN', '2026-01', 23, '2026-03-23 11:28:41'),
(31, 'BC DRUEZ', '2026-01', 23, '2026-03-23 11:28:43'),
(32, 'AA MAES', '2026-01', 23, '2026-03-23 11:28:43'),
(33, 'ACP1 LOIN', '2026-01', 23, '2026-03-23 11:28:44'),
(34, 'ADC LAMBERT', '2026-01', 23, '2026-03-23 11:28:44'),
(35, 'BC DELCROIX', '2026-01', 23, '2026-03-23 11:28:45'),
(36, 'GP DHALLEWYN', '2026-01', 23, '2026-03-23 11:28:45'),
(37, 'ACP1 DEMERVAL', '2026-01', 23, '2026-03-23 11:28:46'),
(38, 'ADJ CORRARD', '2026-01', 23, '2026-03-23 11:28:47'),
(39, 'ADJ LEFEBVRE', '2026-01', 23, '2026-03-23 11:28:47'),
(40, 'BC ANTHONY', '2026-01', 23, '2026-03-23 11:28:48'),
(154, 'CoGe ROUSSEL', '2026-02', 23, '2026-03-27 08:50:36'),
(155, 'LCL PARENT', '2026-02', 23, '2026-03-27 08:50:42'),
(156, 'IR MOREAU', '2026-02', 23, '2026-03-27 08:50:42'),
(157, 'Cne MOKADEM', '2026-02', 23, '2026-03-27 08:50:43'),
(158, 'BC MASSON', '2026-02', 23, '2026-03-27 08:50:44'),
(159, 'BC SIGAUD', '2026-02', 23, '2026-03-27 08:50:45'),
(160, 'BC DAINOTTI', '2026-02', 23, '2026-03-27 08:50:46'),
(161, 'BC BOUXOM', '2026-02', 23, '2026-03-27 08:50:47'),
(162, 'BC ARNAULT', '2026-02', 23, '2026-03-27 08:50:48'),
(163, 'BC HOCHARD', '2026-02', 23, '2026-03-27 08:50:49'),
(165, 'BC DUPUIS', '2026-02', 23, '2026-03-27 08:50:51'),
(166, 'BC BASTIEN', '2026-02', 23, '2026-03-27 08:50:52'),
(167, 'BC ANTHONY', '2026-02', 23, '2026-03-27 08:50:53'),
(168, 'ADJ CORRARD', '2026-02', 23, '2026-03-27 08:50:58'),
(169, 'ADJ LEFEBVRE', '2026-02', 23, '2026-03-27 08:50:59'),
(170, 'GP DHALLEWYN', '2026-02', 23, '2026-03-27 08:51:00'),
(171, 'BC DELCROIX', '2026-02', 23, '2026-03-27 08:51:01'),
(172, 'ACP1 DEMERVAL', '2026-02', 23, '2026-03-27 08:51:02'),
(173, 'ADC LAMBERT', '2026-02', 23, '2026-03-27 08:51:03'),
(174, 'ACP1 LOIN', '2026-02', 23, '2026-03-27 08:51:05'),
(175, 'AA MAES', '2026-02', 23, '2026-03-27 08:51:08'),
(176, 'BC DRUEZ', '2026-02', 23, '2026-03-27 08:51:09'),
(245, 'CoGe ROUSSEL', '2026-03', 23, '2026-04-03 09:56:48'),
(246, 'LCL PARENT', '2026-03', 23, '2026-04-03 09:56:48'),
(247, 'IR MOREAU', '2026-03', 23, '2026-04-03 09:56:48'),
(248, 'Cne MOKADEM', '2026-03', 23, '2026-04-03 09:56:48'),
(249, 'BC MASSON', '2026-03', 23, '2026-04-03 09:56:48'),
(250, 'BC SIGAUD', '2026-03', 23, '2026-04-03 09:56:48'),
(251, 'BC DAINOTTI', '2026-03', 23, '2026-04-03 09:56:48'),
(252, 'BC BOUXOM', '2026-03', 23, '2026-04-03 09:56:48'),
(253, 'BC ARNAULT', '2026-03', 23, '2026-04-03 09:56:48'),
(254, 'BC HOCHARD', '2026-03', 23, '2026-04-03 09:56:48'),
(255, 'BC DUPUIS', '2026-03', 23, '2026-04-03 09:56:48'),
(256, 'BC BASTIEN', '2026-03', 23, '2026-04-03 09:56:48'),
(257, 'BC ANTHONY', '2026-03', 23, '2026-04-03 09:56:48'),
(258, 'ADJ CORRARD', '2026-03', 23, '2026-04-03 09:56:48'),
(259, 'ADJ LEFEBVRE', '2026-03', 23, '2026-04-03 09:56:48'),
(260, 'GP DHALLEWYN', '2026-03', 23, '2026-04-03 09:56:48'),
(261, 'BC DELCROIX', '2026-03', 23, '2026-04-03 09:56:48'),
(262, 'ADC LAMBERT', '2026-03', 23, '2026-04-03 09:56:48'),
(263, 'ACP1 DEMERVAL', '2026-03', 23, '2026-04-03 09:56:48'),
(264, 'ACP1 LOIN', '2026-03', 23, '2026-04-03 09:56:48'),
(265, 'AA MAES', '2026-03', 23, '2026-04-03 09:56:48'),
(266, 'BC DRUEZ', '2026-03', 23, '2026-04-03 09:56:49');

-- --------------------------------------------------------

--
-- Structure de la table `notes_evenements`
--

CREATE TABLE `notes_evenements` (
  `id` int(11) NOT NULL,
  `annee` int(11) NOT NULL,
  `mois` int(11) NOT NULL,
  `date` date NOT NULL,
  `libelle` varchar(255) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `notes_evenements`
--

INSERT INTO `notes_evenements` (`id`, `annee`, `mois`, `date`, `libelle`, `created_by`, `created_at`) VALUES
(2, 2027, 1, '2027-01-21', 'Visite Gouverneur du Hainaut', 23, '2026-03-10 08:36:23'),
(5, 2027, 2, '2027-02-10', 'Opération FRONTIER', 23, '2026-03-10 10:05:10'),
(6, 2027, 1, '2027-01-27', 'Prise de photos.', 23, '2026-03-11 12:12:52'),
(7, 2026, 1, '2026-01-28', '14h - 00h - Match de Foot : Bruges – Marseille. Lieu : Salle de crise en tenue.', 23, '2026-03-23 09:43:11'),
(8, 2026, 3, '2026-03-27', 'Visite de M. LACROIX Franck, Directeur des Douanes de Lille et M. DEMASSIET son adjoint et chef du pôle orientation des contrôles. Merci de mettre la tenue.', 23, '2026-03-25 12:59:51'),
(9, 2026, 3, '2026-03-31', 'Visite d\'une délégation de deux officiers de la direction générale de la gendarmerie nationale l\'AM. Merci de mettre la tenue.', 23, '2026-03-30 09:40:38');

-- --------------------------------------------------------

--
-- Structure de la table `notes_messages`
--

CREATE TABLE `notes_messages` (
  `id` int(11) NOT NULL,
  `annee` int(11) NOT NULL,
  `mois` int(11) NOT NULL,
  `texte` text NOT NULL,
  `date_fin` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `notes_messages`
--

INSERT INTO `notes_messages` (`id`, `annee`, `mois`, `texte`, `date_fin`, `created_by`, `created_at`) VALUES
(1, 2027, 1, 'Pensez à poser vos congés pour la fin du mois.', '2027-01-31', 23, '2026-03-10 10:11:55'),
(2, 2027, 1, 'attnetion', '2027-01-13', 11, '2026-03-11 08:26:31'),
(3, 2027, 3, 'Travaux Câblage', '2027-03-26', 9, '2026-03-17 07:37:22');

-- --------------------------------------------------------

--
-- Structure de la table `notes_mois`
--

CREATE TABLE `notes_mois` (
  `id` int(11) NOT NULL,
  `annee` int(11) NOT NULL,
  `mois` int(11) NOT NULL,
  `texte_libre` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `date_fin` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `notes_mois`
--

INSERT INTO `notes_mois` (`id`, `annee`, `mois`, `texte_libre`, `created_by`, `updated_at`, `date_fin`) VALUES
(1, 2027, 1, 'Pensez à finaliser vos congés prévisionnels !', 23, '2026-03-10 09:41:09', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `permanences`
--

CREATE TABLE `permanences` (
  `id` int(11) NOT NULL,
  `agent` varchar(100) NOT NULL,
  `date` varchar(10) NOT NULL,
  `type` varchar(10) NOT NULL DEFAULT 'M',
  `cycle_orig` varchar(10) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `permanences`
--

INSERT INTO `permanences` (`id`, `agent`, `date`, `type`, `cycle_orig`, `created_at`) VALUES
(38, 'BC ARNAULT', '2029-05-08', 'AM', 'FERIE', '2026-03-11 07:53:42'),
(215, 'BC BOUXOM', '2027-01-02', 'IJ', 'RC', '2026-03-16 14:34:39'),
(216, 'BC BOUXOM', '2027-01-03', 'IJ', 'RL', '2026-03-16 14:34:42'),
(217, 'BC BOUXOM', '2027-01-09', 'M', 'RC', '2026-03-16 14:34:45'),
(218, 'BC BOUXOM', '2027-01-10', 'M', 'RL', '2026-03-16 14:34:48'),
(219, 'BC BOUXOM', '2027-01-16', 'IJ', 'RC', '2026-03-16 14:34:52'),
(235, 'BC ARNAULT', '2026-01-03', 'M', 'RC', '2026-03-19 10:02:38'),
(236, 'BC ARNAULT', '2026-01-04', 'M', 'RL', '2026-03-19 10:02:41'),
(237, 'BC BOUXOM', '2026-01-10', 'IJ', 'RC', '2026-03-19 10:02:52'),
(238, 'BC BOUXOM', '2026-01-11', 'IJ', 'RL', '2026-03-19 10:02:56'),
(239, 'BC ARNAULT', '2026-01-10', 'IJ', 'RC', '2026-03-19 10:03:01'),
(240, 'BC ARNAULT', '2026-01-11', 'IJ', 'RL', '2026-03-19 10:03:04'),
(241, 'BC BOUXOM', '2026-01-03', 'IJ', 'RC', '2026-03-19 10:03:09'),
(242, 'BC BOUXOM', '2026-01-04', 'IJ', 'RL', '2026-03-19 10:03:16'),
(243, 'BC ARNAULT', '2026-01-17', 'IJ', 'RC', '2026-03-19 10:03:23'),
(244, 'BC ARNAULT', '2026-01-18', 'IJ', 'RL', '2026-03-19 10:03:27'),
(245, 'BC HOCHARD', '2026-01-17', 'M', 'RC', '2026-03-19 10:03:31'),
(246, 'BC HOCHARD', '2026-01-18', 'M', 'RL', '2026-03-19 10:03:34'),
(247, 'BC BOUXOM', '2026-01-24', 'IJ', 'RC', '2026-03-19 10:03:39'),
(248, 'BC BOUXOM', '2026-01-25', 'IJ', 'RL', '2026-03-19 10:03:42'),
(249, 'BC ARNAULT', '2026-01-24', 'IJ', 'RC', '2026-03-19 10:03:46'),
(250, 'BC ARNAULT', '2026-01-25', 'IJ', 'RL', '2026-03-19 10:03:49'),
(251, 'BC BOUXOM', '2026-01-31', 'IJ', 'RC', '2026-03-19 10:03:52'),
(252, 'BC BASTIEN', '2026-01-24', 'IJ', 'RC', '2026-03-19 10:08:53'),
(253, 'BC BASTIEN', '2026-01-25', 'IJ', 'RL', '2026-03-19 10:09:39'),
(254, 'BC ANTHONY', '2026-01-24', 'IJ', 'RC', '2026-03-19 10:09:44'),
(255, 'BC ANTHONY', '2026-01-25', 'IJ', 'RL', '2026-03-19 10:09:47'),
(256, 'BC ANTHONY', '2026-01-17', 'IJ', 'RC', '2026-03-19 10:10:00'),
(257, 'BC ANTHONY', '2026-01-18', 'IJ', 'RL', '2026-03-19 10:10:02'),
(258, 'BC DUPUIS', '2026-01-31', 'M', 'RC', '2026-03-19 10:42:45'),
(259, 'BC ANTHONY', '2026-01-31', 'AM', 'RC', '2026-03-19 10:42:49'),
(260, 'BC DUPUIS', '2026-01-24', 'M', 'RC', '2026-03-19 10:42:53'),
(261, 'BC DUPUIS', '2026-01-25', 'M', 'RL', '2026-03-19 10:42:55'),
(262, 'ADJ CORRARD', '2026-01-10', 'AM', 'RC', '2026-03-19 10:45:11'),
(263, 'ADJ CORRARD', '2026-01-11', 'AM', 'RL', '2026-03-19 10:45:15'),
(264, 'ADJ LEFEBVRE', '2026-01-10', 'M', 'RC', '2026-03-19 10:45:18'),
(265, 'ADJ LEFEBVRE', '2026-01-11', 'M', 'RL', '2026-03-19 10:45:21'),
(266, 'GP DHALLEWYN', '2026-01-01', 'M', 'FERIE', '2026-03-19 10:49:28'),
(267, 'GP DHALLEWYN', '2026-01-17', 'IJ', 'RC', '2026-03-19 10:50:01'),
(268, 'GP DHALLEWYN', '2026-01-18', 'IJ', 'RL', '2026-03-19 10:50:03'),
(269, 'GP DHALLEWYN', '2026-01-24', 'AM', 'RC', '2026-03-19 10:50:07'),
(270, 'GP DHALLEWYN', '2026-01-25', 'AM', 'RL', '2026-03-19 10:50:11'),
(271, 'BC DELCROIX', '2026-01-03', 'AM', 'RC', '2026-03-19 10:50:44'),
(272, 'BC DELCROIX', '2026-01-04', 'AM', 'RL', '2026-03-19 10:50:48'),
(273, 'BC DELCROIX', '2026-01-10', 'IJ', 'RC', '2026-03-19 10:51:08'),
(274, 'BC DELCROIX', '2026-01-11', 'IJ', 'RL', '2026-03-19 10:51:11'),
(275, 'BC DELCROIX', '2026-01-24', 'IJ', 'RC', '2026-03-19 10:51:33'),
(276, 'BC DELCROIX', '2026-01-25', 'IJ', 'RL', '2026-03-19 10:51:36'),
(277, 'BC DRUEZ', '2026-01-17', 'AM', 'RC', '2026-03-19 10:52:33'),
(278, 'BC DRUEZ', '2026-01-18', 'AM', 'RL', '2026-03-19 10:52:36'),
(279, 'BC DUPUIS', '2026-01-01', 'AM', 'FERIE', '2026-03-23 09:44:27'),
(280, 'BC BOUXOM', '2026-02-07', 'IJ', 'RC', '2026-03-23 10:14:15'),
(281, 'BC BOUXOM', '2026-02-08', 'IJ', 'RL', '2026-03-23 10:14:18'),
(282, 'BC BOUXOM', '2026-02-14', 'IJ', 'RC', '2026-03-23 10:14:25'),
(283, 'BC BOUXOM', '2026-02-15', 'IJ', 'RL', '2026-03-23 10:14:27'),
(284, 'BC BOUXOM', '2026-02-21', 'M', 'RC', '2026-03-23 10:15:08'),
(285, 'BC BOUXOM', '2026-02-22', 'M', 'RL', '2026-03-23 10:15:11'),
(286, 'BC BOUXOM', '2026-02-28', 'IJ', 'RC', '2026-03-23 10:15:34'),
(287, 'BC HOCHARD', '2026-02-14', 'AM', 'RC', '2026-03-23 10:19:46'),
(288, 'BC HOCHARD', '2026-02-15', 'AM', 'RL', '2026-03-23 10:19:49'),
(289, 'BC BASTIEN', '2026-02-14', 'M', 'RC', '2026-03-23 10:20:58'),
(290, 'BC BASTIEN', '2026-02-15', 'M', 'RL', '2026-03-23 10:21:01'),
(291, 'BC DUPUIS', '2026-02-01', 'M', 'RL', '2026-03-23 10:22:18'),
(292, 'BC ANTHONY', '2026-02-01', 'AM', 'RL', '2026-03-23 10:22:24'),
(293, 'BC ANTHONY', '2026-02-21', 'AM', 'RC', '2026-03-23 10:23:37'),
(294, 'BC ANTHONY', '2026-02-22', 'AM', 'RL', '2026-03-23 10:23:39'),
(295, 'ADJ CORRARD', '2026-02-07', 'M', 'RC', '2026-03-23 10:24:26'),
(296, 'ADJ CORRARD', '2026-02-08', 'M', 'RL', '2026-03-23 10:24:29'),
(297, 'ADJ LEFEBVRE', '2026-02-07', 'AM', 'RC', '2026-03-23 10:25:18'),
(298, 'ADJ LEFEBVRE', '2026-02-08', 'AM', 'RL', '2026-03-23 10:25:21'),
(299, 'BC DELCROIX', '2026-02-07', 'IJ', 'RC', '2026-03-23 10:27:37'),
(300, 'BC DELCROIX', '2026-02-08', 'IJ', 'RL', '2026-03-23 10:27:39'),
(301, 'BC DELCROIX', '2026-02-28', 'AM', 'RC', '2026-03-23 10:28:12'),
(302, 'BC ARNAULT', '2026-03-01', 'M', 'RL', '2026-03-23 10:33:01'),
(303, 'BC BOUXOM', '2026-03-01', 'IJ', 'RL', '2026-03-23 10:33:06'),
(304, 'BC HOCHARD', '2026-03-01', 'IJ', 'RL', '2026-03-23 10:33:09'),
(305, 'BC BOUXOM', '2026-03-14', 'IJ', 'RC', '2026-03-23 10:33:29'),
(306, 'BC BOUXOM', '2026-03-15', 'IJ', 'RL', '2026-03-23 10:33:32'),
(307, 'BC BOUXOM', '2026-03-28', 'IJ', 'RC', '2026-03-23 10:33:45'),
(308, 'BC BOUXOM', '2026-03-29', 'IJ', 'RL', '2026-03-23 10:33:48'),
(309, 'BC HOCHARD', '2026-03-15', 'IM', 'RL', '2026-03-23 10:34:26'),
(310, 'BC HOCHARD', '2026-03-21', 'IAM', 'RC', '2026-03-23 10:34:33'),
(311, 'BC HOCHARD', '2026-03-22', 'IAM', 'RL', '2026-03-23 10:34:36'),
(312, 'BC HOCHARD', '2026-03-29', 'M', 'RL', '2026-03-23 10:34:41'),
(313, 'BC DUPUIS', '2026-03-21', 'M', 'RC', '2026-03-23 10:36:06'),
(314, 'BC DUPUIS', '2026-03-22', 'M', 'RL', '2026-03-23 10:36:09'),
(315, 'BC BASTIEN', '2026-03-15', 'M', 'RL', '2026-03-23 10:36:16'),
(316, 'BC HOCHARD', '2026-03-14', 'M', 'RC', '2026-03-23 10:36:24'),
(317, 'BC BASTIEN', '2026-03-28', 'M', 'RC', '2026-03-23 10:37:00'),
(318, 'BC ANTHONY', '2026-03-21', 'AM', 'RC', '2026-03-23 10:37:21'),
(319, 'BC ANTHONY', '2026-03-22', 'AM', 'RL', '2026-03-23 10:37:23'),
(320, 'BC DELCROIX', '2026-03-01', 'AM', 'RL', '2026-03-23 10:39:04'),
(321, 'BC DELCROIX', '2026-03-07', 'IJ', 'RC', '2026-03-23 10:39:18'),
(322, 'BC DELCROIX', '2026-03-08', 'IJ', 'RL', '2026-03-23 10:39:20'),
(323, 'BC DELCROIX', '2026-03-14', 'AM', 'RC', '2026-03-23 10:39:24'),
(324, 'BC DELCROIX', '2026-03-15', 'AM', 'RL', '2026-03-23 10:39:28'),
(325, 'BC DELCROIX', '2026-03-21', 'IJ', 'RC', '2026-03-23 10:39:39'),
(326, 'BC DELCROIX', '2026-03-22', 'IJ', 'RL', '2026-03-23 10:39:42'),
(327, 'BC DRUEZ', '2026-03-28', 'AM', 'RC', '2026-03-23 10:42:29'),
(328, 'BC DRUEZ', '2026-03-29', 'AM', 'RL', '2026-03-23 10:42:32'),
(329, 'ADJ CORRARD', '2026-03-07', 'AM', 'RC', '2026-03-23 10:52:42'),
(330, 'ADJ CORRARD', '2026-03-08', 'AM', 'RL', '2026-03-23 10:52:45'),
(331, 'ADJ LEFEBVRE', '2026-03-07', 'M', 'RC', '2026-03-23 10:53:28'),
(332, 'ADJ LEFEBVRE', '2026-03-08', 'M', 'RL', '2026-03-23 10:53:31'),
(333, 'BC BOUXOM', '2026-04-04', 'M', 'RC', '2026-03-26 13:47:24'),
(334, 'BC BOUXOM', '2026-04-05', 'M', 'RL', '2026-03-26 13:47:28'),
(335, 'BC BOUXOM', '2026-04-25', 'IJ', 'RC', '2026-03-26 13:47:45'),
(336, 'BC BOUXOM', '2026-04-26', 'IJ', 'RL', '2026-03-26 13:47:48'),
(337, 'BC ARNAULT', '2026-04-25', 'M', 'RC', '2026-03-26 13:48:16'),
(338, 'BC ARNAULT', '2026-04-26', 'M', 'RL', '2026-03-26 13:48:19'),
(339, 'BC HOCHARD', '2026-04-04', 'AM', 'RC', '2026-03-26 13:48:25'),
(340, 'BC BOUXOM', '2026-04-06', 'M', 'FERIE', '2026-03-26 13:48:30'),
(341, 'BC HOCHARD', '2026-04-05', 'AM', 'RL', '2026-03-26 13:48:33'),
(342, 'BC HOCHARD', '2026-04-06', 'AM', 'FERIE', '2026-03-26 13:48:38'),
(343, 'BC HOCHARD', '2026-04-11', 'IAM', 'RC', '2026-03-26 13:48:48'),
(344, 'BC HOCHARD', '2026-04-18', 'IM', 'RC', '2026-03-26 13:48:57'),
(345, 'BC HOCHARD', '2026-04-19', 'IM', 'RL', '2026-03-26 13:49:01'),
(346, 'BC DUPUIS', '2026-04-04', 'IAM', 'RC', '2026-03-26 13:49:11'),
(347, 'BC DUPUIS', '2026-04-05', 'IAM', 'RL', '2026-03-26 13:49:14'),
(348, 'BC DUPUIS', '2026-04-06', 'IAM', 'FERIE', '2026-03-26 13:49:17'),
(349, 'BC BASTIEN', '2026-04-18', 'M', 'RC', '2026-03-26 13:49:47'),
(350, 'BC BASTIEN', '2026-04-19', 'M', 'RL', '2026-03-26 13:49:49'),
(351, 'BC ANTHONY', '2026-04-25', 'AM', 'RC', '2026-03-26 13:50:10'),
(352, 'BC ANTHONY', '2026-04-26', 'AM', 'RL', '2026-03-26 13:50:13'),
(353, 'ADJ CORRARD', '2026-04-11', 'AM', 'RC', '2026-03-26 13:51:03'),
(354, 'ADJ CORRARD', '2026-04-12', 'AM', 'RL', '2026-03-26 13:51:06'),
(355, 'ADJ LEFEBVRE', '2026-04-11', 'M', 'RC', '2026-03-26 13:51:10'),
(356, 'ADJ LEFEBVRE', '2026-04-12', 'M', 'RL', '2026-03-26 13:51:13'),
(357, 'GP DHALLEWYN', '2026-04-04', 'IJ', 'RC', '2026-03-26 13:51:59'),
(358, 'GP DHALLEWYN', '2026-04-05', 'IJ', 'RL', '2026-03-26 13:52:02'),
(359, 'GP DHALLEWYN', '2026-04-06', 'IJ', 'FERIE', '2026-03-26 13:52:09'),
(360, 'GP DHALLEWYN', '2026-04-18', 'IJ', 'RC', '2026-03-26 13:52:14'),
(361, 'GP DHALLEWYN', '2026-04-19', 'IJ', 'RL', '2026-03-26 13:52:17'),
(362, 'GP DHALLEWYN', '2026-04-25', 'IJ', 'RC', '2026-03-26 13:52:42'),
(363, 'GP DHALLEWYN', '2026-04-26', 'IJ', 'RL', '2026-03-26 13:52:44'),
(364, 'BC DELCROIX', '2026-04-04', 'IJ', 'RC', '2026-03-26 13:52:53'),
(365, 'BC DELCROIX', '2026-04-05', 'IJ', 'RL', '2026-03-26 13:52:56'),
(366, 'BC DELCROIX', '2026-04-06', 'IJ', 'FERIE', '2026-03-26 13:52:59'),
(367, 'BC DELCROIX', '2026-04-18', 'IJ', 'RC', '2026-03-26 13:53:03'),
(368, 'BC DELCROIX', '2026-04-19', 'IJ', 'RL', '2026-03-26 13:53:07'),
(369, 'BC DRUEZ', '2026-04-18', 'AM', 'RC', '2026-03-26 13:53:37'),
(370, 'BC DRUEZ', '2026-04-19', 'AM', 'RL', '2026-03-26 13:53:41'),
(371, 'BC DRUEZ', '2026-04-25', 'IJ', 'RC', '2026-03-26 13:53:44'),
(372, 'BC DRUEZ', '2026-04-26', 'IJ', 'RL', '2026-03-26 13:53:47'),
(373, 'BC HOCHARD', '2026-04-25', 'IJ', 'RC', '2026-03-27 07:47:16'),
(374, 'BC HOCHARD', '2026-04-26', 'IJ', 'RL', '2026-03-27 07:47:19');

-- --------------------------------------------------------

--
-- Structure de la table `tir`
--

CREATE TABLE `tir` (
  `id` int(11) NOT NULL,
  `agent` varchar(100) NOT NULL,
  `date` varchar(10) NOT NULL,
  `periode` enum('M','AM','J','NUIT') NOT NULL DEFAULT 'J',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `tir`
--

INSERT INTO `tir` (`id`, `agent`, `date`, `periode`, `created_at`) VALUES
(31, 'BC BOUXOM', '2027-01-29', 'M', '2026-03-16 14:37:22'),
(32, 'BC MASSON', '2027-01-15', 'NUIT', '2026-03-16 14:41:52'),
(39, 'BC BOUXOM', '2026-01-15', 'M', '2026-03-19 09:59:07'),
(40, 'CoGe ROUSSEL', '2026-01-16', 'M', '2026-03-19 10:00:19'),
(41, 'BC DUPUIS', '2026-01-23', 'M', '2026-03-19 10:41:19'),
(42, 'BC BASTIEN', '2026-01-23', 'M', '2026-03-19 10:41:24'),
(43, 'BC DRUEZ', '2026-01-15', 'M', '2026-03-19 10:52:28'),
(44, 'BC MASSON', '2026-02-05', 'NUIT', '2026-03-23 10:11:42'),
(45, 'BC ARNAULT', '2026-02-13', 'M', '2026-03-23 10:16:08'),
(46, 'BC HOCHARD', '2026-02-13', 'M', '2026-03-23 10:16:13'),
(47, 'BC ANTHONY', '2026-02-03', 'M', '2026-03-23 10:22:28'),
(48, 'GP DHALLEWYN', '2026-02-03', 'M', '2026-03-23 10:26:00'),
(49, 'BC DAINOTTI', '2026-03-31', 'NUIT', '2026-03-26 13:43:32'),
(50, 'BC SIGAUD', '2026-04-14', 'NUIT', '2026-04-03 07:57:21');

-- --------------------------------------------------------

--
-- Structure de la table `tir_annulations`
--

CREATE TABLE `tir_annulations` (
  `id` int(11) NOT NULL,
  `agent` varchar(100) NOT NULL,
  `date` varchar(10) NOT NULL,
  `motif` varchar(255) DEFAULT 'Indisponibilité stand',
  `annule_par` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `tir_annulations`
--

INSERT INTO `tir_annulations` (`id`, `agent`, `date`, `motif`, `annule_par`, `created_at`) VALUES
(27, 'BC BOUXOM', '2027-01-25', 'Indisponibilité stand', 23, '2026-03-16 14:37:30'),
(28, 'BC DAINOTTI', '2026-02-10', 'Indisponibilité stand', 23, '2026-03-23 10:13:34'),
(29, 'BC ARNAULT', '2026-02-10', 'Indisponibilité stand', 23, '2026-03-23 10:15:47'),
(30, 'BC HOCHARD', '2026-02-10', 'Indisponibilité stand', 23, '2026-03-23 10:16:00');

-- --------------------------------------------------------

--
-- Structure de la table `types_conges`
--

CREATE TABLE `types_conges` (
  `code` varchar(10) NOT NULL,
  `libelle` varchar(50) NOT NULL,
  `couleur_bg` varchar(7) NOT NULL,
  `couleur_txt` varchar(7) NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `types_conges`
--

INSERT INTO `types_conges` (`code`, `libelle`, `couleur_bg`, `couleur_txt`, `actif`) VALUES
('ASA', 'Autorisation Spéciale d\'Absence', '#ead1dc', '#000000', 1),
('AUT', 'Autres absences', '#b0bec5', '#212121', 1),
('CA', 'Congés Annuels', '#1565c0', '#ffffff', 1),
('CAA', 'Congés Annuels Antérieur', '#1565c0', '#ffffff', 1),
('CAM', 'CA Raison Médicale', '#1565c0', '#ffffff', 1),
('CET', 'Compte Épargne Temps', '#0d47a1', '#ffffff', 1),
('CF', 'Congé Férié', '#ffeb3b', '#c00000', 1),
('CLD', 'Congé Longue Durée', '#990000', '#ffffff', 1),
('CLM', 'Congé Longue Maladie', '#cc0000', '#ffffff', 1),
('CM', 'Congés Maladie', '#d0e0e3', '#000000', 1),
('CMO', 'Congé Maladie Ordinaire', '#ff6666', '#ffffff', 1),
('CONV', 'Convocation', '#fce5cd', '#000000', 1),
('DA', 'Départ Avancé', '#e2efda', '#000000', 1),
('GEM', 'Garde Enfant Malade', '#c9daf8', '#000000', 1),
('HP', 'Congés Annuel Hors Période', '#fff2cc', '#000000', 1),
('HPA', 'Congés Annuel Hors Période Antérieur', '#ffe699', '#000000', 1),
('HS', 'Heures Supplémentaires', '#f4cccc', '#000000', 1),
('PR', 'Prise Retardée', '#e2efda', '#000000', 1),
('PREV', 'Prévisionnel congé', '#ffcc80', '#7a3e00', 1),
('RCH', 'Repos Compensateur Badgé', '#4527a0', '#ffffff', 1),
('RCR', 'Repos Compensateur Reporté', '#6a1b9a', '#ffffff', 1),
('RPS', 'Repos de Pénibilité Spécifique', '#d9ead3', '#000000', 1),
('RTT', 'Réduction du Temps de Travail', '#d9ead3', '#000000', 1),
('STG', 'Stage', '#ead1dc', '#000000', 1),
('VEM', 'Visite / Examen Médical', '#cfe2f3', '#000000', 1);

-- --------------------------------------------------------

--
-- Structure de la table `types_conges_douane`
--

CREATE TABLE `types_conges_douane` (
  `code` varchar(10) NOT NULL,
  `libelle` varchar(60) NOT NULL,
  `couleur_bg` varchar(7) NOT NULL,
  `couleur_txt` varchar(7) NOT NULL DEFAULT '#000000',
  `actif` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `types_conges_douane`
--

INSERT INTO `types_conges_douane` (`code`, `libelle`, `couleur_bg`, `couleur_txt`, `actif`) VALUES
('AEA', 'Autorisation Exceptionnelle Abs.', '#ead1dc', '#000000', 1),
('CA', 'Conges Annuels', '#1565c0', '#ffffff', 1),
('CET', 'Compte Epargne Temps', '#d0cece', '#000000', 1),
('CM', 'Conges Maladie', '#d0e0e3', '#000000', 1),
('GEM', 'Garde Enfant Malade', '#c9daf8', '#000000', 1),
('NC', 'Journee Non Cotee', '#f4cccc', '#000000', 1),
('RC', 'Repos Compensatoire Jour Ferie', '#bfbfbf', '#333333', 1),
('RH', 'Repos Hebdomadaire', '#bfbfbf', '#333333', 1);

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `login` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nom` varchar(100) DEFAULT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `actif` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `must_change_password` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `users`
--

INSERT INTO `users` (`id`, `login`, `password`, `nom`, `role`, `actif`, `created_at`, `must_change_password`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrateur', 'admin', 1, '2026-03-04 15:18:00', 0),
(2, 'ROUSSEL', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'CoGe ROUSSEL', 'user', 1, '2026-03-05 13:22:26', 1),
(3, 'PARENT', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'LCL PARENT', 'user', 1, '2026-03-05 13:22:26', 1),
(4, 'MOREAU', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'IR MOREAU', 'user', 1, '2026-03-05 13:22:26', 1),
(5, 'MOKADEM', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'Cne MOKADEM', 'user', 1, '2026-03-05 13:22:26', 1),
(6, 'MASSON', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'BC MASSON', 'user', 1, '2026-03-05 13:22:26', 1),
(7, 'SIGAUD', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'BC SIGAUD', 'user', 1, '2026-03-05 13:22:26', 1),
(8, 'DAINOTTI', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'BC DAINOTTI', 'user', 1, '2026-03-05 13:22:26', 1),
(9, 'BOUXOM', '$2y$10$.S8Xnoi3eZ/vc9TO5lWmWO4MAwjrvjP.elDZd/TBu9IJqsHBLb6rq', 'BC BOUXOM', 'user', 1, '2026-03-05 13:22:26', 0),
(10, 'ARNAULT', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'BC ARNAULT', 'user', 1, '2026-03-05 13:22:26', 1),
(11, 'HOCHARD', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'BC HOCHARD', 'user', 1, '2026-03-05 13:22:26', 1),
(12, 'ANTHONY', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'BC ANTHONY', 'user', 1, '2026-03-05 13:22:26', 1),
(13, 'BASTIEN', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'BC BASTIEN', 'user', 1, '2026-03-05 13:22:26', 1),
(14, 'DUPUIS', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'BC DUPUIS', 'user', 1, '2026-03-05 13:22:26', 1),
(15, 'LEFEBVRE', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'ADJ LEFEBVRE', 'user', 1, '2026-03-05 13:22:26', 1),
(16, 'CORRARD', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'ADJ CORRARD', 'user', 1, '2026-03-05 13:22:26', 1),
(17, 'DHALLEWYN', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'GP DHALLEWYN', 'user', 1, '2026-03-05 13:22:26', 1),
(18, 'DELCROIX', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'BC DELCROIX', 'user', 1, '2026-03-05 13:22:26', 1),
(19, 'LAMBERT', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'ADC LAMBERT', 'user', 1, '2026-03-05 13:22:26', 1),
(20, 'DEMERVAL', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'ACP1 DEMERVAL', 'user', 1, '2026-03-05 13:22:26', 1),
(21, 'LOIN', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'ACP1 LOIN', 'user', 1, '2026-03-05 13:22:26', 1),
(22, 'MAES', '$2y$10$i8.Ho8LIGv.HuKlK.RkrpOFglo5TmiFho6jN4WJY/qmL8NHkAkvt2', 'AA MAES', 'user', 1, '2026-03-05 13:22:26', 1),
(23, 'DRUEZ', '$2y$10$oDchjUmWetEhTCHCnFXEKu5V7fhe5Cz6BcALKE8ePcUEAqWDPoFVu', 'BC DRUEZ', 'admin', 1, '2026-03-05 13:22:26', 0);

-- --------------------------------------------------------

--
-- Structure de la table `user_agents`
--

CREATE TABLE `user_agents` (
  `user_id` int(11) NOT NULL,
  `agent` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `user_agents`
--

INSERT INTO `user_agents` (`user_id`, `agent`) VALUES
(2, 'CoGe ROUSSEL'),
(3, 'LCL PARENT'),
(4, 'IR MOREAU'),
(5, 'Cne MOKADEM'),
(6, 'BC MASSON'),
(7, 'BC SIGAUD'),
(8, 'BC DAINOTTI'),
(9, 'BC BOUXOM'),
(10, 'BC ARNAULT'),
(11, 'BC HOCHARD'),
(12, 'BC ANTHONY'),
(13, 'BC BASTIEN'),
(14, 'BC DUPUIS'),
(15, 'ADJ CORRARD'),
(15, 'ADJ LEFEBVRE'),
(16, 'ADJ CORRARD'),
(16, 'ADJ LEFEBVRE'),
(17, 'GP DHALLEWYN'),
(18, 'BC DELCROIX'),
(19, 'ADC LAMBERT'),
(20, 'ACP1 DEMERVAL'),
(21, 'ACP1 LOIN'),
(22, 'AA MAES');

-- --------------------------------------------------------

--
-- Structure de la table `user_rights`
--

CREATE TABLE `user_rights` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `can_conge` tinyint(1) DEFAULT 1,
  `can_perm` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `vacation_overrides`
--

CREATE TABLE `vacation_overrides` (
  `id` int(11) NOT NULL,
  `agent` varchar(80) NOT NULL,
  `date` date NOT NULL,
  `vacation` enum('J','M','AM','NUIT') NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `agent_quotas`
--
ALTER TABLE `agent_quotas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_agent_annee_type` (`agent`,`annee`,`type_conge`);

--
-- Index pour la table `app_meta`
--
ALTER TABLE `app_meta`
  ADD PRIMARY KEY (`cle`);

--
-- Index pour la table `conges`
--
ALTER TABLE `conges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `type_conge` (`type_conge`);

--
-- Index pour la table `feries`
--
ALTER TABLE `feries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `date` (`date`);

--
-- Index pour la table `locks`
--
ALTER TABLE `locks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `locked_by` (`locked_by`),
  ADD KEY `idx_locks_agent_mois` (`scope`,`agent`,`mois`);

--
-- Index pour la table `locks_mois`
--
ALTER TABLE `locks_mois`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_agent_mois` (`agent`,`mois`);

--
-- Index pour la table `notes_evenements`
--
ALTER TABLE `notes_evenements`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `notes_messages`
--
ALTER TABLE `notes_messages`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `notes_mois`
--
ALTER TABLE `notes_mois`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_notes` (`annee`,`mois`);

--
-- Index pour la table `permanences`
--
ALTER TABLE `permanences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_agent_date` (`agent`,`date`);

--
-- Index pour la table `tir`
--
ALTER TABLE `tir`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_agent_date` (`agent`,`date`);

--
-- Index pour la table `tir_annulations`
--
ALTER TABLE `tir_annulations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_tir_annul` (`agent`,`date`);

--
-- Index pour la table `types_conges`
--
ALTER TABLE `types_conges`
  ADD PRIMARY KEY (`code`);

--
-- Index pour la table `types_conges_douane`
--
ALTER TABLE `types_conges_douane`
  ADD PRIMARY KEY (`code`);

--
-- Index pour la table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `login` (`login`);

--
-- Index pour la table `user_agents`
--
ALTER TABLE `user_agents`
  ADD PRIMARY KEY (`user_id`,`agent`);

--
-- Index pour la table `user_rights`
--
ALTER TABLE `user_rights`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user` (`user_id`);

--
-- Index pour la table `vacation_overrides`
--
ALTER TABLE `vacation_overrides`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_agent_date` (`agent`,`date`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `agent_quotas`
--
ALTER TABLE `agent_quotas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;

--
-- AUTO_INCREMENT pour la table `conges`
--
ALTER TABLE `conges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=428;

--
-- AUTO_INCREMENT pour la table `feries`
--
ALTER TABLE `feries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT pour la table `locks`
--
ALTER TABLE `locks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=101;

--
-- AUTO_INCREMENT pour la table `locks_mois`
--
ALTER TABLE `locks_mois`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=267;

--
-- AUTO_INCREMENT pour la table `notes_evenements`
--
ALTER TABLE `notes_evenements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT pour la table `notes_messages`
--
ALTER TABLE `notes_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT pour la table `notes_mois`
--
ALTER TABLE `notes_mois`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT pour la table `permanences`
--
ALTER TABLE `permanences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=375;

--
-- AUTO_INCREMENT pour la table `tir`
--
ALTER TABLE `tir`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT pour la table `tir_annulations`
--
ALTER TABLE `tir_annulations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT pour la table `user_rights`
--
ALTER TABLE `user_rights`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `vacation_overrides`
--
ALTER TABLE `vacation_overrides`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `locks`
--
ALTER TABLE `locks`
  ADD CONSTRAINT `locks_ibfk_1` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`);

--
-- Contraintes pour la table `user_agents`
--
ALTER TABLE `user_agents`
  ADD CONSTRAINT `user_agents_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- Schéma initial de l'outil de conformité RGESN
-- Compatible PostgreSQL 13+

CREATE TABLE IF NOT EXISTS projects (
    id          SERIAL PRIMARY KEY,
    name        TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Référentiel RGESN : données de référence, rechargées par le seed, jamais modifiées via l'UI.
CREATE TABLE IF NOT EXISTS criteria (
    code                     TEXT PRIMARY KEY,          -- '1.1', '2.10', '9.7'...
    theme_code               SMALLINT NOT NULL,         -- 1 à 9
    theme_label              TEXT NOT NULL,
    position                 SMALLINT NOT NULL,         -- ordre d'affichage dans la thématique
    question                 TEXT NOT NULL,
    priority                 TEXT NOT NULL CHECK (priority IN ('prioritaire', 'recommande', 'modere')),
    difficulty               TEXT CHECK (difficulty IN ('faible', 'moyen', 'fort')),
    applicability_default    TEXT NOT NULL DEFAULT 'all' CHECK (applicability_default IN ('all', 'conditional')),
    applicability_condition  TEXT NOT NULL DEFAULT '',
    metiers                  TEXT NOT NULL DEFAULT '',
    objectif                 TEXT NOT NULL DEFAULT '',
    mise_en_oeuvre            TEXT NOT NULL DEFAULT '',
    moyen_test                TEXT NOT NULL DEFAULT '',
    pour_aller_plus_loin      TEXT,
    referentiel_version       TEXT NOT NULL DEFAULT '2024'
);

CREATE INDEX IF NOT EXISTS idx_criteria_theme ON criteria (theme_code, position);

-- Questions de filtrage ("gating") posées une seule fois par évaluation,
-- permettant d'écarter en bloc les critères non applicables sans les poser un par un.
CREATE TABLE IF NOT EXISTS gating_questions (
    tag       TEXT PRIMARY KEY,
    label     TEXT NOT NULL,
    help_text TEXT NOT NULL DEFAULT '',
    position  SMALLINT NOT NULL DEFAULT 0
);

-- Association critère <-> tag de filtrage : un critère devient N/A automatiquement
-- si au moins un de ses tags associés est répondu "non" par le projet.
CREATE TABLE IF NOT EXISTS criteria_gating_tags (
    criteria_code TEXT NOT NULL REFERENCES criteria(code) ON DELETE CASCADE,
    gating_tag    TEXT NOT NULL REFERENCES gating_questions(tag) ON DELETE CASCADE,
    PRIMARY KEY (criteria_code, gating_tag)
);

-- Une évaluation = une campagne de diagnostic RGESN pour un projet, à une date donnée.
CREATE TABLE IF NOT EXISTS evaluations (
    id           SERIAL PRIMARY KEY,
    project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    label        TEXT NOT NULL DEFAULT '',
    status       TEXT NOT NULL DEFAULT 'in_progress' CHECK (status IN ('in_progress', 'completed')),
    score        NUMERIC(5,2),
    meta         JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_evaluations_project ON evaluations (project_id, created_at DESC);

-- Réponses aux questions de filtrage pour une évaluation donnée.
CREATE TABLE IF NOT EXISTS evaluation_gating_answers (
    evaluation_id INTEGER NOT NULL REFERENCES evaluations(id) ON DELETE CASCADE,
    gating_tag    TEXT NOT NULL REFERENCES gating_questions(tag) ON DELETE CASCADE,
    value         BOOLEAN NOT NULL,
    PRIMARY KEY (evaluation_id, gating_tag)
);

-- Réponse à chaque critère applicable pour une évaluation donnée.
CREATE TABLE IF NOT EXISTS evaluation_answers (
    evaluation_id INTEGER NOT NULL REFERENCES evaluations(id) ON DELETE CASCADE,
    criteria_code TEXT NOT NULL REFERENCES criteria(code) ON DELETE CASCADE,
    status        TEXT NOT NULL CHECK (status IN ('valide', 'non_valide', 'non_applicable', 'non_renseigne')),
    justification TEXT NOT NULL DEFAULT '',
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (evaluation_id, criteria_code)
);

-- Export horodaté du document de conformité (déclaration d'écoconception) généré pour une évaluation.
-- Le PDF n'est pas persisté sur disque : il est régénéré à la demande depuis html_content.
CREATE TABLE IF NOT EXISTS declarations (
    id            SERIAL PRIMARY KEY,
    evaluation_id INTEGER NOT NULL REFERENCES evaluations(id) ON DELETE CASCADE,
    html_content  TEXT NOT NULL,
    generated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_declarations_evaluation ON declarations (evaluation_id, generated_at DESC);

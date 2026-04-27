<?php
function initSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        email      TEXT UNIQUE NOT NULL,
        password   TEXT NOT NULL,
        name       TEXT NOT NULL,
        role       TEXT DEFAULT 'user' CHECK(role IN ('admin','user')),
        avatar_emoji TEXT DEFAULT '👤',
        created_at TEXT DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS families (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL,
        owner_id    INTEGER NOT NULL,
        invite_code TEXT UNIQUE NOT NULL,
        created_at  TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (owner_id) REFERENCES users(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS family_members (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        family_id INTEGER NOT NULL,
        user_id   INTEGER NOT NULL,
        FOREIGN KEY (family_id) REFERENCES families(id),
        FOREIGN KEY (user_id)   REFERENCES users(id),
        UNIQUE (family_id, user_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS family_profiles (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        family_id      INTEGER NOT NULL,
        name           TEXT NOT NULL,
        type           TEXT DEFAULT 'adult' CHECK(type IN ('adult','child')),
        portion_weight REAL DEFAULT 1.0,
        avatar_emoji   TEXT DEFAULT '👤',
        FOREIGN KEY (family_id) REFERENCES families(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS intolerances (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        profile_id INTEGER NOT NULL,
        label      TEXT NOT NULL,
        FOREIGN KEY (profile_id) REFERENCES family_profiles(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS meal_categories (
        id    INTEGER PRIMARY KEY AUTOINCREMENT,
        name  TEXT NOT NULL,
        emoji TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS meals (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        family_id     INTEGER NOT NULL,
        name          TEXT NOT NULL,
        emoji         TEXT DEFAULT '🍽️',
        category_id   INTEGER,
        cal_per_adult INTEGER DEFAULT 0,
        notes         TEXT,
        created_by    INTEGER,
        created_at    TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (family_id)   REFERENCES families(id),
        FOREIGN KEY (category_id) REFERENCES meal_categories(id),
        FOREIGN KEY (created_by)  REFERENCES users(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS meal_ingredients (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        meal_id           INTEGER NOT NULL,
        name              TEXT NOT NULL,
        quantity          REAL,
        unit              TEXT,
        price_est         REAL DEFAULT 0.0,
        intolerance_flags TEXT DEFAULT '',
        zone              TEXT DEFAULT 'scaffali',
        FOREIGN KEY (meal_id) REFERENCES meals(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS schedule (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        family_id      INTEGER NOT NULL,
        week_start     TEXT NOT NULL,
        day_index      INTEGER NOT NULL,
        slot           TEXT NOT NULL CHECK(slot IN ('colazione','pranzo','cena')),
        meal_id        INTEGER,
        is_exception   INTEGER DEFAULT 0,
        exception_note TEXT,
        created_by     INTEGER,
        FOREIGN KEY (family_id) REFERENCES families(id),
        FOREIGN KEY (meal_id)   REFERENCES meals(id),
        UNIQUE (family_id, week_start, day_index, slot)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shopping_items (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        family_id       INTEGER NOT NULL,
        week_start      TEXT NOT NULL,
        ingredient_name TEXT NOT NULL,
        quantity        REAL,
        unit            TEXT,
        price_est       REAL DEFAULT 0.0,
        price_actual    REAL,
        checked         INTEGER DEFAULT 0,
        zone            TEXT DEFAULT 'scaffali',
        zone_order      INTEGER DEFAULT 6,
        meal_id         INTEGER,
        checked_by      INTEGER REFERENCES users(id),
        checked_at      TEXT,
        FOREIGN KEY (family_id) REFERENCES families(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ingredient_prices (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        family_id       INTEGER NOT NULL,
        ingredient_name TEXT NOT NULL,
        price           REAL NOT NULL,
        unit            TEXT,
        recorded_at     TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (family_id) REFERENCES families(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS nutrition_db (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        name         TEXT NOT NULL UNIQUE,
        kcal_100g    REAL NOT NULL,
        zone         TEXT DEFAULT 'scaffali',
        aliases      TEXT DEFAULT '[]',
        unit_weights TEXT DEFAULT '{}'
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pantry_items (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        family_id       INTEGER NOT NULL,
        ingredient_name TEXT NOT NULL,
        quantity        REAL,
        unit            TEXT,
        zone            TEXT DEFAULT 'scaffali',
        expiry_date     TEXT,
        updated_at      TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (family_id) REFERENCES families(id),
        UNIQUE (family_id, ingredient_name, unit)
    )");

    // Migration: share_token (safe on existing DB)
    try { $pdo->exec("ALTER TABLE families ADD COLUMN share_token TEXT UNIQUE"); } catch (Exception $e) {}
    // Migration: is_system meals
    try { $pdo->exec("ALTER TABLE meals ADD COLUMN is_system INTEGER DEFAULT 0"); } catch (Exception $e) {}
    // Migration: shopping_items — articoli manuali e zona custom
    try { $pdo->exec("ALTER TABLE shopping_items ADD COLUMN is_manual INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE shopping_items ADD COLUMN custom_zone TEXT DEFAULT 'altro'"); } catch (Exception $e) {}
    // Migration: pantry_items — articoli manuali
    try { $pdo->exec("ALTER TABLE pantry_items ADD COLUMN is_manual INTEGER DEFAULT 0"); } catch (Exception $e) {}

    // Indici per query frequenti
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_schedule_family_week ON schedule (family_id, week_start)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_shopping_family_week ON shopping_items (family_id, week_start)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_meals_family        ON meals (family_id, is_system)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pantry_family       ON pantry_items (family_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ingredients_meal    ON meal_ingredients (meal_id)");

    // Seed categorie
    $pdo->exec("INSERT OR IGNORE INTO meal_categories (id, name, emoji) VALUES
        (1,'Colazione','☀️'),
        (2,'Primo','🍝'),
        (3,'Secondo','🥩'),
        (4,'Contorno','🥗'),
        (5,'Altro','🍽️')
    ");

    // Seed prodotti casalinghi in nutrition_db
    $pdo->exec("INSERT OR IGNORE INTO nutrition_db (name, kcal_100g, zone) VALUES
        ('carta igienica',0,'casalinghi'),
        ('fazzoletti',0,'casalinghi'),
        ('carta da cucina',0,'casalinghi'),
        ('sacchetti spazzatura',0,'casalinghi'),
        ('sacchetti freezer',0,'casalinghi'),
        ('pellicola trasparente',0,'casalinghi'),
        ('alluminio',0,'casalinghi'),
        ('detersivo piatti liquido',0,'casalinghi'),
        ('detersivo lavastoviglie',0,'casalinghi'),
        ('sale lavastoviglie',0,'casalinghi'),
        ('brillantante lavastoviglie',0,'casalinghi'),
        ('detersivo lavatrice',0,'casalinghi'),
        ('ammorbidente',0,'casalinghi'),
        ('candeggina',0,'casalinghi'),
        ('anticalcare',0,'casalinghi'),
        ('detersivo bagno',0,'casalinghi'),
        ('detersivo pavimenti',0,'casalinghi'),
        ('sgrassatore',0,'casalinghi'),
        ('vetri spray',0,'casalinghi'),
        ('spugne',0,'casalinghi'),
        ('pagliette',0,'casalinghi'),
        ('guanti gomma',0,'casalinghi'),
        ('scopino WC',0,'casalinghi'),
        ('deodorante WC',0,'casalinghi'),
        ('profumatore ambiente',0,'casalinghi'),
        ('sapone mani liquido',0,'casalinghi'),
        ('shampoo',0,'casalinghi'),
        ('balsamo capelli',0,'casalinghi'),
        ('bagnoschiuma',0,'casalinghi'),
        ('sapone solido',0,'casalinghi'),
        ('dentifricio',0,'casalinghi'),
        ('spazzolino',0,'casalinghi'),
        ('filo interdentale',0,'casalinghi'),
        ('collutorio',0,'casalinghi'),
        ('rasoi',0,'casalinghi'),
        ('schiuma da barba',0,'casalinghi'),
        ('cotone idrofilo',0,'casalinghi'),
        ('cerotti',0,'casalinghi'),
        ('paracetamolo',0,'casalinghi'),
        ('ibuprofene',0,'casalinghi'),
        ('aspirina',0,'casalinghi'),
        ('disinfettante',0,'casalinghi'),
        ('garze',0,'casalinghi'),
        ('bende',0,'casalinghi'),
        ('termometro',0,'casalinghi'),
        ('batterie AA',0,'casalinghi'),
        ('batterie AAA',0,'casalinghi'),
        ('lampadine',0,'casalinghi'),
        ('pile stilo',0,'casalinghi'),
        ('nastro adesivo',0,'casalinghi'),
        ('spago',0,'casalinghi'),
        ('penne',0,'casalinghi'),
        ('quaderni',0,'casalinghi'),
        ('buste',0,'casalinghi'),
        ('francobolli',0,'casalinghi'),
        ('detersivo multiuso',0,'casalinghi'),
        ('spray disinfettante superfici',0,'casalinghi'),
        ('salviettine umidificate',0,'casalinghi'),
        ('assorbenti',0,'casalinghi'),
        ('pannolini',0,'casalinghi'),
        ('salviettine bebè',0,'casalinghi'),
        ('crema mani',0,'casalinghi'),
        ('dopobarba',0,'casalinghi'),
        ('acqua ossigenata',0,'casalinghi')
    ");
}

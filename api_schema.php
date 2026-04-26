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

    // Seed categorie
    $pdo->exec("INSERT OR IGNORE INTO meal_categories (id, name, emoji) VALUES
        (1,'Colazione','☀️'),
        (2,'Primo','🍝'),
        (3,'Secondo','🥩'),
        (4,'Contorno','🥗'),
        (5,'Altro','🍽️')
    ");
}

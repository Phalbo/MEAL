# 🍽️ Meal Planner v2 — Documentazione as-built

Stack: **PHP 8.1 · SQLite 3 · Vanilla JS**. Zero dipendenze esterne, zero server di database.

---

## Stack tecnico

| Layer | Tecnologia |
|---|---|
| Frontend | HTML5 + CSS3 + JavaScript Vanilla (no framework) |
| Backend | PHP 8.1+ |
| Database | SQLite 3 — file `data/meal_planner.db` |
| Auth | **Dev mode: login disabilitato** — utente/famiglia id=1 creati automaticamente |
| Font | Playfair Display (titoli) + DM Sans (corpo) — Google Fonts |

**NON usare:** React, Vue, Angular, Bootstrap, jQuery, Tailwind, MySQL.

---

## Avvio rapido

```bash
# qualsiasi server PHP funziona:
php -S localhost:8080
# oppure XAMPP/Laragon/Apache

# al primo accesso:
# 1. api_schema.php crea il DB SQLite e tutte le tabelle
# 2. seedIfEmpty() carica nutrition.json (216 ingredienti) + ricette.xlsx (120 ricette)
#    Se ZipArchive non è disponibile, usa 20 ricette hardcoded come fallback
# 3. Nessun login necessario — si entra direttamente in index.php
```

**Requisiti PHP:** `pdo_sqlite`, opzionale `zip` (per seed da xlsx).

---

## Struttura file

```
meal-planner/
├── index.php               # Planner settimanale
├── admin.php               # Gestione ricette
├── family.php              # Gestione famiglia, profili, intolleranze
├── lista.php               # Lista spesa live (ottimizzata mobile)
├── pantry.php              # Gestione dispensa
├── ingredienti.php         # DB ingredienti editabile (nutrition_db)
├── export_import.php       # Export/import CSV
├── login.php               # Pagina login (non usata in dev mode)
│
├── api.php                 # Entry point API JSON — router unico
├── api_schema.php          # CREATE TABLE + migrations + seed
├── api_auth.php            # login, register, logout, me, password reset
├── api_family.php          # famiglia, profili, intolleranze, share token
├── api_meals.php           # piatti CRUD + toggle_favorite + search_by_ingredient
├── api_schedule.php        # calendario CRUD + autofill + copy_prev + set_kids
├── api_shopping.php        # lista spesa generate/check/price + manual + pub
├── api_pantry.php          # dispensa CRUD
├── api_nutrition.php       # nutrition_db search/list/update/add/delete
├── api_export_import.php   # export CSV + import CSV
├── nutrition_functions.php # lookupKcal(), toGrams(), calcMealKcal()
│
├── app.js                  # Planner: state, API helpers, init, sidebar, controls
├── app_ui.js               # Planner: calendario, drag&drop, calorie, shopping
├── admin.js                # Ricette: tabella + form + autocomplete ingredienti
├── pantry.js               # Dispensa: lista + form + autocomplete
├── lista.js                # Lista spesa live: render + inline editing + autocomplete
├── utils.js                # initAutocomplete() — condiviso da admin/pantry/lista
│
├── style.css               # Unico foglio di stile
├── lista.css               # Stili aggiuntivi lista spesa
├── config.php              # Costanti: DB_PATH, ZONE_ORDER, INTOLERANCE_PRESETS
│
├── nutrition.json          # 216 ingredienti italiani (kcal, zone, aliases, unit_weights)
├── ricette.xlsx            # 120 ricette di sistema (richiede ZipArchive)
│
└── data/
    ├── meal_planner.db     # SQLite (creato automaticamente)
    └── .htaccess           # Deny from all
```

---

## Dev mode — nessun login

Tutti i file PHP hanno la sessione auto-popolata:

```php
// in ogni .php:
if (empty($_SESSION['user_id']))   $_SESSION['user_id']   = 1;
if (empty($_SESSION['family_id'])) $_SESSION['family_id'] = 1;

// in api.php — bootSession() assicura che user/famiglia id=1 esistano nel DB:
function bootSession(PDO $pdo): void {
    if (empty($_SESSION['user_id'])) {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec("INSERT OR IGNORE INTO users (id,email,password,name,role)
                    VALUES (1,'admin@local','','Admin','admin')");
        $pdo->exec("INSERT OR IGNORE INTO families (id,name,owner_id,invite_code)
                    VALUES (1,'Famiglia',1,'LOCAL0001')");
        $pdo->exec("INSERT OR IGNORE INTO family_members (family_id,user_id) VALUES (1,1)");
        $pdo->exec('PRAGMA foreign_keys = ON');
        $_SESSION['user_id']   = 1;
        $_SESSION['family_id'] = 1;
    }
}
```

`verifyCsrf()` è no-op in dev mode. Il token CSRF viene comunque generato e inviato (per compatibilità futura), ma non verificato.

---

## Schema SQLite completo

Lo schema viene creato da `initSchema()` in `api_schema.php`, chiamata da `getDB()` ad ogni request. Usa `CREATE TABLE IF NOT EXISTS` + `ALTER TABLE ... ADD COLUMN` in try/catch per le migration.

```sql
-- Utenti
CREATE TABLE IF NOT EXISTS users (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  email        TEXT UNIQUE NOT NULL,
  password     TEXT NOT NULL,
  name         TEXT NOT NULL,
  role         TEXT DEFAULT 'user' CHECK(role IN ('admin','user')),
  avatar_emoji TEXT DEFAULT '👤',
  created_at   TEXT DEFAULT (datetime('now'))
);

-- Famiglie
CREATE TABLE IF NOT EXISTS families (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT NOT NULL,
  owner_id     INTEGER NOT NULL,
  invite_code  TEXT UNIQUE NOT NULL,
  share_token  TEXT UNIQUE,           -- per link pubblico lista spesa
  created_at   TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (owner_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS family_members (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  family_id INTEGER NOT NULL,
  user_id   INTEGER NOT NULL,
  FOREIGN KEY (family_id) REFERENCES families(id),
  FOREIGN KEY (user_id)   REFERENCES users(id),
  UNIQUE (family_id, user_id)
);

-- Profili (anche non-utenti: figli, partner senza account)
CREATE TABLE IF NOT EXISTS family_profiles (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  family_id      INTEGER NOT NULL,
  name           TEXT NOT NULL,
  type           TEXT DEFAULT 'adult' CHECK(type IN ('adult','child')),
  portion_weight REAL DEFAULT 1.0,
  avatar_emoji   TEXT DEFAULT '👤',
  FOREIGN KEY (family_id) REFERENCES families(id)
);

CREATE TABLE IF NOT EXISTS intolerances (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  profile_id INTEGER NOT NULL,
  label      TEXT NOT NULL,
  FOREIGN KEY (profile_id) REFERENCES family_profiles(id) ON DELETE CASCADE
);

-- Categorie piatti (seed fisso: Colazione/Primo/Secondo/Contorno/Altro)
CREATE TABLE IF NOT EXISTS meal_categories (
  id    INTEGER PRIMARY KEY AUTOINCREMENT,
  name  TEXT NOT NULL,
  emoji TEXT
);

-- Piatti
CREATE TABLE IF NOT EXISTS meals (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  family_id     INTEGER NOT NULL,   -- 0 per piatti di sistema (is_system=1)
  name          TEXT NOT NULL,
  emoji         TEXT DEFAULT '🍽️',
  category_id   INTEGER,
  cal_per_adult INTEGER DEFAULT 0,
  notes         TEXT,
  is_system     INTEGER DEFAULT 0,  -- 1 = piatto di sistema (seed), non modificabile
  use_count     INTEGER DEFAULT 0,  -- incrementato ogni volta che viene pianificato
  is_favorite   INTEGER DEFAULT 0,  -- toggle stella ⭐
  created_by    INTEGER,
  created_at    TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (category_id) REFERENCES meal_categories(id)
);

CREATE TABLE IF NOT EXISTS meal_ingredients (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  meal_id           INTEGER NOT NULL,
  name              TEXT NOT NULL,
  quantity          REAL,
  unit              TEXT,
  price_est         REAL DEFAULT 0.0,
  intolerance_flags TEXT DEFAULT '',   -- CSV: "lattosio,glutine"
  zone              TEXT DEFAULT 'scaffali',
  FOREIGN KEY (meal_id) REFERENCES meals(id) ON DELETE CASCADE
);

-- Calendario
CREATE TABLE IF NOT EXISTS schedule (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  family_id         INTEGER NOT NULL,
  week_start        TEXT NOT NULL,       -- YYYY-MM-DD (sempre lunedì)
  day_index         INTEGER NOT NULL,    -- 0=lun ... 6=dom
  slot              TEXT NOT NULL CHECK(slot IN ('colazione','pranzo','cena')),
  meal_id           INTEGER,
  is_exception      INTEGER DEFAULT 0,
  exception_note    TEXT,
  side_dish         TEXT,               -- contorno libre
  extra_note        TEXT,               -- nota breve
  slot_kids         TEXT,               -- piatto alternativo bambini (solo pranzo)
  portions_override REAL DEFAULT NULL,  -- sovrascrive Σ portion_weight per questa cella
  created_by        INTEGER,
  UNIQUE (family_id, week_start, day_index, slot),
  FOREIGN KEY (family_id) REFERENCES families(id),
  FOREIGN KEY (meal_id)   REFERENCES meals(id)
);

-- Lista spesa
CREATE TABLE IF NOT EXISTS shopping_items (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  family_id        INTEGER NOT NULL,
  week_start       TEXT NOT NULL,
  ingredient_name  TEXT NOT NULL,
  quantity         REAL,
  unit             TEXT,
  price_est        REAL DEFAULT 0.0,    -- stimato da nutrition_db via toGrams()
  price_actual     REAL,                -- inserito manualmente, salvato in ingredient_prices
  checked          INTEGER DEFAULT 0,
  checked_by       INTEGER,             -- FK users
  checked_at       TEXT,
  zone             TEXT DEFAULT 'scaffali',
  zone_order       INTEGER DEFAULT 6,
  is_manual        INTEGER DEFAULT 0,   -- 1 = aggiunto manualmente (non rigenerato)
  custom_zone      TEXT DEFAULT 'altro',
  FOREIGN KEY (family_id) REFERENCES families(id)
);

-- Storico prezzi reali
CREATE TABLE IF NOT EXISTS ingredient_prices (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  family_id       INTEGER NOT NULL,
  ingredient_name TEXT NOT NULL,
  price           REAL NOT NULL,
  unit            TEXT,
  recorded_at     TEXT DEFAULT (datetime('now'))
);

-- Dispensa
CREATE TABLE IF NOT EXISTS pantry_items (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  family_id        INTEGER NOT NULL,
  ingredient_name  TEXT NOT NULL,
  quantity         REAL,
  unit             TEXT,
  zone             TEXT DEFAULT 'scaffali',
  expiry_date      TEXT,
  is_manual        INTEGER DEFAULT 0,
  updated_at       TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (family_id) REFERENCES families(id)
);

-- DB nutrizionale (216 ingredienti italiani)
CREATE TABLE IF NOT EXISTS nutrition_db (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT NOT NULL UNIQUE,
  kcal_100g    REAL NOT NULL DEFAULT 0,
  zone         TEXT DEFAULT 'scaffali',
  aliases      TEXT DEFAULT '[]',       -- JSON array
  unit_weights TEXT DEFAULT '{}',       -- JSON object {"pz":120,"cucchiaio":15}
  price_est    REAL DEFAULT 0           -- €/kg, usato per stimare costo lista spesa
);

-- Password reset tokens
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  token      TEXT NOT NULL UNIQUE,
  expires_at TEXT NOT NULL,
  used       INTEGER DEFAULT 0
);
```

---

## Seed al primo avvio

`seedIfEmpty()` in `api_schema.php` viene chiamata dentro `initSchema()`, avvolta in `try { } catch (\Throwable $e) {}`.

1. **nutrition_db**: carica `nutrition.json` se `aglio` non è già nel DB.
2. **meals (sistema)**: se nessun piatto con `is_system=1` esiste:
   - prova `parseXlsxRecipes('ricette.xlsx')` — richiede PHP `ZipArchive`
   - se restituisce `[]` (ZipArchive assente o xlsx mancante) → usa `getHardcodedMeals()` (20 ricette hardcoded)

Per forzare il re-seed: cancella `data/meal_planner.db`.

---

## API endpoints completi

Tutti via `GET ?action=X` o `POST` con body JSON `{action, csrf_token, ...}`.

### Auth
| action | method | params | risposta |
|---|---|---|---|
| `csrf_token` | GET | — | `{token}` |
| `login` | POST | email, password | `{success, user}` |
| `register` | POST | name, email, password | `{success, user}` |
| `logout` | POST | — | `{success}` |
| `me` | GET | — | `{user, family}` |
| `password_reset_request` | POST | email | `{success}` |
| `password_reset_do` | POST | token, password | `{success}` |

### Famiglia
| action | method | params | risposta |
|---|---|---|---|
| `family_create` | POST | name | `{family}` |
| `family_join` | POST | invite_code | `{success}` |
| `family_members` | GET | — | `[members]` |
| `family_share_token` | GET | — | `{token}` |
| `profiles_list` | GET | — | `[profiles]` |
| `profiles_add` | POST | name, type, portion_weight, avatar_emoji | `{profile}` |
| `profiles_update` | POST | id, ...fields | `{success}` |
| `profiles_delete` | POST | id | `{success}` |
| `intolerance_add` | POST | profile_id, label | `{success}` |
| `intolerance_delete` | POST | id | `{success}` |
| `intolerance_list_by_profile` | GET | — | `[intolerances]` |

### Piatti
| action | method | params | risposta |
|---|---|---|---|
| `categories_list` | GET | — | `[categories]` |
| `meals_list` | GET | ?category_id, ?q | `[meals + ingredients[]]` |
| `meals_add` | POST | name, emoji, category_id, cal_per_adult, ingredients[] | `{meal}` |
| `meals_update` | POST | id, ...fields, ?ingredients[] | `{success}` |
| `meals_delete` | POST | id | `{success}` |
| `meals_toggle_favorite` | POST | id | `{success, is_favorite}` |
| `meals_search_by_ingredient` | GET | ingredient | `[meals]` |
| `meal_ingredients_preview` | GET | meal_id | `{meal, ingredients[]}` |

### Calendario
| action | method | params | risposta |
|---|---|---|---|
| `schedule_get` | GET | week_start | `[rows]` (include slot_kids, portions_override) |
| `schedule_set` | POST | week_start, day_index, slot, meal_id | `{success}` — incrementa use_count |
| `schedule_clear` | POST | week_start | `{success}` |
| `schedule_exception` | POST | schedule_id, exception_note, is_exception | `{success}` |
| `schedule_copy` | POST | from_week, to_week | `{copied}` — sovrascrive |
| `schedule_copy_prev` | POST | week_start | `{copied}` — INSERT OR IGNORE (preserva celle esistenti + copia slot_kids) |
| `schedule_autofill` | POST | week_start | `{success, filled}` |
| `schedule_update_extras` | POST | week_start, day_index, slot, side_dish, extra_note, ?portions_override | `{success}` |
| `schedule_random_replace` | POST | week_start, day_index, slot | meal row |
| `schedule_set_kids` | POST | week_start, day_index, slot, slot_kids | `{success}` |

### Lista spesa
| action | method | params | risposta |
|---|---|---|---|
| `shopping_generate` | POST | week_start | `[items]` — preserva is_manual=1; price_est = toGrams×price_per_kg |
| `shopping_list` | GET | week_start, ?since | `[items]` (delta se since) |
| `shopping_check` | POST | id, checked | `{success, checked_by, checked_at}` |
| `shopping_price_update` | POST | id, price_actual | `{success}` — salva in ingredient_prices |
| `shopping_update_item` | POST | id, ?quantity, ?unit | `{success}` |
| `shopping_add_manual` | POST | name, quantity, unit, zone, week_start | `{item}` |
| `shopping_clear` | POST | week_start, mode (all\|checked) | `{success}` |
| `shopping_reset_checks` | POST | week_start | `{success}` |
| `shopping_export_text` | GET | week_start | plain text con totale |
| `shopping_list_pub` | GET | token, week_start | `[items]` (pubblico via share_token) |
| `shopping_check_pub` | POST | token, id, checked | `{success}` |

### Dispensa
| action | method | params | risposta |
|---|---|---|---|
| `pantry_list` | GET | — | `[items]` |
| `pantry_update` | POST | id, ingredient_name, quantity, unit, zone, expiry_date | `{success}` |
| `pantry_delete` | POST | id | `{success}` |
| `pantry_add_manual` | POST | ingredient_name, quantity, unit, zone | `{item}` |
| `pantry_from_shopping` | POST | week_start, item_ids[] | `{success}` |
| `pantry_consume` | POST | id, quantity | `{success}` |
| `pantry_clear` | POST | — | `{success}` |

### Ingredienti (nutrition_db)
| action | method | params | risposta |
|---|---|---|---|
| `nutrition_search` | GET | q, ?zone, ?limit | `[items]` — per autocomplete |
| `nutrition_list` | GET | ?q, ?zone, ?offset, ?limit | `{items[], total}` — paginato |
| `nutrition_lookup` | GET | name | `{kcal_100g, zone}` |
| `nutrition_update` | POST | id, ?name, ?kcal_100g, ?price_est, ?zone | item aggiornato |
| `nutrition_add` | POST | name, kcal_100g, price_est, zone, ?unit_weights | item creato |
| `nutrition_delete` | POST | id | `{success}` |

### Export/Import
| action | method | params | risposta |
|---|---|---|---|
| `export_csv` | GET | — | stream CSV |
| `import_csv` | POST | multipart file | `{success, imported}` |

---

## config.php

```php
define('DB_PATH',          __DIR__ . '/data/meal_planner.db');
define('APP_NAME',         'Meal Planner');
define('SESSION_LIFETIME', 60 * 60 * 24 * 30);

define('INTOLERANCE_PRESETS', [
    'lattosio','glutine','nichel','uova crude','peperoni',
    'arachidi','frutta secca','crostacei','soia','senape',
]);

define('PORTION_ADULT', 1.0);
define('PORTION_CHILD', 0.6);

define('ZONE_ORDER', [
    'ortofrutta' => 1,  // frutta, verdura, erbe
    'pane'       => 2,  // pane, cornetti, crackers
    'macelleria' => 3,  // carne, salumi
    'pesce'      => 4,  // pesce, tonno
    'latticini'  => 5,  // uova, formaggi, yogurt, burro
    'scaffali'   => 6,  // pasta, riso, conserve, olio
    'bevande'    => 7,  // acqua, vino, succhi
    'surgelati'  => 8,  // ultimi (per non sgelarli)
    'altro'      => 9,
]);
```

---

## Pagine

### `index.php` — Planner settimanale
Script: `app.js` + `app_ui.js`

- Sidebar sinistra: lista piatti con drag, ricerca, filtro categoria, stella ⭐ per favoriti
- Calendario 7×3 (giorni × colazione/pranzo/cena) con drag & drop e touch
- Per ogni cella piena:
  - 🎲 dado → sostituzione casuale rispettando categoria e intolleranze
  - 📝 eccezione → nota testo libero
  - Contorno/nota extra → inline form
  - +/− porzioni → sovrascrive `portions_override` per quella cella
  - 🧒 slot bambini → input testuale (solo celle pranzo)
- Pulsanti header: Popola (autofill), 📋 Copia settimana precedente (INSERT OR IGNORE), Svuota, Genera lista
- Sezione calorie per giorno (barre, scalate per porzioni, dettaglio per profilo)
- Sezione lista spesa con toggle Lista/Riquadri e prezzi inline
- Navigazione settimane prev/next

State in `app.js`:
```js
const state = {
  user, family, week,  // 'YYYY-MM-DD' (lunedì)
  schedule,            // key: `${dayIdx}_${slotIdx}` → meal obj
  meals, profiles
};
```

Ogni entry di `state.schedule` contiene: `id, name, emoji, cal_per_adult, category, schedule_id, exception_note, is_exception, side_dish, extra_note, slot_kids, portions_override`.

### `admin.php` — Gestione ricette
Script: `utils.js` + `admin.js`

- Tabella piatti con modifica/elimina
- Form aggiunta/modifica: nome, emoji, categoria, kcal, ingredienti
- Ogni riga ingrediente: nome (con **autocomplete** da `nutrition_db`), qtà, unità, flag intolleranze
- Autocomplete auto-compila la prima unità da `unit_weights` e mostra kcal in toast

### `ingredienti.php` — DB ingredienti
Script inline (no file esterno)

- Tabella `nutrition_db` editabile inline (click su cella → input/select)
- Barra di ricerca + filtro zona
- Form aggiunta nuovo ingrediente: nome, kcal, prezzo €/kg, zona
- Paginazione "Carica altri" (100 a blocchi)
- Campi editabili: nome, kcal/100g, prezzo €/kg, zona

### `lista.php` — Lista spesa live
Script: `utils.js` + `lista.js`

- Header con totale prezzo (sempre visibile), badge LIVE, pulsanti azione
- Barra "Cerca ricetta" → dropdown ricette → modal preview ingredienti → aggiunta selettiva
- Barra aggiunta manuale con **autocomplete** (auto-compila zona)
- Articoli raggruppati per zona in ordine ZONE_ORDER
- Ogni articolo:
  - Checkbox per spuntare (salva `checked_by + checked_at`)
  - Qty+unit **inline editabili** (click → input → save via `shopping_update_item`)
  - Prezzo **inline editabile** (click → input → save via `shopping_price_update` → storico)
- Prezzi stimati in grigio, prezzi reali in verde

### `pantry.php` — Dispensa
Script: `utils.js` + `pantry.js`

- Lista ingredienti in dispensa con quantità e scadenza
- Form aggiunta con **autocomplete** sul nome (auto-compila zona)
- Import da lista spesa (seleziona articoli acquistati)
- Consumo parziale

### `family.php` — Famiglia
- Gestione profili (nome, tipo adulto/bambino, peso porzione, avatar)
- Intolleranze per profilo
- Codice invito famiglia
- Link condivisione lista spesa pubblica

---

## utils.js — Autocomplete condiviso

```js
/**
 * initAutocomplete(inputEl, onSelect, options?)
 *
 * Attacca un dropdown autocomplete a inputEl basato su nutrition_db.
 * onSelect(item) viene chiamata con { id, name, zone, kcal_100g, price_est, unit_weights }
 *
 * options:
 *   apiBase    {string}   default 'api.php'
 *   limit      {number}   default 8
 *   zone       {string}   filtra per zona
 *   showPrice  {boolean}  default true
 *   showZone   {boolean}  default true
 */
function initAutocomplete(inputEl, onSelect, options = {}) { ... }
```

- 300ms debounce su input → `GET api.php?action=nutrition_search&q=...`
- Keyboard: ArrowUp/Down, Enter seleziona, Escape chiude
- Inietta `<style id="ac-styles">` una sola volta nel `<head>`
- Usato in: `admin.js` (campo `.ing-name`), `pantry.js` (campo `#pf-name`), `lista.js` (campo `#add-name`)

---

## nutrition_functions.php

```php
lookupKcal($pdo, 'mozzarella')                   // → 253.0
toGrams(2, 'cucchiai', 'olio evo', $pdo)         // → 30.0
toGrams(1, 'pz', 'carota', $pdo)                 // → 80.0
calcIngredientKcal($pdo, 'spaghetti', 320, 'g')  // → ['grams'=>320,'kcal'=>1187.2,'found'=>true]
calcMealKcal($pdo, $ingredients)                  // → ['kcal_totali'=>1650,'non_trovati'=>['pepe q.b.']]
calcMealKcalPerPorzione($pdo, $ingredients, $profili)
```

**Matematica prezzi lista spesa** (`shopping_generate`):
```
grams          = toGrams(quantity, unit, ingredient_name, $pdo)
price_est_item = (grams / 1000) × nutrition_db.price_est  [€/kg]
```
Sovrascrive con media ultimi 3 prezzi reali da `ingredient_prices` se disponibile.

---

## Logica calendario

### `schedule_set`
```php
INSERT INTO schedule ... ON CONFLICT DO UPDATE SET meal_id=...
UPDATE meals SET use_count = use_count + 1 WHERE id = ?
```

### `schedule_copy_prev`
```php
// Copia settimana precedente (week_start - 7 giorni) → week_start
// INSERT OR IGNORE: preserva celle già occupate; copia anche slot_kids
```

### `schedule_autofill`
- Regole slot: colazione → Colazione; pranzo → Primo/Secondo/Altro; cena → Secondo/Altro
- Esclude piatti con intolleranze familiari
- Evita categoria ripetuta rispetto al giorno precedente

### `schedule_random_replace`
- Rispetta categoria dello slot, esclude intolleranze
- Preferisce piatti non ancora usati nella settimana
- Restituisce: `{id, name, emoji, cal_per_adult, category, schedule_id, side_dish, extra_note, slot_kids, portions_override}`

---

## Topbar navigazione (tutte le pagine)

```html
<nav class="topbar-nav">
  <a href="index.php">📅 Pianifica</a>
  <a href="admin.php">🍝 Ricette</a>
  <a href="family.php">👥 Famiglia</a>
  <a href="lista.php">🛒 Spesa</a>
  <a href="pantry.php">🏪 Dispensa</a>
  <a href="ingredienti.php">🧂 Ingredienti</a>
  <a href="export_import.php">📦 Import/Export</a>
</nav>
```

Bottom nav mobile (index.php, lista.php, pantry.php, admin.php):
```html
<nav class="bottom-nav">
  <a href="index.php">📅 Pianifica</a>
  <a href="admin.php">🍝 Ricette</a>
  <button class="bottom-nav-fab">🎲</button>
  <a href="lista.php">🛒 Spesa</a>
  <a href="family.php">👥 Famiglia</a>
</nav>
```

---

## Note per ridevelopment

1. **PHP 8.0+ obbligatorio** per `match`, `named arguments`, `union types`.
2. **Tutto in `api.php`**: router unico `match ($action) { ... }`. Aggiungi nuove azioni qui + nuovo `require_once api_xxx.php`.
3. **Migrations sicure**: `try { $pdo->exec("ALTER TABLE ..."); } catch (\Throwable $e) {}`.
4. **Seed** wrappato in `catch (\Throwable $e)` (non solo Exception) per gestire `ZipArchive` mancante.
5. **CSRF** disabilitato in dev mode — `verifyCsrf()` è no-op. Per produzione: riabilitare.
6. **Shopping generate** cancella solo `is_manual=0` — gli articoli manuali sopravvivono alla rigenerazione.
7. **`nutrition_db.price_est`** è in €/kg. Il costo stimato per un ingrediente è `toGrams(qty,unit,name)/1000 × price_est`.
8. **Lista pubblica** (`lista_pub.php`): accesso anonimo via `families.share_token`, solo check/uncheck.
9. **Font**: caricare Google Fonts `Playfair+Display:wght@400;700&family=DM+Sans:wght@400;500;600`.
10. **Palette CSS**: `--terra: #FF6B4A`, `--primary-soft: #FFE8E2`, `--green: #4CAF50`, `--card: #fff`, `--border-soft: #E5E7EB`.

---

*Stack: PHP 8.1 · SQLite 3 · Vanilla JS · Zero dipendenze esterne*

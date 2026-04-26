[meal-planner-v2-README.md](https://github.com/user-attachments/files/27096200/meal-planner-v2-README.md)
# 🍽️ Meal Planner v2.0 — Specifiche tecniche

> Documento di progetto per Claude Code.  
> Versione 1.0 già esistente (drag & drop, calendario, lista spesa, admin piatti, JSON).  
> Questa è la specifica completa per il refactor in **v2.0**.

---

## Stack tecnico

| Layer | Tecnologia |
|---|---|
| Frontend | HTML5 + CSS3 + JavaScript Vanilla (NO framework) |
| Backend | PHP 8.1+ |
| Database | **SQLite 3** — file locale `data/meal_planner.db` |
| Auth | Session-based (PHP sessions + cookie) |
| File config | `config.php` con costanti |
| Struttura | File separati: index, admin, login, api, config, style, app |

**NON usare:** React, Vue, Angular, Bootstrap, jQuery, Tailwind, MySQL.

**SQLite**: zero server, zero config, singolo file `.db` nella cartella `data/`.  
Funziona con `php -S localhost:8080`, XAMPP, Laragon — qualsiasi setup PHP locale.  
PHP lo supporta nativamente via PDO (`pdo_sqlite` abilitato di default).

---

## Struttura file

```
meal-planner/
├── index.php           # Planner settimanale (protetto da login)
├── login.php           # Login / registrazione
├── admin.php           # Gestione piatti (ruolo admin)
├── family.php          # Gestione membri famiglia e intolleranze
├── config.php          # Costanti app + path DB
├── api.php             # REST API JSON (tutte le operazioni)
├── style.css           # Unico foglio di stile
├── app.js              # JS planner principale
├── admin.js            # JS pannello admin
├── family.js           # JS gestione famiglia
├── lista.php           # Modalità lista spesa live (ottimizzata mobile)
├── lista.js            # JS lista live con polling real-time
├── nutrition_functions.php  # Funzioni PHP calcolo kcal (include in api.php)
├── README.md           # Questo file
└── data/
    ├── nutrition.json  # DB nutrizionale 216 ingredienti italiani (kcal/100g)
    ├── meal_planner.db # Database SQLite (creato automaticamente al primo avvio)
    ├── import_nutrition.php  # Script one-shot: php import_nutrition.php
    └── .htaccess       # Deny from all (protezione file DB da accesso diretto)
```

---

## Schema SQLite

Il file `api.php` crea lo schema automaticamente al primo avvio se il DB non esiste (`CREATE TABLE IF NOT EXISTS`). Nessun file SQL separato necessario.

```sql
CREATE TABLE IF NOT EXISTS users (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  email       TEXT UNIQUE NOT NULL,
  password    TEXT NOT NULL,
  name        TEXT NOT NULL,
  role        TEXT DEFAULT 'user' CHECK(role IN ('admin','user')),
  created_at  TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS families (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,
  owner_id    INTEGER NOT NULL,
  invite_code TEXT UNIQUE NOT NULL,
  created_at  TEXT DEFAULT (datetime('now')),
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

-- Profili membri (anche non-utenti: figli, partner senza account)
CREATE TABLE IF NOT EXISTS family_profiles (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  family_id      INTEGER NOT NULL,
  name           TEXT NOT NULL,
  type           TEXT DEFAULT 'adult' CHECK(type IN ('adult','child')),
  portion_weight REAL DEFAULT 1.0,   -- adulto=1.0, bambino=0.6 (configurabile)
  avatar_emoji   TEXT DEFAULT '👤',
  FOREIGN KEY (family_id) REFERENCES families(id)
);

CREATE TABLE IF NOT EXISTS intolerances (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  profile_id INTEGER NOT NULL,
  label      TEXT NOT NULL,
  FOREIGN KEY (profile_id) REFERENCES family_profiles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS meal_categories (
  id    INTEGER PRIMARY KEY AUTOINCREMENT,
  name  TEXT NOT NULL,
  emoji TEXT
);

CREATE TABLE IF NOT EXISTS meals (
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
);

CREATE TABLE IF NOT EXISTS meal_ingredients (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  meal_id           INTEGER NOT NULL,
  name              TEXT NOT NULL,
  quantity          REAL,
  unit              TEXT,
  price_est         REAL DEFAULT 0.0,
  intolerance_flags TEXT DEFAULT '',   -- CSV: "lattosio,glutine"
  zone              TEXT DEFAULT 'scaffali', -- reparto supermercato (vedi ZONE_ORDER)
  FOREIGN KEY (meal_id) REFERENCES meals(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS schedule (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  family_id      INTEGER NOT NULL,
  week_start     TEXT NOT NULL,        -- YYYY-MM-DD (sempre lunedì)
  day_index      INTEGER NOT NULL,     -- 0=lun ... 6=dom
  slot           TEXT NOT NULL CHECK(slot IN ('colazione','pranzo','cena')),
  meal_id        INTEGER,
  is_exception   INTEGER DEFAULT 0,
  exception_note TEXT,
  created_by     INTEGER,
  FOREIGN KEY (family_id)  REFERENCES families(id),
  FOREIGN KEY (meal_id)    REFERENCES meals(id),
  UNIQUE (family_id, week_start, day_index, slot)
);

CREATE TABLE IF NOT EXISTS shopping_items (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  family_id        INTEGER NOT NULL,
  week_start       TEXT NOT NULL,
  ingredient_name  TEXT NOT NULL,
  quantity         REAL,
  unit             TEXT,
  price_est        REAL DEFAULT 0.0,
  price_actual     REAL,
  checked          INTEGER DEFAULT 0,
  zone             TEXT DEFAULT 'scaffali',  -- reparto supermercato
  zone_order       INTEGER DEFAULT 6,        -- ordine fisico nel supermercato (1=ortofrutta ... 8=surgelati)
  meal_id          INTEGER,
  FOREIGN KEY (family_id) REFERENCES families(id)
);

CREATE TABLE IF NOT EXISTS ingredient_prices (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  family_id       INTEGER NOT NULL,
  ingredient_name TEXT NOT NULL,
  price           REAL NOT NULL,
  unit            TEXT,
  recorded_at     TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (family_id) REFERENCES families(id)
);

-- Seed categorie (solo se tabella vuota)
INSERT OR IGNORE INTO meal_categories (id, name, emoji) VALUES
  (1, 'Colazione', '☀️'),
  (2, 'Primo',     '🍝'),
  (3, 'Secondo',   '🥩'),
  (4, 'Contorno',  '🥗'),
  (5, 'Altro',     '🍽️');
```

---

## API endpoints (api.php)

Tutti via `GET ?action=X` o `POST` con body JSON. Richiedono sessione attiva tranne `login` e `register`.

### Auth
| action | method | params | risposta |
|---|---|---|---|
| `login` | POST | email, password | `{success, user}` |
| `register` | POST | name, email, password | `{success, user}` |
| `logout` | POST | — | `{success}` |
| `me` | GET | — | `{user, family}` |

### Famiglia
| action | method | params | risposta |
|---|---|---|---|
| `family_create` | POST | name | `{family}` |
| `family_join` | POST | invite_code | `{success}` |
| `family_members` | GET | — | `[members]` |
| `profiles_list` | GET | — | `[profiles]` |
| `profiles_add` | POST | name, type, portion_weight, avatar_emoji | `{profile}` |
| `profiles_update` | POST | id, ...fields | `{success}` |
| `profiles_delete` | POST | id | `{success}` |
| `intolerance_add` | POST | profile_id, label | `{success}` |
| `intolerance_delete` | POST | id | `{success}` |

### Piatti
| action | method | params | risposta |
|---|---|---|---|
| `meals_list` | GET | ?category_id, ?q | `[meals]` |
| `meals_add` | POST | name, emoji, category_id, cal_per_adult, ingredients[] | `{meal}` |
| `meals_update` | POST | id, ...fields | `{success}` |
| `meals_delete` | POST | id | `{success}` |
| `categories_list` | GET | — | `[categories]` |

### Calendario
| action | method | params | risposta |
|---|---|---|---|
| `schedule_get` | GET | week_start (YYYY-MM-DD) | `[rows]` |
| `schedule_set` | POST | week_start, day_index, slot, meal_id | `{success}` |
| `schedule_clear` | POST | week_start | `{success}` |
| `schedule_exception` | POST | schedule_id, exception_note, is_exception | `{success}` |

### Spesa
| action | method | params | risposta |
|---|---|---|---|
| `shopping_generate` | POST | week_start | `[items]` |
| `shopping_list` | GET | week_start | `[items]` |
| `shopping_check` | POST | id, checked | `{success}` |
| `shopping_price_update` | POST | id, price_actual | `{success}` |
| `shopping_export_text` | GET | week_start | plain text |

---

## Funzionalità v2.0 — dettaglio

### 1. Auth e condivisione famiglia

- Login/registrazione via email+password (bcrypt).
- Primo accesso: crea famiglia o entra con **codice invito** (6 caratteri, es. `A3KW9P`).
- Tutti i dati (piatti, calendario, spesa) sono legati a `family_id`.
- I membri della famiglia condividono gli stessi dati — polling ogni 30s per aggiornamenti.

### 2. Profili e porzioni

- Profili liberi per la famiglia: non devono avere un account (es. "Bianca", "Costanza").
- `portion_weight`: adulto = 1.0, bambino = 0.6 (default, modificabile per profilo).
- Esempio: Andrea (1.0) + Chiara (1.0) + Bianca (0.6) + Costanza (0.6) = **3.2 porzioni totali**.
- Le quantità degli ingredienti in lista spesa vengono moltiplicate per `Σ portion_weight`, arrotondate per eccesso all'unità commerciale (es. 320g spaghetti × 3.2 = 1024g → **2 pacchi da 500g**).
- Le calorie giornaliere mostrano il totale per l'intera famiglia.

### 3. Intolleranze

- Ogni profilo ha N intolleranze (lattosio, glutine, nichel, uova crude, peperoni...).
- Gli ingredienti hanno `intolerance_flags` (CSV): l'admin li spunta nel form.
- Se un piatto contiene un ingrediente incompatibile con un profilo → badge 🚨 sulla cella del calendario.
- Nella sidebar i piatti con conflitti sono marcati (bordo arancione) ma non nascosti.

### 4. Eccezioni giornaliere

- Ogni cella del calendario ha un pulsante **"+ Eccezione"**.
- Mini-form: testo libero (es. "Bianca a scuola → cotoletta") + selezione profilo coinvolto.
- Salvato in `schedule.exception_note`. Mostrato come nota colorata sotto la cella.
- Gli ingredienti dell'eccezione vanno in lista spesa come voce libera.

### 5. Prezzi

- `price_est` per ingrediente inserito in admin.
- Lista spesa: prezzo stimato per riga e **totale settimana stimato**.
- Prezzo reale corretto manualmente → salvato in `ingredient_prices`.
- Generazione successiva: usa la **media degli ultimi 3 prezzi reali** se disponibile.
- Subtotale per categoria + totale finale (stimato vs reale).

### 6. Lista spesa avanzata

- Generata da `shopping_generate`: raccoglie ingredienti, scala per porzioni, **deduplica** sommando quantità compatibili, raggruppa per categoria merceologica.
- Toggle vista: **Lista** (checkbox + quantità + prezzo) / **Riquadri** (griglia con emoji grande).
- Condivisione: copia testo formattato o link URL con lista in chiaro.

### 6b. Ordine reparti (percorso supermercato)

La lista spesa viene ordinata secondo il percorso fisico standard di un supermercato italiano.
Nessuna mappa visiva — è solo un ordinamento. Ogni ingrediente ha una `zone`, ogni zona ha un `zone_order` fisso.

**Tabella ZONE_ORDER (costante in config.php):**

```php
define('ZONE_ORDER', [
  'ortofrutta' => 1,   // frutta, verdura, erbe aromatiche
  'pane'       => 2,   // pane, cornetti, crackers
  'macelleria' => 3,   // carne, salumi, affettati
  'pesce'      => 4,   // pesce fresco, tonno fresco
  'latticini'  => 5,   // uova, formaggi, yogurt, burro, latte
  'scaffali'   => 6,   // pasta, riso, conserve, olio, sale, spezie
  'bevande'    => 7,   // acqua, vino, succhi (pesanti, vicino uscita)
  'surgelati'  => 8,   // ultimi, per non sgelarli
  'altro'      => 9,   // tutto il resto
]);
```

**Auto-detect zona** (funzione PHP in api.php, usata come default quando si aggiunge un ingrediente):

```php
function detectZone(string $name): string {
  $n = mb_strtolower(trim($name));
  $map = [
    'ortofrutta' => ['aglio','cipolla','carota','sedano','zucchine','pomodor','basilico',
                     'prezzemolo','menta','rosmarino','limone','funghi',
                     'melanzane','patate','insalata','spinaci','rucola'],
    'pane'       => ['pane','cornetti','crackers','grissini','fette biscottate'],
    'macelleria' => ['carne','macinata','bistecca','pollo','guanciale',
                     'pancetta','salsiccia','prosciutto','salame'],
    'pesce'      => ['salmone','merluzzo','gamberi','vongole','acciughe'],
    'latticini'  => ['mozzarella','parmigiano','pecorino','burro',
                     'yogurt','latte','uova','panna','ricotta'],
    'scaffali'   => ['spaghetti','penne','pasta','riso','olio',
                     'passata','pelati','fagioli','lenticchie',
                     'sale','pepe','brodo','dado'],
    'bevande'    => ['acqua','vino','birra','succo','aranciata'],
    'surgelati'  => ['surgelat','gelato','piselli surgelati'],
  ];
  foreach ($map as $zone => $keywords) {
    foreach ($keywords as $kw) {
      if (str_contains($n, $kw)) return $zone;
    }
  }
  return 'scaffali'; // default sicuro
}
```

**In `shopping_generate`:** dopo deduplicazione, aggiungi `zone_order = ZONE_ORDER[$zone]` e ordina con `ORDER BY zone_order ASC, ingredient_name ASC`.

**In `lista.php`:** raggruppa per zona nell'ordine corretto, mostra separatore di reparto con emoji e nome. La lista esce già nell'ordine in cui il cliente incontra i reparti.

**In admin (form ingrediente):** select con le 8 zone. Il valore viene pre-popolato da `detectZone($name)` via AJAX mentre l'utente digita il nome — può correggere manualmente.

### 7. Navigazione settimane

- Default: settimana corrente (lunedì).
- Frecce prev/next per navigare.
- **Copia settimana**: clona il piano di una settimana precedente nella corrente.


### 8. Modalità lista spesa live 🛒

File dedicato `lista.php` + `lista.js`. Accessibile da pulsante **"🛒 Vai alla lista"** nellheader del planner. Ottimizzata per essere usata al supermercato da telefono.

**UI:**
- Layout full-screen, font grande (min 18px), checkbox 40×40px touch-friendly.
- Sfondo bianco pulito, nessuna sidebar — solo la lista.
- Ingredienti raggruppati per corsia/categoria.
- Item spuntato: testo barrato + sfondo grigio, si sposta in fondo al gruppo.
- Header fisso con: nome settimana, totale stimato, badge "🔴 LIVE" lampeggiante, pulsante Chiudi.
- Pulsante "↺ Deseleziona tutto" per ricominciare.

**Sync real-time:**
- Polling ogni **5 secondi** su `api.php?action=shopping_list&week_start=X&since=TIMESTAMP`.
- Risposta delta: solo gli item modificati dopo `since` → aggiorna solo quei nodi DOM, nessun flash.
- Quando Andrea spunta "Mozzarella" → dopo max 5s Chiara vede la voce barrarsi sul suo telefono.
- Badge "🔴 LIVE" diventa "⚪ OFFLINE" se la fetch fallisce. Riprende automaticamente online.

**Indicatore autore:**
- Colonna `checked_by` (INTEGER FK users) + `checked_at` (TEXT) su `shopping_items`.
- Accanto al checkbox spuntato: avatar emoji dellutente che lha barrato (es. "✓ 👩").

**API aggiuntive:**

| action | method | params | risposta |
|---|---|---|---|
| `shopping_check` | POST | id, checked | `{success, checked_by, checked_at}` |
| `shopping_list` | GET | week_start, `?since=TIMESTAMP` | solo item modificati dopo `since` |
| `shopping_reset_checks` | POST | week_start | azzera tutti i `checked` |

**Colonne aggiuntive da aggiungere al CREATE TABLE shopping_items:**
```sql
checked_by  INTEGER REFERENCES users(id),
checked_at  TEXT
```

### 9. Design

- Palette crema / terracotta / oliva (da v1.0, mantenere).
- Font: Playfair Display (titoli) + DM Sans (corpo).
- Sidebar con ricerca, filtro, badge intolleranze.
- Calendario 7×3 con drag & drop, badge avvisi, note eccezione.
- Mobile responsive: sidebar diventa drawer su schermo < 768px.

---

## config.php

```php
<?php
define('DB_PATH',          __DIR__ . '/data/meal_planner.db');
define('APP_NAME',         'Meal Planner');
define('SESSION_LIFETIME', 60 * 60 * 24 * 30); // 30 giorni

define('INTOLERANCE_PRESETS', [
  'lattosio', 'glutine', 'nichel', 'uova crude', 'peperoni',
  'arachidi', 'frutta secca', 'crostacei', 'soia', 'senape'
]);

define('PORTION_ADULT', 1.0);
define('PORTION_CHILD', 0.6);
```

---

## Note implementative per Claude Code

**1. Connessione SQLite:**
```php
$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL'); // concorrenza lettura/scrittura
```

**2. Init schema** all'avvio di `api.php`: eseguire tutti i `CREATE TABLE IF NOT EXISTS` + seed categorie.

**3.** Prepared statements ovunque — nessuna concatenazione di query.

**4.** `password_hash($pw, PASSWORD_BCRYPT)` per le password.

**5.** CSRF token su tutti i form POST (token in sessione, verificato in api.php).

**6. Week start:**
```php
$weekStart = date('Y-m-d', strtotime('monday this week'));
```

**7. Invite code:**
```php
$code = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
```

**8. Generazione lista spesa** (`shopping_generate`):
- Leggi tutti i `schedule` della settimana con `meal_id NOT NULL`.
- Per ogni piatto carica `meal_ingredients`.
- Moltiplica `quantity` per `Σ portion_weight` dei profili della famiglia.
- Deduplica: raggruppa per `LOWER(TRIM(name))` + `unit`, somma quantità.
- Arrotonda all'unità commerciale con soglie semplici (es. ogni 500g = 1 pacco).
- `DELETE FROM shopping_items WHERE family_id=? AND week_start=?` poi reinserisci tutto.

**9. Prezzo medio storico:**
```sql
SELECT AVG(price) FROM (
  SELECT price FROM ingredient_prices
  WHERE family_id=? AND LOWER(ingredient_name)=LOWER(?)
  ORDER BY recorded_at DESC LIMIT 3
)
```

**10.** Frontend mantiene stato in `window.appState = { user, family, profiles, week, schedule, meals }` — nessun reload di pagina, tutto fetch+DOM update.

**11.** Creare `data/.htaccess` con `Deny from all` per proteggere il file `.db` da accesso HTTP diretto.

---

## Roadmap (fasi consigliate)

### Fase 1 — Base funzionante
- [ ] `config.php` + init schema SQLite in `api.php`
- [ ] `login.php` — registrazione, login, creazione/join famiglia
- [ ] `api.php` — auth, meals, categories, schedule
- [ ] `index.php` + `app.js` — calendario drag & drop portato da v1.0
- [ ] `style.css` — portato da v1.0 con aggiunte

### Fase 2 — Famiglia e intolleranze
- [ ] `family.php` + `family.js`
- [ ] API profili e intolleranze
- [ ] Badge avvisi nel calendario
- [ ] Calcolo kcal scalato per porzioni

### Fase 3 — Spesa avanzata
- [ ] `shopping_generate` con deduplicazione, scaling, arrotondamento
- [ ] Prezzi stimati e storico
- [ ] Toggle lista/riquadri
- [ ] Totale spesa settimanale

### Fase 4 — UX avanzata
- [ ] Eccezioni giornaliere
- [ ] Navigazione settimane prev/next + copia settimana
- [ ] Mobile responsive (drawer)
- [ ] Condivisione lista (link pubblico)

### Fase 5 — Lista live
- [ ] `lista.php` + `lista.js` — UI mobile-first
- [ ] Polling delta ogni 5s (parametro `since`)
- [ ] Colonne `checked_by` + `checked_at` su `shopping_items`
- [ ] Badge autore spunta (avatar emoji)
- [ ] `shopping_reset_checks` API
- [ ] Link pubblico con accesso anonimo (sola lettura + check)

---

*Stack: PHP 8.1 + SQLite 3 + Vanilla JS. Zero dipendenze esterne, zero server esterno.*  
*Generato da Claude — da passare a Claude Code per implementazione v2.0.*

## Database nutrizionale (già pronto)

Il file `data/nutrition.json` è già incluso nel repository. Contiene **216 ingredienti della cucina italiana** con:
- `kcal` — calorie per 100g (fonte: USDA FoodData Central, valori approssimati)
- `zone` — reparto supermercato per l'ordinamento lista spesa
- `aliases` — varianti del nome per il matching fuzzy
- `unit_weights` — peso medio in grammi per unità non-peso (`pz`, `cucchiaio`, `fetta`, ecc.)

**Setup una-tantum** (dopo il primo clone):
```bash
php data/import_nutrition.php
```
Popola la tabella `nutrition_db` nel DB SQLite. Poi il file JSON non serve più a runtime.

**Funzioni disponibili** in `nutrition_functions.php` (includere in `api.php`):

```php
// Trova kcal/100g per nome (cerca per nome, alias, match parziale)
lookupKcal($pdo, 'mozzarella')              // → 253.0

// Converte quantità + unità → grammi
toGrams(2, 'cucchiai', 'olio evo', $pdo)    // → 28.0
toGrams(1, 'pz', 'carota', $pdo)            // → 80.0
toGrams(150, 'g', null, $pdo)               // → 150.0

// Calorie di un ingrediente
calcIngredientKcal($pdo, 'spaghetti', 320, 'g')
// → ['grams'=>320, 'kcal'=>1187.2, 'found'=>true]

// Calorie totali di un piatto (array ingredienti)
calcMealKcal($pdo, $ingredients)
// → ['kcal_totali'=>1650, 'non_trovati'=>['pepe q.b.']]

// Calorie scalate per i profili famiglia
calcMealKcalPerPorzione($pdo, $ingredients, $profili)
// → ['kcal_totali'=>1650, 'kcal_per_profilo'=>[
//      ['name'=>'Andrea',   'kcal'=>516],
//      ['name'=>'Chiara',   'kcal'=>516],
//      ['name'=>'Bianca',   'kcal'=>309],
//      ['name'=>'Costanza', 'kcal'=>309],
//   ]]
```

**Matematica:**
```
kcal_ingrediente  = (grammi / 100) × kcal_per_100g
kcal_piatto       = Σ kcal_ingrediente
kcal_per_profilo  = kcal_piatto × (portion_weight / Σ portion_weight)
kcal_giorno       = Σ kcal_per_profilo di tutti i piatti del giorno
```

**Schema tabella `nutrition_db`:**
```sql
CREATE TABLE IF NOT EXISTS nutrition_db (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL UNIQUE,
  kcal_100g     REAL NOT NULL,
  zone          TEXT DEFAULT 'scaffali',
  aliases       TEXT DEFAULT '[]',    -- JSON array stringhe
  unit_weights  TEXT DEFAULT '{}',    -- JSON object {"pz":120,"cucchiaio":15}
);
```

**In admin (form ingrediente):** campo `zone` (select 8 zone) + chiamata AJAX a `api.php?action=nutrition_lookup&name=X` che restituisce `{kcal_100g, zone}` — pre-popola i campi mentre l'utente digita.

**Nota:** i valori sono approssimati ±5-10% — accettabile per un meal planner domestico. Non è un'app medica.


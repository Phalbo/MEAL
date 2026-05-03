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
    // Migration: schedule — contorno e nota extra per cella
    try { $pdo->exec("ALTER TABLE schedule ADD COLUMN side_dish TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE schedule ADD COLUMN extra_note TEXT DEFAULT NULL"); } catch (Exception $e) {}
    // Migration: schedule — piatto alternativo bambini + override porzioni
    try { $pdo->exec("ALTER TABLE schedule ADD COLUMN slot_kids TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE schedule ADD COLUMN portions_override REAL DEFAULT NULL"); } catch (Exception $e) {}
    // Migration: meals — contatore utilizzo e preferiti
    try { $pdo->exec("ALTER TABLE meals ADD COLUMN use_count INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE meals ADD COLUMN is_favorite INTEGER DEFAULT 0"); } catch (Exception $e) {}
    // Migration: nutrition_db — prezzo stimato per 100g
    try { $pdo->exec("ALTER TABLE nutrition_db ADD COLUMN price_est REAL DEFAULT 0"); } catch (Exception $e) {}

    // Indici per query frequenti (in try/catch: sicuri su ogni versione DB)
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_schedule_family_week ON schedule (family_id, week_start)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_shopping_family_week ON shopping_items (family_id, week_start)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_meals_family        ON meals (family_id, is_system)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pantry_family       ON pantry_items (family_id)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ingredients_meal    ON meal_ingredients (meal_id)"); } catch (Exception $e) {}

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

    try { seedIfEmpty($pdo); } catch (Exception $e) {}
}

function detectZoneStatic(string $name): string {
    $n = mb_strtolower(trim($name));
    $map = [
        'ortofrutta' => ['aglio','cipolla','carota','sedano','zucchine','pomodor','basilico',
                         'prezzemolo','menta','rosmarino','limone','funghi','rape','friariell',
                         'melanzane','patate','insalata','spinaci','rucola','mele','banane',
                         'fragole','arance','avocado','cavolo','broccoli'],
        'pane'       => ['pane','cornett','crackers','grissini','fette biscottate','piadina'],
        'macelleria' => ['carne','macinata','bistecca','pollo','guanciale','pancetta','salsiccia',
                         'prosciutto','salame','maiale','vitello','agnello','hamburger','wurstel',
                         'spiedini','cotoletta','braciole','lombata','abbacchio'],
        'pesce'      => ['salmone','merluzzo','gamberi','vongole','acciughe','tonno','baccalà',
                         'orata','calamari','cozze','pesce'],
        'latticini'  => ['mozzarella','parmigiano','pecorino','burro','yogurt','latte','uova',
                         'panna','ricotta','mascarpone','fontina','gorgonzola','stracchino'],
        'scaffali'   => ['spaghetti','penne','pasta','riso','olio','passata','pelati','fagioli',
                         'lenticchie','ceci','sale','pepe','brodo','dado','farina','zucchero',
                         'miele','marmellata','nutella','caffè','aceto','maionese','pangrattato',
                         'lievito','cacao','granola','biscotti','olive','capperi'],
        'bevande'    => ['acqua','vino','birra','succo','aranciata'],
        'surgelati'  => ['surgelat','gelato','piselli surgelati','bastoncini','pizzette surgelate'],
    ];
    foreach ($map as $zone => $keywords)
        foreach ($keywords as $kw)
            if (str_contains($n, $kw)) return $zone;
    return 'scaffali';
}

// ── Parse ricette.xlsx → array di ricette ────────────────────────────────────
function parseXlsxRecipes(string $path): array {
    if (!file_exists($path)) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$xml) return [];

    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('ss', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $catMap = ['Colazione'=>1,'Primo'=>2,'Secondo'=>3,'Contorno'=>4,'Altro'=>5];
    $recipes = [];
    $rowNodes = $xp->query('//ss:sheetData/ss:row');
    $first = true;
    foreach ($rowNodes as $row) {
        if ($first) { $first = false; continue; } // skip header
        $cols = [];
        foreach ($xp->query('ss:c', $row) as $cell) {
            $t = $cell->getAttribute('t');
            $val = '';
            if ($t === 'inlineStr') {
                foreach ($xp->query('.//ss:t', $cell) as $tn) $val .= $tn->nodeValue;
            } else {
                $vn = $xp->query('ss:v', $cell);
                if ($vn->length) $val = $vn->item(0)->nodeValue;
            }
            // get column letter from r attribute (e.g. B2 → B)
            $ref = $cell->getAttribute('r');
            $col = preg_replace('/[0-9]/', '', $ref);
            $cols[$col] = $val;
        }
        $name  = trim($cols['B'] ?? '');
        if (!$name) continue;
        $emoji = trim($cols['C'] ?? '🍽️') ?: '🍽️';
        $catNm = trim($cols['D'] ?? 'Altro');
        $catId = $catMap[$catNm] ?? 5;
        $kcal  = (int)($cols['E'] ?? 0);
        $note  = trim($cols['G'] ?? '');
        $ings  = [];
        foreach (explode("\n", $cols['F'] ?? '') as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) >= 2) {
                $ings[] = [
                    'name'     => $parts[0],
                    'quantity' => is_numeric($parts[1]) ? (float)$parts[1] : null,
                    'unit'     => $parts[2] ?? null,
                ];
            }
        }
        $recipes[] = compact('name','emoji','catId','kcal','note','ings');
    }
    return $recipes;
}

// ── Seed al primo avvio — nutrition_db da JSON + ricette da XLSX ──────────────
function seedIfEmpty(PDO $pdo): void {
    $pdo->exec('PRAGMA foreign_keys = OFF');

    // 1. nutrition_db da nutrition.json (idempotente: INSERT OR IGNORE)
    // Controlla un ingrediente tipico del JSON per capire se il seed è già stato fatto
    $jsonPath = __DIR__ . '/nutrition.json';
    if (file_exists($jsonPath) &&
        !(int)$pdo->query("SELECT COUNT(*) FROM nutrition_db WHERE name='aglio'")->fetchColumn()) {
        $items = json_decode(file_get_contents($jsonPath), true) ?? [];
        $ins = $pdo->prepare("INSERT OR IGNORE INTO nutrition_db
            (name, kcal_100g, zone, aliases, unit_weights) VALUES (?,?,?,?,?)");
        foreach ($items as $it) {
            $ins->execute([
                $it['name'],
                (float)($it['kcal'] ?? 0),
                $it['zone'] ?? 'scaffali',
                json_encode($it['aliases'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($it['unit_weights'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    // 2. Ricette di sistema da ricette.xlsx
    if ((int)$pdo->query("SELECT COUNT(*) FROM meals WHERE is_system=1")->fetchColumn() === 0) {
        $recipes = parseXlsxRecipes(__DIR__ . '/ricette.xlsx');
        $insMeal = $pdo->prepare("INSERT OR IGNORE INTO meals
            (family_id,name,emoji,category_id,cal_per_adult,notes,is_system,created_by)
            VALUES (0,?,?,?,?,?,1,0)");
        $insIng = $pdo->prepare("INSERT OR IGNORE INTO meal_ingredients
            (meal_id,name,quantity,unit,zone) VALUES (?,?,?,?,?)");
        foreach ($recipes as $r) {
            $insMeal->execute([$r['name'],$r['emoji'],$r['catId'],$r['kcal'],$r['note']?: null]);
            $mealId = (int)$pdo->lastInsertId();
            if (!$mealId) continue;
            foreach ($r['ings'] as $ing) {
                $zone = $ing['name'] ? detectZoneStatic($ing['name']) : 'scaffali';
                $insIng->execute([$mealId,$ing['name'],$ing['quantity'],$ing['unit'],$zone]);
            }
        }
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
}

// kept for back-compat — now delegates to seedIfEmpty
function seedSystemMeals(PDO $pdo): void { seedIfEmpty($pdo); }

// ── OLD hardcoded meal list (kept below, now unused) ─────────────────────────
function _unused_old_meals(): array { return [
        ['Spaghetti al sugo','🍝',2,520,'',[['Spaghetti',320,'g'],['Passata di pomodoro',400,'g'],['Aglio',2,'spicchi'],['Olio EVO',3,'cucchiai'],['Basilico',1,'mazzo']]],
        ['Carbonara','🥚',2,650,'',[['Spaghetti',320,'g'],['Guanciale',150,'g'],['Uova',4,'pz'],['Pecorino Romano',80,'g'],['Pepe nero',1,'cucchiaino']]],
        ['Risotto ai funghi','🍄',2,490,'',[['Riso Carnaroli',320,'g'],['Funghi porcini',200,'g'],['Cipolla',1,'pz'],['Vino bianco',1,'bicchiere'],['Parmigiano',50,'g'],['Burro',30,'g'],['Brodo vegetale',1000,'ml']]],
        ['Pasta e fagioli','🫘',2,440,'',[['Pasta mista',300,'g'],['Fagioli borlotti',400,'g'],['Cipolla',1,'pz'],['Carota',1,'pz'],['Rosmarino',1,'rametto'],['Olio EVO',3,'cucchiai']]],
        ['Penne all\'arrabbiata','🌶️',2,480,'',[['Penne',320,'g'],['Passata di pomodoro',400,'g'],['Peperoncino',2,'pz'],['Aglio',3,'spicchi'],['Olio EVO',3,'cucchiai'],['Prezzemolo',1,'mazzo']]],
        ['Lasagne al ragù','🫕',2,720,'',[['Sfoglie lasagne',500,'g'],['Carne macinata mista',400,'g'],['Besciamella',500,'ml'],['Passata di pomodoro',400,'g'],['Parmigiano',100,'g'],['Cipolla',1,'pz'],['Carota',1,'pz'],['Sedano',1,'costa']]],
        ['Minestrone','🥦',2,260,'',[['Zucchine',2,'pz'],['Carota',2,'pz'],['Patate',2,'pz'],['Fagiolini',150,'g'],['Pomodori',3,'pz'],['Pasta mista',150,'g'],['Olio EVO',2,'cucchiai'],['Parmigiano',30,'g']]],
        ['Pollo arrosto','🍗',3,380,'',[['Pollo intero',1200,'g'],['Rosmarino',2,'rametti'],['Aglio',3,'spicchi'],['Olio EVO',3,'cucchiai'],['Patate',600,'g']]],
        ['Bistecca alla fiorentina','🥩',3,680,'',[['Bistecca di manzo',600,'g'],['Sale',1,'cucchiaino'],['Rosmarino',1,'rametto'],['Olio EVO',1,'cucchiaio'],['Limone',1,'pz']]],
        ['Melanzane alla parmigiana','🍆',3,420,'',[['Melanzane',2,'pz'],['Passata di pomodoro',400,'g'],['Mozzarella',200,'g'],['Parmigiano',80,'g'],['Olio di semi',200,'ml'],['Basilico',1,'mazzo']]],
        ['Tonno e fagioli','🐟',3,310,'',[['Tonno in scatola',160,'g'],['Fagioli cannellini',400,'g'],['Cipolla rossa',1,'pz'],['Olio EVO',2,'cucchiai'],['Limone',1,'pz'],['Prezzemolo',1,'mazzo']]],
        ['Frittata di zucchine','🍳',3,290,'',[['Uova',4,'pz'],['Zucchine',2,'pz'],['Olio EVO',2,'cucchiai'],['Parmigiano',30,'g'],['Menta',5,'foglie']]],
        ['Insalata caprese','🥗',4,280,'',[['Mozzarella di bufala',250,'g'],['Pomodori cuore di bue',4,'pz'],['Basilico',1,'mazzo'],['Olio EVO',2,'cucchiai']]],
        ['Pizza Margherita','🍕',5,600,'',[['Pizza base',500,'g'],['Passata di pomodoro',200,'g'],['Mozzarella',200,'g'],['Basilico',1,'mazzo'],['Olio EVO',1,'cucchiaio']]],
        ['Zuppa di lenticchie','🥣',2,320,'',[['Lenticchie',300,'g'],['Carota',2,'pz'],['Sedano',2,'coste'],['Cipolla',1,'pz'],['Pomodori pelati',400,'g'],['Olio EVO',2,'cucchiai'],['Rosmarino',1,'rametto']]],
        ['Cornetto e caffè','☕',1,310,'',[['Cornetti',2,'pz'],['Zucchero',2,'cucchiaini']]],
        ['Yogurt e frutta','🍓',1,180,'',[['Yogurt greco',150,'g'],['Fragole',100,'g'],['Miele',1,'cucchiaio'],['Granola',30,'g']]],
        ['Uova strapazzate e toast','🍞',1,350,'',[['Uova',3,'pz'],['Pane in cassetta',2,'fette'],['Burro',10,'g'],['Latte intero',2,'cucchiai']]],
        ['Pasta al pesto','🌿',2,510,'',[['Trofie',320,'g'],['Pesto genovese',80,'g'],['Patate',100,'g'],['Fagiolini',80,'g'],['Parmigiano',30,'g']]],
        ['Cacio e pepe','⚫',2,580,'',[['Spaghetti',320,'g'],['Pecorino Romano',100,'g'],['Pepe nero',2,'cucchiaini'],['Olio EVO',1,'cucchiaio']]],
        ['Amatriciana','🧅',2,620,'',[['Rigatoni',320,'g'],['Guanciale',150,'g'],['Pomodori pelati',400,'g'],['Pecorino Romano',60,'g'],['Peperoncino',1,'pz'],['Vino bianco',50,'ml']]],
        ['Pasta al salmone','🐟',2,530,'',[['Penne',320,'g'],['Salmone fresco',200,'g'],['Panna da cucina',100,'ml'],['Cipolla',1,'pz'],['Olio EVO',2,'cucchiai'],['Erba cipollina',1,'mazzo']]],
        ['Pasta con le vongole','🐚',2,480,'',[['Linguine',320,'g'],['Vongole',500,'g'],['Aglio',3,'spicchi'],['Olio EVO',4,'cucchiai'],['Vino bianco',100,'ml'],['Prezzemolo',1,'mazzo'],['Peperoncino',1,'pz']]],
        ['Risotto allo zafferano','🌼',2,520,'Risotto alla milanese',[['Riso Carnaroli',320,'g'],['Zafferano',1,'bustina'],['Cipolla',1,'pz'],['Vino bianco',100,'ml'],['Brodo di carne',1000,'ml'],['Burro',40,'g'],['Parmigiano',50,'g']]],
        ['Pasta alla norma','🍆',2,490,'',[['Rigatoni',320,'g'],['Melanzane',2,'pz'],['Passata di pomodoro',400,'g'],['Ricotta salata',80,'g'],['Basilico',1,'mazzo'],['Olio EVO',4,'cucchiai']]],
        ['Zuppa di ceci','🫘',2,340,'',[['Ceci',300,'g'],['Pomodori pelati',400,'g'],['Rosmarino',2,'rametti'],['Aglio',2,'spicchi'],['Olio EVO',3,'cucchiai'],['Salvia',3,'foglie']]],
        ['Gnocchi al pomodoro','🥣',2,460,'',[['Gnocchi',500,'g'],['Passata di pomodoro',400,'g'],['Basilico',1,'mazzo'],['Olio EVO',2,'cucchiai'],['Parmigiano',40,'g']]],
        ['Pasta e patate','🥔',2,430,'',[['Pasta mista',280,'g'],['Patate',300,'g'],['Cipolla',1,'pz'],['Pomodori pelati',200,'g'],['Olio EVO',3,'cucchiai'],['Parmigiano',30,'g'],['Rosmarino',1,'rametto']]],
        ['Ribollita','🥬',2,310,'Toscana',[['Fagioli cannellini',300,'g'],['Cavolo nero',200,'g'],['Carota',2,'pz'],['Sedano',2,'coste'],['Cipolla',1,'pz'],['Pomodori pelati',400,'g'],['Pane raffermo',100,'g'],['Olio EVO',4,'cucchiai']]],
        ['Pasta al tonno','🐟',2,450,'',[['Penne',320,'g'],['Tonno in scatola',160,'g'],['Pomodori pelati',400,'g'],['Olive',50,'g'],['Capperi',1,'cucchiaio'],['Olio EVO',2,'cucchiai'],['Aglio',1,'spicchio']]],
        ['Polpette al sugo','🍖',3,420,'',[['Carne macinata mista',400,'g'],['Uova',1,'pz'],['Pane raffermo',50,'g'],['Latte intero',50,'ml'],['Parmigiano',30,'g'],['Passata di pomodoro',400,'g'],['Olio EVO',2,'cucchiai']]],
        ['Scaloppine al limone','🍋',3,320,'',[['Cotoletta di vitello',500,'g'],['Limone',2,'pz'],['Farina 00',30,'g'],['Burro',30,'g'],['Prezzemolo',1,'mazzo']]],
        ['Arrosto di maiale','🐷',3,380,'',[['Maiale',600,'g'],['Rosmarino',2,'rametti'],['Aglio',3,'spicchi'],['Vino bianco',100,'ml'],['Olio EVO',2,'cucchiai']]],
        ['Cotoletta alla milanese','🍳',3,520,'Ottima per i bambini',[['Cotoletta di vitello',500,'g'],['Uova',2,'pz'],['Pangrattato',100,'g'],['Burro',50,'g']]],
        ['Spezzatino di manzo','🥩',3,430,'',[['Bistecca di manzo',500,'g'],['Carota',2,'pz'],['Sedano',2,'coste'],['Cipolla',1,'pz'],['Pomodori pelati',400,'g'],['Vino rosso',100,'ml'],['Olio EVO',2,'cucchiai']]],
        ['Salsicce e friarielli','🌿',3,480,'Tipico napoletano',[['Salsiccia',400,'g'],['Rape',400,'g'],['Aglio',2,'spicchi'],['Olio EVO',3,'cucchiai'],['Peperoncino',1,'pz']]],
        ['Pollo alla cacciatora','🍗',3,390,'',[['Cosce di pollo',600,'g'],['Pomodori pelati',400,'g'],['Olive',80,'g'],['Capperi',1,'cucchiaio'],['Vino rosso',100,'ml'],['Cipolla',1,'pz'],['Olio EVO',2,'cucchiai']]],
        ['Salmone al forno','🐟',3,350,'',[['Salmone fresco',600,'g'],['Limone',1,'pz'],['Olio EVO',2,'cucchiai'],['Erba cipollina',1,'mazzo']]],
        ['Baccalà alla livornese','🐠',3,310,'Ammollare il baccalà 24h',[['Baccalà',500,'g'],['Pomodori pelati',400,'g'],['Olive',60,'g'],['Capperi',1,'cucchiaio'],['Aglio',2,'spicchi'],['Olio EVO',3,'cucchiai']]],
        ['Fritto misto di pesce','🦐',3,420,'',[['Gamberi',300,'g'],['Calamari',300,'g'],['Farina 00',100,'g'],['Olio di semi',500,'ml'],['Limone',2,'pz']]],
        ['Orata al cartoccio','🐡',3,280,'',[['Orata',800,'g'],['Limone',1,'pz'],['Pomodori',2,'pz'],['Olio EVO',2,'cucchiai'],['Rosmarino',1,'rametto'],['Aglio',1,'spicchio']]],
        ['Parmigiana di zucchine','🥒',3,340,'',[['Zucchine',4,'pz'],['Mozzarella',200,'g'],['Parmigiano',60,'g'],['Uova',2,'pz'],['Olio di semi',200,'ml'],['Basilico',1,'mazzo']]],
        ['Farinata di ceci','🟡',3,290,'Ligure',[['Farina di ceci',250,'g'],['Olio EVO',4,'cucchiai'],['Rosmarino',1,'rametto'],['Sale',1,'cucchiaino']]],
        ['Frittata al forno','🥚',3,310,'',[['Uova',6,'pz'],['Patate',300,'g'],['Cipolla',1,'pz'],['Olio EVO',2,'cucchiai'],['Parmigiano',40,'g']]],
        ['Verdure grigliate','🥗',4,160,'',[['Zucchine',2,'pz'],['Melanzane',1,'pz'],['Peperoni',2,'pz'],['Olio EVO',3,'cucchiai'],['Aglio',1,'spicchio'],['Basilico',1,'mazzo']]],
        ['Zuppa di verdure','🥕',2,220,'',[['Carota',3,'pz'],['Sedano',3,'coste'],['Cipolla',1,'pz'],['Patate',2,'pz'],['Pomodori',2,'pz'],['Spinaci',100,'g'],['Olio EVO',2,'cucchiai']]],
        ['Crema di zucca','🎃',2,200,'',[['Zucca',600,'g'],['Patate',200,'g'],['Cipolla',1,'pz'],['Brodo vegetale',500,'ml'],['Olio EVO',2,'cucchiai'],['Parmigiano',30,'g']]],
        ['Passato di verdure','🥦',2,190,'Ottimo per bambini',[['Carota',2,'pz'],['Patate',2,'pz'],['Sedano',2,'coste'],['Cipolla',1,'pz'],['Olio EVO',2,'cucchiai']]],
        ['Latte e cereali','🥛',1,280,'',[['Latte intero',200,'ml'],['Granola',60,'g']]],
        ['Pancakes','🥞',1,380,'',[['Farina 00',150,'g'],['Uova',2,'pz'],['Latte intero',200,'ml'],['Zucchero',2,'cucchiai'],['Burro',20,'g'],['Lievito per dolci',1,'cucchiaino']]],
        ['Pane tostato e marmellata','🍯',1,290,'',[['Pane in cassetta',3,'fette'],['Burro',15,'g'],['Marmellata',40,'g']]],
        ['Frullato di frutta','🍌',1,210,'',[['Banane',1,'pz'],['Fragole',100,'g'],['Yogurt greco',125,'g'],['Miele',1,'cucchiaio'],['Latte intero',100,'ml']]],
        ['Avocado toast','🥑',1,350,'',[['Pane integrale',2,'fette'],['Avocado',1,'pz'],['Uova',1,'pz'],['Limone',1,'pz']]],
        ['Insalata di riso','🍚',5,420,'Estiva',[['Riso basmati',320,'g'],['Tonno in scatola',160,'g'],['Olive',60,'g'],['Mais in scatola',80,'g'],['Pomodori',2,'pz'],['Olio EVO',2,'cucchiai']]],
        ['Piadina con prosciutto','🫓',5,480,'',[['Piadina',2,'pz'],['Prosciutto crudo',80,'g'],['Rucola',50,'g'],['Stracchino',80,'g']]],
        ['Bruschette al pomodoro','🍅',5,320,'Antipasto',[['Pane comune',4,'fette'],['Pomodori',4,'pz'],['Aglio',1,'spicchio'],['Basilico',1,'mazzo'],['Olio EVO',3,'cucchiai']]],
        ['Supplì al telefono','🍱',5,390,'Romano',[['Riso Carnaroli',300,'g'],['Passata di pomodoro',200,'g'],['Mozzarella',100,'g'],['Uova',2,'pz'],['Pangrattato',80,'g'],['Olio di semi',500,'ml']]],
        ['Focaccia genovese','🫓',5,370,'',[['Farina 00',400,'g'],['Olio EVO',6,'cucchiai'],['Lievito di birra',1,'cubetto'],['Sale grosso',1,'cucchiaio']]],
        ['Crostata di marmellata','🥧',5,410,'Dolce',[['Farina 00',300,'g'],['Burro',150,'g'],['Zucchero',100,'g'],['Uova',2,'pz'],['Marmellata',200,'g'],['Lievito per dolci',1,'bustina']]],
        ['Tiramisù','☕',5,520,'Dolce',[['Mascarpone',500,'g'],['Uova',4,'pz'],['Zucchero',100,'g'],['Biscotti',200,'g'],['Caffè',200,'ml']]],
        ['Pizza bianca con patate','🥔',5,610,'Senza pomodoro',[['Pizza base',300,'g'],['Patate',300,'g'],['Rosmarino',1,'rametto'],['Olio EVO',3,'cucchiai'],['Sale',1,'cucchiaino']]],
        ['Pizza con wurstel','🌭',5,640,'Per i bambini',[['Pizza base',300,'g'],['Passata di pomodoro',150,'g'],['Mozzarella',150,'g'],['Wurstel',3,'pz']]],
        ['Pizza funghi e prosciutto','🍄',5,620,'',[['Pizza base',300,'g'],['Passata di pomodoro',150,'g'],['Mozzarella',150,'g'],['Funghi champignon',100,'g'],['Prosciutto cotto',80,'g']]],
        ['Pizza quattro formaggi','🧀',5,680,'',[['Pizza base',300,'g'],['Mozzarella',100,'g'],['Gorgonzola',60,'g'],['Fontina',60,'g'],['Parmigiano',40,'g']]],
        ['Pizza diavola','🌶️',5,620,'',[['Pizza base',300,'g'],['Passata di pomodoro',150,'g'],['Mozzarella',150,'g'],['Salame',80,'g'],['Peperoncino',1,'pz']]],
        ['Calzone fritto','🫔',5,720,'',[['Pizza base',300,'g'],['Mozzarella',120,'g'],['Prosciutto cotto',80,'g'],['Olio di semi',500,'ml']]],
        ['Pizza con salsiccia e friarielli','🌿',5,650,'',[['Pizza base',300,'g'],['Passata di pomodoro',150,'g'],['Mozzarella',120,'g'],['Salsiccia',100,'g'],['Rape',150,'g']]],
        ['Supplì pronti','🍱',5,380,'Dal freezer',[['Supplì surgelati',500,'g'],['Olio di semi',500,'ml']]],
        ['Crocchette di patate','🟡',5,350,'Preconfezionate',[['Crocchette surgelate',500,'g'],['Olio di semi',500,'ml']]],
        ['Olive ascolane','🫒',5,420,'Preconfezionate',[['Olive ascolane surgelate',400,'g'],['Olio di semi',500,'ml']]],
        ['Misto fritto surgelato','🍟',5,400,'',[['Misto fritto surgelato',500,'g'],['Olio di semi',500,'ml']]],
        ['Bastoncini di pesce','🐟',3,340,'Dal freezer',[['Bastoncini di pesce',400,'g'],['Limone',1,'pz']]],
        ['Pizzette surgelate','🍕',5,360,'Per i bambini',[['Pizzette surgelate',400,'g']]],
        ['Lombata di maiale','🐷',3,360,'',[['Maiale',600,'g'],['Rosmarino',2,'rametti'],['Aglio',2,'spicchi'],['Olio EVO',2,'cucchiai'],['Sale',1,'cucchiaino']]],
        ['Braciole di maiale','🥩',3,340,'Veloci',[['Maiale',500,'g'],['Salvia',3,'foglie'],['Aglio',1,'spicchio'],['Olio EVO',2,'cucchiai']]],
        ['Wurstel e patatine','🌭',3,480,'Da bambini',[['Wurstel',4,'pz'],['Patate',400,'g'],['Olio EVO',2,'cucchiai'],['Rosmarino',1,'rametto']]],
        ['Hamburger in padella','🍔',3,420,'',[['Carne macinata di manzo',400,'g'],['Cipolla',1,'pz'],['Olio EVO',1,'cucchiaio']]],
        ['Fettine di pollo impanate','🍗',3,380,'',[['Petto di pollo',500,'g'],['Uova',2,'pz'],['Pangrattato',80,'g'],['Olio di semi',200,'ml']]],
        ['Salsicce alla griglia','🌭',3,440,'',[['Salsiccia',400,'g'],['Olio EVO',1,'cucchiaio']]],
        ['Pollo al forno con patate','🍗',3,410,'',[['Petto di pollo',500,'g'],['Patate',500,'g'],['Rosmarino',2,'rametti'],['Aglio',2,'spicchi'],['Olio EVO',3,'cucchiai']]],
        ['Spiedini di carne','🍢',3,360,'',[['Carne macinata mista',400,'g'],['Cipolla',1,'pz'],['Prezzemolo',1,'mazzo'],['Pangrattato',30,'g']]],
        ['Pasta col tonno in bianco','🐟',2,430,'Senza pomodoro',[['Spaghetti',320,'g'],['Tonno in scatola',160,'g'],['Aglio',2,'spicchi'],['Olio EVO',3,'cucchiai'],['Prezzemolo',1,'mazzo']]],
        ['Pasta aglio olio peperoncino','🧄',2,420,'10 minuti',[['Spaghetti',320,'g'],['Aglio',4,'spicchi'],['Olio EVO',5,'cucchiai'],['Peperoncino',2,'pz'],['Prezzemolo',1,'mazzo']]],
        ['Pasta burro e parmigiano','🧀',2,490,'Per i bambini',[['Spaghetti',320,'g'],['Burro',60,'g'],['Parmigiano',80,'g']]],
        ['Pasta al sugo con carne','🍝',2,580,'Bolognese veloce',[['Rigatoni',320,'g'],['Carne macinata mista',200,'g'],['Passata di pomodoro',400,'g'],['Cipolla',1,'pz'],['Olio EVO',2,'cucchiai'],['Parmigiano',40,'g']]],
        ['Spaghetti alle acciughe','🐟',2,450,'',[['Spaghetti',320,'g'],['Acciughe sott\'olio',6,'filetti'],['Aglio',2,'spicchi'],['Olio EVO',3,'cucchiai'],['Pangrattato',30,'g'],['Peperoncino',1,'pz']]],
        ['Pasta con ricotta','🍝',2,480,'Cremosa e veloce',[['Rigatoni',320,'g'],['Ricotta',200,'g'],['Parmigiano',40,'g'],['Pepe nero',1,'cucchiaino']]],
        ['Pasta fredda estiva','🥗',2,400,'Estate',[['Fusilli',320,'g'],['Pomodori',3,'pz'],['Mozzarella',150,'g'],['Olive',50,'g'],['Basilico',1,'mazzo'],['Olio EVO',3,'cucchiai']]],
        ['Pasta e piselli','🫛',2,420,'',[['Pasta mista',300,'g'],['Piselli surgelati',200,'g'],['Cipolla',1,'pz'],['Pancetta',80,'g'],['Olio EVO',2,'cucchiai']]],
        ['Riso al salto','🍚',2,380,'Con riso avanzato',[['Riso basmati',320,'g'],['Uova',2,'pz'],['Parmigiano',40,'g'],['Burro',20,'g']]],
        ['Tagliatelle al ragù','🍝',2,620,'Bolognese classica',[['Tagliatelle',320,'g'],['Carne macinata mista',300,'g'],['Carota',1,'pz'],['Sedano',1,'costa'],['Cipolla',1,'pz'],['Vino rosso',100,'ml'],['Passata di pomodoro',300,'g']]],
        ['Panino con prosciutto','🥪',5,380,'',[['Pane comune',2,'fette'],['Prosciutto crudo',60,'g'],['Burro',10,'g']]],
        ['Panino con tonno e olive','🥪',5,360,'',[['Pane comune',2,'fette'],['Tonno in scatola',80,'g'],['Olive',30,'g'],['Maionese',1,'cucchiaio']]],
        ['Tramezzino al prosciutto','🥪',5,320,'',[['Pane in cassetta',4,'fette'],['Prosciutto cotto',80,'g'],['Maionese',2,'cucchiai']]],
        ['Uova al tegamino','🍳',3,220,'Velocissimo',[['Uova',3,'pz'],['Burro',15,'g'],['Sale',1,'pizzico']]],
        ['Uova in camicia','🥚',3,200,'',[['Uova',3,'pz'],['Aceto',1,'cucchiaio']]],
        ['Bruschetta con ricotta','🍞',5,310,'Colazione o merenda',[['Pane comune',4,'fette'],['Ricotta',150,'g'],['Miele',1,'cucchiaio'],['Noce',4,'pz']]],
        ['Toast prosciutto e formaggio','🧀',5,380,'',[['Pane in cassetta',4,'fette'],['Prosciutto cotto',60,'g'],['Fontina',60,'g'],['Burro',10,'g']]],
        ['Insalata mista','🥗',4,120,'',[['Insalata',1,'cespo'],['Pomodori',2,'pz'],['Carota',1,'pz'],['Olio EVO',2,'cucchiai'],['Aceto',1,'cucchiaio']]],
        ['Insalata di pollo','🥗',3,290,'Leggera',[['Petto di pollo',300,'g'],['Insalata',1,'cespo'],['Pomodori',2,'pz'],['Limone',1,'pz'],['Olio EVO',2,'cucchiai']]],
        ['Panzanella','🍅',5,310,'Toscana estiva',[['Pane raffermo',200,'g'],['Pomodori',4,'pz'],['Cipolla rossa',1,'pz'],['Basilico',1,'mazzo'],['Aceto',2,'cucchiai'],['Olio EVO',4,'cucchiai']]],
        ['Patate al forno','🥔',4,280,'',[['Patate',600,'g'],['Rosmarino',2,'rametti'],['Aglio',2,'spicchi'],['Olio EVO',3,'cucchiai']]],
        ['Spinaci in padella','🥬',4,100,'Velocissimo',[['Spinaci',400,'g'],['Aglio',1,'spicchio'],['Olio EVO',2,'cucchiai']]],
        ['Zucchine trifolate','🥒',4,120,'',[['Zucchine',4,'pz'],['Aglio',1,'spicchio'],['Olio EVO',2,'cucchiai'],['Prezzemolo',1,'mazzo']]],
        ['Broccoli ripassati','🥦',4,130,'',[['Broccoli',500,'g'],['Aglio',2,'spicchi'],['Olio EVO',3,'cucchiai'],['Peperoncino',1,'pz']]],
        ['Fagioli all\'uccelletto','🫘',4,240,'Toscano',[['Fagioli cannellini',400,'g'],['Passata di pomodoro',200,'g'],['Aglio',2,'spicchi'],['Salvia',3,'foglie'],['Olio EVO',3,'cucchiai']]],
        ['Caponata','🍆',4,200,'Siciliana',[['Melanzane',2,'pz'],['Pomodori',3,'pz'],['Sedano',2,'coste'],['Olive',60,'g'],['Capperi',1,'cucchiaio'],['Aceto',2,'cucchiai'],['Zucchero',1,'cucchiaio'],['Olio EVO',4,'cucchiai']]],
        ['Fette biscottate e miele','🍯',1,260,'',[['Fette biscottate',4,'pz'],['Miele',2,'cucchiai']]],
        ['Biscotti e latte','🍪',1,290,'',[['Biscotti',6,'pz'],['Latte intero',200,'ml']]],
        ['Pane e nutella','🍫',1,380,'',[['Pane comune',2,'fette'],['Nutella',40,'g']]],
        ['Cioccolata calda','☕',1,220,'',[['Latte intero',200,'ml'],['Cacao in polvere',2,'cucchiai'],['Zucchero',2,'cucchiaini']]],
        ['Macedonia di frutta','🍓',1,150,'',[['Mele',1,'pz'],['Banane',1,'pz'],['Arance',1,'pz'],['Fragole',80,'g'],['Zucchero',1,'cucchiaio'],['Limone',1,'pz']]],
        ['Abbacchio al forno','🐑',3,520,'Domenica',[['Agnello',800,'g'],['Patate',500,'g'],['Rosmarino',3,'rametti'],['Aglio',4,'spicchi'],['Vino bianco',150,'ml'],['Olio EVO',3,'cucchiai']]],
        ['Saltimbocca alla romana','🍖',3,340,'Romano',[['Cotoletta di vitello',500,'g'],['Prosciutto crudo',80,'g'],['Salvia',8,'foglie'],['Burro',40,'g'],['Vino bianco',80,'ml']]],
        ['Baccalà fritto','🐠',3,380,'Vigilia',[['Baccalà',500,'g'],['Farina 00',100,'g'],['Olio di semi',500,'ml'],['Limone',2,'pz']]],
        ['Spaghetti alle vongole','🐚',2,490,'',[['Spaghetti',320,'g'],['Vongole',600,'g'],['Aglio',3,'spicchi'],['Olio EVO',4,'cucchiai'],['Vino bianco',100,'ml'],['Prezzemolo',1,'mazzo']]],
        ['Pasta alla carbonara di zucchine','🥒',2,520,'Versione vegetariana',[['Spaghetti',320,'g'],['Zucchine',3,'pz'],['Uova',3,'pz'],['Pecorino Romano',60,'g'],['Olio EVO',2,'cucchiai']]],
        ['Tiella di riso patate e cozze','🦪',2,460,'Barese',[['Riso Carnaroli',250,'g'],['Patate',300,'g'],['Cozze',500,'g'],['Pomodori',3,'pz'],['Cipolla',1,'pz'],['Olio EVO',4,'cucchiai'],['Parmigiano',40,'g']]],
    ]; // end _unused_old_meals
}

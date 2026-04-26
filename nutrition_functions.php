<?php
/**
 * nutrition_functions.php
 * Includere in api.php oppure copiare le funzioni direttamente.
 * 
 * Uso:
 *   $kcal = lookupKcal($pdo, 'mozzarella');        // → 253
 *   $g    = toGrams(125, 'g', null);               // → 125
 *   $g    = toGrams(1, 'pz', 'mozzarella', $pdo);  // → 125 (da unit_weights)
 *   $res  = calcIngredientKcal($pdo, 'mozzarella', 1, 'pz'); // → ['g'=>125, 'kcal'=>316]
 *   $tot  = calcMealKcal($pdo, $ingredients);       // kcal totali del piatto
 */

// ── Cerca kcal/100g per nome ingrediente ───────────────────────────────────
function lookupKcal(PDO $pdo, string $name): ?float
{
    $name = mb_strtolower(trim($name));

    // Match esatto
    $st = $pdo->prepare("SELECT kcal_100g FROM nutrition_db WHERE LOWER(name)=?");
    $st->execute([$name]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) return (float)$row['kcal_100g'];

    // Match su aliases (JSON array)
    $all = $pdo->query("SELECT name, kcal_100g, aliases FROM nutrition_db")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all as $row) {
        $aliases = json_decode($row['aliases'], true) ?? [];
        foreach ($aliases as $alias) {
            if (mb_strtolower($alias) === $name) return (float)$row['kcal_100g'];
        }
    }

    // Match parziale (contains)
    $st = $pdo->prepare("SELECT kcal_100g FROM nutrition_db WHERE LOWER(name) LIKE ? LIMIT 1");
    $st->execute(["%$name%"]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) return (float)$row['kcal_100g'];

    return null; // non trovato
}

// ── Converte quantità + unità → grammi ─────────────────────────────────────
function toGrams(float $qty, string $unit, ?string $ingredientName, PDO $pdo): ?float
{
    $unit = mb_strtolower(trim($unit));

    // Unità dirette
    $direct = [
        'g'   => 1.0,
        'kg'  => 1000.0,
        'ml'  => 1.0,   // densità ≈ 1 per liquidi acquosi
        'l'   => 1000.0,
        'cl'  => 10.0,
        'dl'  => 100.0,
    ];
    if (isset($direct[$unit])) return $qty * $direct[$unit];

    // Unità volumetriche standard
    $volume = [
        'cucchiaio'   => 15.0,
        'cucchiai'    => 15.0,
        'cucchiaino'  => 5.0,
        'cucchiaini'  => 5.0,
        'tazza'       => 240.0,
        'bicchiere'   => 200.0,
        'mazzo'       => 30.0,
        'rametto'     => 5.0,
        'foglia'      => 2.0,
        'bustina'     => 8.0,
        'noce'        => 10.0,  // noce di burro
        'spruzzo'     => 3.0,
        'pizzico'     => 0.5,
    ];
    if (isset($volume[$unit])) return $qty * $volume[$unit];

    // 'pz', 'pezzo', 'fetta', ecc. → cerca unit_weights nel DB
    $piece_units = ['pz','pezzo','pezzi','fetta','fette','spicchio','spicchi',
                    'filetto','filetti','lattina','lattine','vasetto','pallina',
                    'grappolo','cespo','pannocchia','dado','cubetto','quadretto',
                    'testa','trancio','bottiglia','tazzina'];

    if (in_array($unit, $piece_units) && $ingredientName) {
        $iname = mb_strtolower(trim($ingredientName));
        $rows  = $pdo->query("SELECT unit_weights FROM nutrition_db WHERE LOWER(name) LIKE '%$iname%' LIMIT 1")->fetchAll();
        if ($rows) {
            $weights = json_decode($rows[0]['unit_weights'], true) ?? [];
            // prova unità esatta, poi 'pz' come fallback
            $w = $weights[$unit] ?? $weights['pz'] ?? null;
            if ($w) return $qty * (float)$w;
        }
    }

    // q.b. e simili → 0
    if (in_array($unit, ['q.b.','qb','q.b','n/a','a piacere',''])) return 0.0;

    return null; // unità sconosciuta
}

// ── Calorie di un ingrediente singolo ──────────────────────────────────────
function calcIngredientKcal(PDO $pdo, string $name, float $qty, string $unit): array
{
    $grams   = toGrams($qty, $unit, $name, $pdo);
    $kcal100 = lookupKcal($pdo, $name);

    if ($grams === null || $kcal100 === null) {
        return ['grams' => null, 'kcal' => null, 'found' => false];
    }

    return [
        'grams' => round($grams, 1),
        'kcal'  => round(($grams / 100.0) * $kcal100, 1),
        'found' => true,
    ];
}

// ── Calorie totali di un piatto (array di ingredienti) ─────────────────────
// $ingredients = [['name'=>'spaghetti','quantity'=>320,'unit'=>'g'], ...]
function calcMealKcal(PDO $pdo, array $ingredients): array
{
    $total   = 0.0;
    $missing = [];

    foreach ($ingredients as $ing) {
        $res = calcIngredientKcal($pdo, $ing['name'], (float)$ing['quantity'], $ing['unit']);
        if ($res['found']) {
            $total += $res['kcal'];
        } else {
            $missing[] = $ing['name'];
        }
    }

    return [
        'kcal_totali'    => round($total),
        'ingredienti_ok' => count($ingredients) - count($missing),
        'ingredienti_tot'=> count($ingredients),
        'non_trovati'    => $missing,
    ];
}

// ── Calorie per porzione, scalate per famiglia ─────────────────────────────
// $profili = [['portion_weight'=>1.0], ['portion_weight'=>1.0], ['portion_weight'=>0.6], ...]
function calcMealKcalPerPorzione(PDO $pdo, array $ingredients, array $profili): array
{
    $meal     = calcMealKcal($pdo, $ingredients);
    $n_porc   = count($profili);
    $tot_peso = array_sum(array_column($profili, 'portion_weight'));

    if ($n_porc === 0) return $meal;

    $meal['kcal_per_adulto']  = $n_porc > 0 ? round($meal['kcal_totali'] / $tot_peso) : null;
    $meal['kcal_famiglia']    = $meal['kcal_totali']; // già totale per tot_peso porzioni
    $meal['porzioni_totali']  = $tot_peso;

    // kcal per ogni profilo
    $meal['kcal_per_profilo'] = array_map(function($p) use ($meal, $tot_peso) {
        return [
            'name'  => $p['name'] ?? '?',
            'kcal'  => round($meal['kcal_totali'] * ($p['portion_weight'] / $tot_peso)),
        ];
    }, $profili);

    return $meal;
}

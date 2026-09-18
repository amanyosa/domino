<?php
/* =============================================================
   Dòmino de sumes i restes — servidor de partides en línia
   Un sol fitxer. Estat en JSON, un fitxer per partida.
   El servidor és qui reparteix i qui valida: el client no pot
   inventar-se cap jugada.
   ============================================================= */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* ---------- PARÀMETRES AJUSTABLES ---------- */
const DIR_DADES      = __DIR__ . '/dades-domino'; // millor moure'l fora del document root
const VIDA_SALA      = 600;   // s sense que entri el segon jugador
const VIDA_PARTIDA   = 900;   // s sense cap moviment
const MARGE_TORNADA  = 60;    // s que esperem un jugador desconnectat
const MIDA_MA        = 7;
const NOMBRE_VALORS  = 7;

// Ha de coincidir amb CONFIG.TEMPS del fitxer HTML
const TEMPS_ACTIU       = false;
const TEMPS_PER_JUGADOR = 300;
const TEMPS_INCREMENT   = 5;
const TEMPS_AL_ACABAR   = 'passa';   // 'perd' | 'passa' | 'avisa'

const NIVELLS = [
  1 => ['etiqueta'=>'Fins a 12','valorMin'=>1,'valorMax'=>12,'nombreMax'=>12,'ops'=>['+']],
  2 => ['etiqueta'=>'Fins a 24','valorMin'=>0,'valorMax'=>18,'nombreMax'=>24,'ops'=>['+','-']],
  3 => ['etiqueta'=>'Fins a 36','valorMin'=>0,'valorMax'=>24,'nombreMax'=>36,'ops'=>['+','-']],
];

/* ---------- UTILITATS ---------- */
function surt(array $d, int $codi = 200) {
  http_response_code($codi);
  echo json_encode($d, JSON_UNESCAPED_UNICODE);
  exit;
}
function error(string $m, int $codi = 400) { surt(['error'=>$m], $codi); }

function preparaDir(): void {
  if (!is_dir(DIR_DADES)) {
    @mkdir(DIR_DADES, 0770, true);
    @file_put_contents(DIR_DADES.'/.htaccess', "Require all denied\nDeny from all\n");
  }
  if (!is_dir(DIR_DADES) || !is_writable(DIR_DADES)) error('El directori de dades no es pot escriure', 500);
}

function neteja(): void {
  foreach (glob(DIR_DADES.'/*.json') ?: [] as $f) {
    $p = json_decode((string)@file_get_contents($f), true);
    if (!is_array($p)) { @unlink($f); continue; }
    $vida = ($p['estat'] ?? '') === 'esperant' ? VIDA_SALA : VIDA_PARTIDA;
    if (time() - (int)($p['tocada'] ?? 0) > $vida) @unlink($f);
  }
}

function fitxer(string $codi): string { return DIR_DADES.'/'.$codi.'.json'; }

function codiNou(): string {
  $lletres = 'BCDFGHJKLMNPQRSTVWXZ23456789';
  do {
    $c = '';
    for ($i=0; $i<4; $i++) $c .= $lletres[random_int(0, strlen($lletres)-1)];
  } while (file_exists(fitxer($c)));
  return $c;
}

function llegeix(string $codi): array {
  $f = fitxer($codi);
  if (!is_file($f)) error('Aquesta partida no existeix o ja ha caducat', 404);
  $p = json_decode((string)file_get_contents($f), true);
  if (!is_array($p)) error('Partida malmesa', 500);
  return $p;
}
function desa(array $p): void {
  $f = fitxer($p['codi']);
  $fp = fopen($f, 'c+');
  if (!$fp) error('No es pot desar la partida', 500);
  flock($fp, LOCK_EX);
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($p, JSON_UNESCAPED_UNICODE));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
}

/* ---------- BARALLA (mateixa lògica que el client) ---------- */
function triaValors(int $min, int $max, int $quants): array {
  $rang = $max - $min + 1;
  $vals = [];
  for ($i=0; $i<$quants; $i++) {
    $lo = $min + (int)round($i*$rang/$quants);
    $hi = max($lo, $min + (int)round(($i+1)*$rang/$quants) - 1);
    $intents = 0;
    do { $v = random_int($lo, $hi); $intents++; } while (in_array($v, $vals, true) && $intents < 40);
    while (in_array($v, $vals, true)) $v = random_int($min, $max);
    $vals[] = $v;
  }
  sort($vals);
  return $vals;
}

function creaCara(int $valor, array $niv, float $ratio): string {
  if (mt_rand() / mt_getrandmax() > $ratio) return (string)$valor;
  $opcions = [];
  if (in_array('+', $niv['ops'], true) && $valor >= 2 && $valor <= $niv['nombreMax']) {
    $a = random_int(1, $valor-1);
    $opcions[] = $a.' + '.($valor-$a);
  }
  if (in_array('-', $niv['ops'], true) && $niv['nombreMax'] - $valor >= 1) {
    $b = random_int(1, min($niv['nombreMax'] - $valor, $niv['nombreMax']));
    $opcions[] = ($valor+$b).' − '.$b;
  }
  if (!$opcions) return (string)$valor;
  return $opcions[array_rand($opcions)];
}

function creaBaralla(array $niv, float $ratio): array {
  $valors = triaValors($niv['valorMin'], $niv['valorMax'], NOMBRE_VALORS);
  $fitxes = [];
  $id = 0;
  foreach ($valors as $i => $a) {
    for ($j = $i; $j < count($valors); $j++) {
      $b = $valors[$j];
      $fitxes[] = ['id'=>$id++, 'a'=>$a, 'b'=>$b,
                   'caraA'=>creaCara($a,$niv,$ratio), 'caraB'=>creaCara($b,$niv,$ratio)];
    }
  }
  shuffle($fitxes);
  return $fitxes;
}

/* ---------- REGLES ---------- */
function encaixa(array $f, array $p, string $extrem): bool {
  $v = $extrem === 'esq' ? $p['esq'] : $p['dre'];
  return $f['a'] === $v || $f['b'] === $v;
}
function potJugar(array $ma, array $p): bool {
  foreach ($ma as $f) if (encaixa($f,$p,'esq') || encaixa($f,$p,'dre')) return true;
  return false;
}
function colloca(array &$p, array $f, string $extrem): void {
  $peça = ['a'=>$f['a'],'b'=>$f['b'],'caraA'=>$f['caraA'],'caraB'=>$f['caraB']];
  if ($extrem === 'dre') {
    if ($peça['a'] !== $p['dre']) $peça = gira($peça);
    $p['tauler'][] = $peça;
    $p['dre'] = $peça['b'];
  } else {
    if ($peça['b'] !== $p['esq']) $peça = gira($peça);
    array_unshift($p['tauler'], $peça);
    $p['esq'] = $peça['a'];
  }
}
function gira(array $p): array {
  return ['a'=>$p['b'],'b'=>$p['a'],'caraA'=>$p['caraB'],'caraB'=>$p['caraA']];
}

/* ---------- RELLOTGE ---------- */
function actualitzaTemps(array &$p): void {
  if (!TEMPS_ACTIU || $p['estat'] !== 'jugant') { $p['darrer'] = time(); return; }
  $t = $p['torn'];
  $p['temps'][$t] = max(0, $p['temps'][$t] - (time() - (int)$p['darrer']));
  $p['darrer'] = time();
  if ($p['temps'][$t] <= 0) {
    if (TEMPS_AL_ACABAR === 'perd') {
      $p['estat'] = 'acabada';
      $p['resultat'] = 'Sense temps · guanya el jugador '.(2-$t);
    } elseif (TEMPS_AL_ACABAR === 'passa') {
      $p['temps'][$t] = TEMPS_INCREMENT;
      $p['torn'] = 1 - $t;
    }
  }
}
function premiaTemps(array &$p, int $j): void {
  if (TEMPS_ACTIU) $p['temps'][$j] = min(TEMPS_PER_JUGADOR, $p['temps'][$j] + TEMPS_INCREMENT);
}

/* ---------- VISTA FILTRADA ---------- */
function vista(array $p, int $j): array {
  return [
    'v'          => $p['v'],
    'codi'       => $p['codi'],
    'jo'         => $j,
    'torn'       => $p['torn'],
    'tauler'     => $p['tauler'],
    'esq'        => $p['esq'],
    'dre'        => $p['dre'],
    'ma'         => array_values($p['mans'][$j]),
    'rival'      => count($p['mans'][1-$j]),
    'munt'       => count($p['munt']),
    'estat'      => $p['estat'],
    'nivell'     => $p['opcions']['nivell'],
    'etiqueta'   => NIVELLS[$p['opcions']['nivell']]['etiqueta'],
    'pistesRival'=> (bool)$p['pistes'][1-$j],
    'temps'      => [$p['temps'][$j], $p['temps'][1-$j]],
    'rellotge'   => TEMPS_ACTIU,
    'rivalViu'   => (time() - (int)$p['vist'][1-$j]) < MARGE_TORNADA,
    'resultat'   => $p['resultat'],
    'ultima'     => $p['ultima'] ?? null,
  ];
}

function comprova(array $p, string $secret): int {
  foreach ($p['secrets'] as $j => $s) if (hash_equals($s, $secret)) return $j;
  error('No ets d\'aquesta partida', 403);
}

/* ============ ENTRADA ============ */
preparaDir();
neteja();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
  surt(['ok'=>true, 'php'=>PHP_VERSION, 'partides'=>count(glob(DIR_DADES.'/*.json') ?: []),
        'temps'=>TEMPS_ACTIU]);
}

$cos = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($cos)) $cos = $_POST;
$accio = (string)($cos['accio'] ?? '');

if ($accio === 'crea') {
  $nivell = max(1, min(3, (int)($cos['nivell'] ?? 1)));
  $ratio  = max(0.0, min(1.0, (float)($cos['ratio'] ?? 0.6)));
  $pistes = (bool)($cos['pistes'] ?? true);
  $niv = NIVELLS[$nivell];
  $baralla = creaBaralla($niv, $ratio);
  $mans = [array_splice($baralla, 0, MIDA_MA), array_splice($baralla, 0, MIDA_MA)];

  $i = 0;
  foreach ($baralla as $k => $f) if ($f['a'] !== $f['b']) { $i = $k; break; }
  $ini = array_splice($baralla, $i, 1)[0];

  $p = [
    'codi'    => codiNou(),
    'v'       => 1,
    'tocada'  => time(),
    'opcions' => ['nivell'=>$nivell, 'ratio'=>$ratio],
    'munt'    => $baralla,
    'mans'    => $mans,
    'tauler'  => [['a'=>$ini['a'],'b'=>$ini['b'],'caraA'=>$ini['caraA'],'caraB'=>$ini['caraB']]],
    'esq'     => $ini['a'],
    'dre'     => $ini['b'],
    'torn'    => 0,
    'estat'   => 'esperant',
    'passades'=> 0,
    'pistes'  => [$pistes, true],
    'temps'   => [TEMPS_PER_JUGADOR, TEMPS_PER_JUGADOR],
    'darrer'  => time(),
    'vist'    => [time(), 0],
    'secrets' => [bin2hex(random_bytes(9)), bin2hex(random_bytes(9))],
    'resultat'=> null,
    'ultima'  => null,
  ];
  desa($p);
  surt(['secret'=>$p['secrets'][0], 'estat'=>vista($p, 0)]);
}

if ($accio === 'entra') {
  $codi = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($cos['codi'] ?? '')));
  if (strlen($codi) !== 4) error('El codi ha de tenir quatre caràcters');
  $p = llegeix($codi);
  if ($p['estat'] !== 'esperant') error('Aquesta partida ja ha començat', 409);
  $p['estat'] = 'jugant';
  $p['pistes'][1] = (bool)($cos['pistes'] ?? true);
  $p['vist'][1] = time();
  $p['darrer']  = time();
  $p['tocada']  = time();
  $p['v']++;
  desa($p);
  surt(['secret'=>$p['secrets'][1], 'estat'=>vista($p, 1)]);
}

/* --- la resta demanen codi i secret --- */
$codi   = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($cos['codi'] ?? '')));
$secret = (string)($cos['secret'] ?? '');
if (strlen($codi) !== 4 || $secret === '') error('Falten dades de la partida');

$p = llegeix($codi);
$jo = comprova($p, $secret);
$p['vist'][$jo] = time();
$p['tocada'] = time();

if ($accio === 'estat') {
  actualitzaTemps($p);
  desa($p);
  if ((int)($cos['v'] ?? 0) === $p['v'] && !TEMPS_ACTIU) surt(['sensecanvi'=>true]);
  surt(['estat'=>vista($p, $jo)]);
}

if ($accio === 'pistes') {
  $p['pistes'][$jo] = (bool)($cos['valor'] ?? true);
  $p['v']++;
  desa($p);
  surt(['estat'=>vista($p, $jo)]);
}

if ($accio === 'plego') {
  $p['estat'] = 'acabada';
  $p['resultat'] = 'El jugador '.($jo+1).' ha deixat la partida';
  $p['v']++;
  desa($p);
  surt(['estat'=>vista($p, $jo)]);
}

/* --- accions de joc --- */
if (!in_array($accio, ['juga','roba','passa'], true)) error('Acció desconeguda');
if ($p['estat'] !== 'jugant') error('La partida no està en joc', 409);
actualitzaTemps($p);
if ($p['estat'] !== 'jugant') { desa($p); surt(['estat'=>vista($p, $jo)]); }
if ($p['torn'] !== $jo) error('Encara no et toca', 409);

if ($accio === 'juga') {
  $idf    = (int)($cos['fitxa'] ?? -1);
  $extrem = ($cos['extrem'] ?? 'dre') === 'esq' ? 'esq' : 'dre';
  $pos = null;
  foreach ($p['mans'][$jo] as $k => $f) if ($f['id'] === $idf) { $pos = $k; break; }
  if ($pos === null) error('Aquesta fitxa no és teva');
  $f = $p['mans'][$jo][$pos];
  if (!encaixa($f, $p, $extrem)) error('La fitxa no encaixa en aquest extrem');
  array_splice($p['mans'][$jo], $pos, 1);
  colloca($p, $f, $extrem);
  premiaTemps($p, $jo);
  $p['passades'] = 0;
  $p['ultima'] = ['jugador'=>$jo, 'que'=>'juga', 'caraA'=>$f['caraA'], 'caraB'=>$f['caraB']];
  if (!count($p['mans'][$jo])) {
    $p['estat'] = 'acabada';
    $p['resultat'] = 'Guanya el jugador '.($jo+1).', s\'ha quedat sense fitxes';
  } else {
    $p['torn'] = 1 - $jo;
  }
}

if ($accio === 'roba') {
  if (!count($p['munt'])) error('El munt és buit');
  $p['mans'][$jo][] = array_pop($p['munt']);
  $p['passades'] = 0;
  $p['ultima'] = ['jugador'=>$jo, 'que'=>'roba'];
  $p['torn'] = 1 - $jo;
}

if ($accio === 'passa') {
  if (!potJugar($p['mans'][$jo], $p) && !count($p['munt'])) $p['passades']++;
  else $p['passades'] = 0;
  $p['ultima'] = ['jugador'=>$jo, 'que'=>'passa'];
  if ($p['passades'] >= 2) {
    $a = count($p['mans'][0]); $b = count($p['mans'][1]);
    $p['estat'] = 'acabada';
    $p['resultat'] = $a === $b
      ? 'Empat, tots dos amb '.$a.' fitxes'
      : 'Ningú pot jugar · guanya el jugador '.($a < $b ? 1 : 2).', que té menys fitxes';
  } else {
    $p['torn'] = 1 - $jo;
  }
}

$p['darrer'] = time();
$p['v']++;
desa($p);
surt(['estat'=>vista($p, $jo)]);

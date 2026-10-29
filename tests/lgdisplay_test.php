<?php
/**
 * Tests für das LG-Display-Modul ohne Symcon (Aufruf: php tests/lgdisplay_test.php).
 * Bildet die nötigen Symcon-Funktionen nach und prüft Telegramme, Empfangsparser
 * und das Soll/Ist-Verhalten.
 */

const KR_READY = 10103;
const IPS_KERNELSTARTED = 10001;
const KL_MESSAGE = 10201;
const KL_WARNING = 10203;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;

$GLOBALS['socketStatus'] = 102;
$GLOBALS['socketProps']  = ['Host' => '', 'Port' => 0, 'Open' => false];
$GLOBALS['profiles']     = [];
$GLOBALS['log']          = [];

function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_InstanceExists($id) { return true; }
function IPS_GetInstance($id)
{
    if ($id === 999) {
        return ['InstanceStatus' => $GLOBALS['socketStatus']];
    }
    return ['ConnectionID' => 999, 'InstanceStatus' => 102];
}
function IPS_GetProperty($id, $name) { return $GLOBALS['socketProps'][$name]; }
function IPS_SetProperty($id, $name, $value) { $GLOBALS['socketProps'][$name] = $value; }
function IPS_ApplyChanges($id) { return true; }
function IPS_SemaphoreEnter($key, $ms) { return true; }
function IPS_SemaphoreLeave($key) { }
function IPS_VariableProfileExists($n) { return isset($GLOBALS['profiles'][$n]); }
function IPS_CreateVariableProfile($n, $t) { $GLOBALS['profiles'][$n] = []; }
function IPS_SetVariableProfileIcon($n, $i) { }
function IPS_SetVariableProfileValues($n, $a, $b, $c) { }
function IPS_SetVariableProfileAssociation($n, $v, $name, $icon, $color) { $GLOBALS['profiles'][$n][(int) $v] = $name; }
function IPS_GetVariableProfileList() { return array_keys($GLOBALS['profiles']); }
function IPS_SetHidden($id, $hidden) { $GLOBALS['hidden'][] = $id; }
function IPS_DeleteVariableProfile($n) { unset($GLOBALS['profiles'][$n]); }

class IPSModule
{
    public $InstanceID = 4711;
    public $tx = [];
    public $timers = [];
    public $status = 0;
    private $props = [];
    private $attrs = [];
    private $buffers = [];
    private $vars = [];

    public function __construct() { }
    public function Create() { }
    public function ApplyChanges() { }
    public function Destroy() { }

    protected function RequireParent($guid) { }
    protected function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function ReadPropertyString($n) { return (string) $this->props[$n]; }
    protected function ReadPropertyInteger($n) { return (int) $this->props[$n]; }
    protected function ReadAttributeString($n) { return (string) $this->attrs[$n]; }
    protected function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterTimer($n, $i, $s) { $this->timers[$n] = $i; }
    protected function SetTimerInterval($n, $i) { $this->timers[$n] = $i; }
    protected function RegisterMessage($id, $msg) { }
    protected function RegisterVariableBoolean($i, $n, $p, $pos) { $this->vars[$i] = false; }
    protected function RegisterVariableInteger($i, $n, $p, $pos) { $this->vars[$i] = 0; }
    protected function RegisterVariableFloat($i, $n, $p, $pos) { $this->vars[$i] = 0.0; }
    protected function RegisterVariableString($i, $n, $p, $pos) { $this->vars[$i] = ''; }
    protected function EnableAction($i) { }
    protected function SetBuffer($n, $v) { $this->buffers[$n] = $v; }
    protected function GetBuffer($n) { return $this->buffers[$n] ?? ''; }
    protected function SetStatus($s) { $this->status = $s; }
    protected function GetStatus() { return $this->status; }
    protected function SendDebug($a, $b, $c) { }
    protected function LogMessage($m, $t) { $GLOBALS['log'][] = $m; }
    protected function SendDataToParent($json) { $this->tx[] = json_decode($json, true)['Buffer']; }

    protected function GetIDForIdent($i) { return $i; }   // Ident als ID genügt für IPS_SetHidden
    public function GetValue($i) { return $this->vars[$i]; }
    public function SetValue($i, $v) { $this->vars[$i] = $v; }

    // Testzugriffe
    public function setProp($n, $v) { $this->props[$n] = $v; }
    public function setBuf($n, $v) { $this->buffers[$n] = $v; }
    public function getBuf($n) { return $this->buffers[$n] ?? ''; }
}

require __DIR__ . '/../LG Display/module.php';

$failed = 0;
function check($name, $cond)
{
    global $failed;
    echo ($cond ? '  ok    ' : '  FEHLER ') . $name . PHP_EOL;
    if (!$cond) {
        $failed++;
    }
}

function rx(LGDisplay $m, string $s)
{
    $m->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => $s]));
}

function fresh(): LGDisplay
{
    $GLOBALS['socketStatus'] = 102;
    $GLOBALS['socketProps']  = ['Host' => '', 'Port' => 0, 'Open' => false];
    $GLOBALS['log']          = [];
    $GLOBALS['hidden']       = [];
    $m = new LGDisplay();
    $m->Create();
    $m->setProp('Host', '192.0.2.10');
    $m->ApplyChanges();
    return $m;
}

echo 'Grundlagen' . PHP_EOL;
$m = fresh();
check('Socket bekommt Host, Port 9761 und Open', $GLOBALS['socketProps'] === ['Host' => '192.0.2.10', 'Port' => 9761, 'Open' => true]);
check('Erste Abfrage ist nur "ka 01 ff" (Power aus)', $m->tx === ["ka 01 ff\r"]);
check('Profile pro Instanz angelegt', isset($GLOBALS['profiles']['LGD.4711.Input'][0x90]));

echo 'Empfang und Reihenfolge' . PHP_EOL;
rx($m, 'a 01 OK01x');
check('Power an übernommen', $m->GetValue('Power') === true);
check('Online nach Antwort', $m->GetValue('Online') === true && $m->status === 102);
$m->Poll();
check('Nächste Runde: nur ein Kommando unterwegs', count($m->tx) === 2 && $m->tx[1] === "ka 01 ff\r");
rx($m, 'a 01 OK01x');
check('Nach Antwort folgt das nächste Kommando', end($m->tx) === "xb 01 ff\r");
rx($m, 'b 01 OK');
check('Halbe Antwort wird gepuffert', end($m->tx) === "xb 01 ff\r");
rx($m, 'a0x');
check('Eingang HDMI 1 (PC) = 0xA0', $m->GetValue('Input') === 0xA0);
rx($m, 'd 01 OK00x');
rx($m, 'g 01 OK4bx');
check('Hintergrundbeleuchtung 0x4b = 75', $m->GetValue('Backlight') === 75);
rx($m, 'f 01 OK14x');
rx($m, 'e 01 OK00x');
check('"ke 00" heißt stumm', $m->GetValue('Mute') === true);
check('Temperatur wird in der ersten Runde abgefragt', end($m->tx) === "dn 01 ff\r");
rx($m, 'n 01 OK2ax');
check('Temperatur 0x2a = 42 °C', $m->GetValue('Temperature') === 42.0);
check('Signalabfrage mit Unterkommando', end($m->tx) === "sv 01 02 ff\r");
rx($m, 'v 01 OK0201x');
check('Signal vorhanden', $m->GetValue('Signal') === true);
rx($m, 'y 01 OK508KCAB1234x');
check('Seriennummer', $m->GetValue('Serial') === '508KCAB1234');
rx($m, 'z 01 OK03.20.10x');
check('Firmware ("fz" antwortet mit "z")', $m->GetValue('Firmware') === '03.20.10');
check('Danach Kachelmodus ("dz" antwortet ebenfalls mit "z")', end($m->tx) === "dz 01 ff\r");
rx($m, 'z 01 OK010202x');
check('Videowand 2 × 2 ohne Kachel-ID', $m->GetValue('VideoWall') === '2 × 2');
check('Firmware bleibt trotz zweitem "z" unverändert', $m->GetValue('Firmware') === '03.20.10');
check('Kachel-ID kommt erst in der nächsten Runde', $m->getBuf('queue') === '[]' && $m->getBuf('inflight') === '[]');
$m->Poll();
for ($i = 0; $i < 5; $i++) {
    rx($m, ['a 01 OK01x', 'b 01 OKa0x', 'd 01 OK00x', 'g 01 OK4bx', 'f 01 OK14x'][$i]);
}
rx($m, 'e 01 OK00x');
check('Im Kachelmodus wird "di" abgefragt', end($m->tx) === "di 01 ff\r");
rx($m, 'i 01 OK03x');
check('Videowand 2 × 2, Kachel 3', $m->GetValue('VideoWall') === '2 × 2, Kachel 3');

echo 'Soll/Ist' . PHP_EOL;
$sent = count($m->tx);
$m->SetMute(false);
check('Stumm aus sendet "ke 01 01"', end($m->tx) === "ke 01 01\r");
check('Sollwert sofort sichtbar', $m->GetValue('Mute') === false);
check('Schneller Takt während Sollwert offen', $m->timers['PollTimer'] === 1000);
rx($m, 'e 01 OK01x');
check('Bestätigung beendet den schnellen Takt', $m->timers['PollTimer'] === 5000);

$m->SetInput(0xC0);
check('Eingang DisplayPort sendet "xb 01 c0"', end($m->tx) === "xb 01 c0\r");
rx($m, 'b 01 NGc0x');
check('NG stellt den alten Eingang wieder her', $m->GetValue('Input') === 0xA0);

$m->SetBacklight(30);
rx($m, 'g 01 OK1ex');
check('Hintergrundbeleuchtung bestätigt', $m->GetValue('Backlight') === 30);

$m->SetBacklight(80);
// Abfrage aus einer Runde, die noch den alten Wert liefert
$m->setBuf('inflight', json_encode(['cmd' => 'mg', 'data' => 'ff', 'since' => microtime(true)]));
rx($m, 'g 01 OK1ex');
check('Alter Wert überschreibt den Sollwert nicht', $m->GetValue('Backlight') === 80);

echo 'Set-ID, Grenzen, Sonderfälle' . PHP_EOL;
$m = fresh();
$m->setProp('SetID', 10);
$m->setBuf('inflight', '[]');
$m->setBuf('queue', '[]');
$m->Poll();
check('Set-ID 10 wird hexadezimal "0a"', end($m->tx) === "ka 0a ff\r");
rx($m, 'a 0a OK00x');
$m->setBuf('inflight', json_encode(['cmd' => 'xb', 'data' => 'ff', 'since' => microtime(true)]));
rx($m, 'b 0a OKe0x');
check('Eingang 0xE0 übernommen', $m->GetValue('Input') === 0xE0);
check('… mit eigener Beschriftung', ($GLOBALS['profiles']['LGD.4711.Input'][0xE0] ?? '') === 'Eingang 0xE0');
check('Ungültige Lautstärke abgelehnt', $m->SetVolume(101) === false);
check('SendRaw prüft das Format', $m->SendRaw('k', '01') === false && $m->SendRaw('dz', 'ff') === true);

echo 'Antworten des 55VX1D-B (192.168.10.116, 29.10.2026)' . PHP_EOL;
$m = fresh();
$replies = [
    'ka' => 'a 01 OK01x', 'xb' => 'b 01 OKa0x', 'kd' => 'd 01 OK00x', 'mg' => 'g 01 OK55x',
    'kf' => 'f 01 NGffx', 'ke' => 'e 01 NGffx', 'dn' => 'n 01 OK25x', 'sv' => 'v 01 OK0201x',
    'fy' => 'y 01 OK807KCJZLM124x', 'fz' => 'z 01 OK033070x', 'dz' => 'z 01 OK000202x', 'di' => 'i 01 NGffx'
];
$asked = [];
for ($round = 0; $round < 3; $round++) {
    $m->Poll();
    for ($guard = 0; $guard < 20 && $m->getBuf('inflight') !== '[]'; $guard++) {
        $cmd = substr(end($m->tx), 0, 2);
        $asked[] = $cmd;
        rx($m, $replies[$cmd]);
    }
}
check('Eingang HDMI 1 (PC)', $m->GetValue('Input') === 0xA0);
check('Hintergrundbeleuchtung 85', $m->GetValue('Backlight') === 85);
check('Temperatur 37 °C', $m->GetValue('Temperature') === 37.0);
check('Seriennummer', $m->GetValue('Serial') === '807KCJZLM124');
check('Firmware mit Punkten', $m->GetValue('Firmware') === '03.30.70');
check('Kachelmodus aus', $m->GetValue('VideoWall') === 'aus');
check('Lautstärke/Stumm nur einmal gefragt, dann ausgeblendet',
    count(array_keys($asked, 'kf')) === 1 && count(array_keys($asked, 'ke')) === 1 && $GLOBALS['hidden'] === ['Volume', 'Mute']);
check('"di" wird ohne Kachelmodus nie gefragt', !in_array('di', $asked, true));
check('Stumm-Fehler läuft nicht als abgelehnter Sollwert', $GLOBALS['log'] === []);

$m = fresh();
$GLOBALS['socketStatus'] = 200;
$m->setBuf('lastResponse', '0');
$m->Poll();
check('Ohne Antwort offline, Status 202', $m->GetValue('Online') === false && $m->status === 202);
check('Ausschalten offline wird abgelehnt', $m->PowerOff() === false);
check('Einschalten offline ohne MAC wird abgelehnt', $m->PowerOn() === false && $m->GetValue('Power') === false);
check('Stellbefehle offline werden nicht gesetzt', $m->SetBacklight(50) === false && $m->GetValue('Backlight') === 0);

$m = fresh();
$m->setProp('MAC', 'AA-BB-CC-00-11-22');
$form = json_decode($m->GetConfigurationForm(), true);
$info = '';
foreach ($form['elements'] as $e) {
    if (($e['name'] ?? '') === 'MacInfo') {
        $info = $e['caption'];
    }
}
check('MAC wird normalisiert angezeigt', $info === 'MAC-Adresse für Wake-on-LAN: aa:bb:cc:00:11:22');

$m->Destroy();
check('Destroy räumt die Instanzprofile ab', !isset($GLOBALS['profiles']['LGD.4711.Input']));

echo PHP_EOL . ($failed === 0 ? 'Alle Tests bestanden.' : $failed . ' Test(s) fehlgeschlagen.') . PHP_EOL;
exit($failed === 0 ? 0 : 1);

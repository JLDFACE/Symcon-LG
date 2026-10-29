<?php

/**
 * LG Signage-Display (webOS) über das LAN-Steuerprotokoll auf TCP-Port 9761.
 *
 * Gebaut für das Videowand-Display LG 55VX1D-B. Das Protokoll ist bei den
 * LG-Signage-Geräten dieser Generation gleich (Kapitel „RS-232C“ bzw.
 * „External Control Device Setup“ der Bedienungsanleitung) und läuft über
 * LAN mit denselben Telegrammen wie über RS-232C:
 *
 *   Anfrage   <cmd1><cmd2> <Set-ID> <Daten>\r      z. B. "ka 01 01\r"
 *   Antwort   <cmd2> <Set-ID> OK<Daten>x             z. B. "a 01 OK01x"
 *             <cmd2> <Set-ID> NG<Daten>x             abgelehnt
 *
 *  - Set-ID und Daten sind hexadezimal, Daten "ff" fragt den aktuellen Wert ab.
 *  - Antworten enden mit "x", nicht mit CR.
 *  - Set-ID 00 erreicht alle Geräte, die dann aber NICHT antworten. Das Modul
 *    spricht deshalb immer die eingestellte Set-ID an.
 *  - Die Antwort trägt nur den zweiten Kommandobuchstaben, und der ist nicht
 *    eindeutig ("fz" und "dz" antworten beide mit "z"). Es ist deshalb immer
 *    nur ein Kommando gleichzeitig unterwegs, die Antwort gehört zu ihm.
 *  - Im Standby beantwortet das Display nur "ka", alles andere quittiert es
 *    mit NG. Ebenso während der Wiedergabe aus dem internen Speicher oder von
 *    USB, dann gehen laut Handbuch nur ka, kf, ke und mc.
 *  - Ob das Display im Standby im Netz bleibt, hängt an seinen Einstellungen
 *    (Wake-on-LAN, PM-Modus). Ist es weg, holt es nur ein Magic Packet zurück.
 */
class LGDisplay extends IPSModule
{
    private const GUID_SOCKET  = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    private const GUID_SEND    = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    private const STATUS_OK          = 102;
    private const STATUS_NO_HOST     = 104;
    private const STATUS_NO_SOCKET   = 201;
    private const STATUS_UNREACHABLE = 202;

    // Stellkommandos und die Variable, die sie bedienen.
    private const COMMAND_IDENTS = [
        'ka' => 'Power',
        'xb' => 'Input',
        'kd' => 'ScreenOff',
        'mg' => 'Backlight',
        'kf' => 'Volume',
        'ke' => 'Mute'
    ];

    // Eingänge laut Handbuch. Welche davon das Gerät tatsächlich hat, hängt
    // am Modell; meldet es einen unbekannten Code, kommt er dazu.
    private const INPUTS = [
        0x40 => 'Component',
        0x60 => 'RGB',
        0x70 => 'DVI-D (PC)',
        0x80 => 'DVI-D',
        0x90 => 'HDMI 1',
        0xA0 => 'HDMI 1 (PC)',
        0x91 => 'HDMI 2/OPS',
        0xA1 => 'HDMI 2/OPS (PC)',
        0xC0 => 'DisplayPort',
        0xD0 => 'DisplayPort (PC)'
    ];

    public function Create()
    {
        parent::Create();

        $this->RequireParent(self::GUID_SOCKET);

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 9761);
        $this->RegisterPropertyInteger('SetID', 1);
        $this->RegisterPropertyString('MAC', '');

        $this->RegisterPropertyInteger('QueryInterval', 5000);
        // Solange ein gestellter Wert noch nicht bestätigt ist, wird in diesem
        // kürzeren Takt abgefragt.
        $this->RegisterPropertyInteger('QueryFastInterval', 1000);
        $this->RegisterPropertyInteger('ResponseTimeoutMs', 2000);
        $this->RegisterPropertyInteger('PendingTimeoutMs', 5000);
        // Einschalten dauert, per Wake-on-LAN erst recht: das Display muss
        // booten, bevor es antwortet.
        $this->RegisterPropertyInteger('PowerTimeoutMs', 60000);

        $this->RegisterAttributeString('LearnedMAC', '');
        $this->RegisterAttributeString('ExtraInputs', '[]');

        $this->RegisterTimer('PollTimer', 0, 'LGD_Poll($_IPS[\'TARGET\']);');
        $this->RegisterTimer('QueueTimer', 0, 'LGD_ProcessQueue($_IPS[\'TARGET\']);');

        $this->EnsureProfiles();

        $this->RegisterVariableBoolean('Online', 'Online', $this->GetProfileName('Online'), 0);
        $this->RegisterVariableBoolean('Power', 'Power', '~Switch', 1);
        $this->RegisterVariableInteger('Input', 'Eingang', $this->GetProfileName('Input'), 2);
        $this->RegisterVariableBoolean('ScreenOff', 'Bildschirm aus', '~Switch', 3);
        $this->RegisterVariableInteger('Backlight', 'Hintergrundbeleuchtung', '~Intensity.100', 4);
        $this->RegisterVariableInteger('Volume', 'Lautstärke', '~Intensity.100', 5);
        $this->RegisterVariableBoolean('Mute', 'Stumm', '~Switch', 6);
        $this->RegisterVariableBoolean('Signal', 'Signal', $this->GetProfileName('Signal'), 10);
        $this->RegisterVariableFloat('Temperature', 'Temperatur', '~Temperature', 11);
        $this->RegisterVariableString('VideoWall', 'Videowand', '', 12);
        $this->RegisterVariableString('Serial', 'Seriennummer', '', 20);
        $this->RegisterVariableString('Firmware', 'Firmware', '', 21);

        foreach (self::COMMAND_IDENTS as $ident) {
            $this->EnableAction($ident);
        }

        $this->ResetRuntime();
    }

    public function Destroy()
    {
        // Läuft auch bei jedem Modul-Update. Die Profile entstehen in
        // ApplyChanges() neu, unabhängig davon, ob das Display erreichbar ist.
        $prefix = 'LGD.' . $this->InstanceID . '.';
        foreach (IPS_GetVariableProfileList() as $profile) {
            if (strpos($profile, $prefix) === 0) {
                @IPS_DeleteVariableProfile($profile);
            }
        }

        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        $this->ResetRuntime();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetTimerInterval('PollTimer', 0);
            $this->SetStatus(self::STATUS_NO_HOST);
            return;
        }

        $parentId = $this->GetParentId();
        if ($parentId === 0) {
            // Ein Socket lässt sich von hier aus nicht anlegen und verbinden,
            // das muss über die Konsole bzw. die create-Kette passieren.
            $this->SetTimerInterval('PollTimer', 0);
            $this->SetStatus(self::STATUS_NO_SOCKET);
            return;
        }

        $this->ConfigureParent($parentId, $host, $this->GetPort());

        $this->SetStatus(self::STATUS_OK);
        $this->UpdatePollSpeed();
        $this->Poll();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);

        $mac = $this->GetMAC();
        $text = ($mac === '')
            ? 'MAC-Adresse noch unbekannt. Sie wird aus der ARP-Tabelle gelesen, sobald das Display einmal geantwortet hat.'
            : 'MAC-Adresse für Wake-on-LAN: ' . $mac
                . ($this->ReadPropertyString('MAC') === '' ? ' (automatisch ermittelt)' : '');

        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'MacInfo') {
                $element['caption'] = $text;
            }
        }
        unset($element);

        return json_encode($form);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Power':
                $this->SetPower((bool) $Value);
                break;
            case 'Input':
                $this->SetInput((int) $Value);
                break;
            case 'ScreenOff':
                $this->SetScreenOff((bool) $Value);
                break;
            case 'Backlight':
                $this->SetBacklight((int) $Value);
                break;
            case 'Volume':
                $this->SetVolume((int) $Value);
                break;
            case 'Mute':
                $this->SetMute((bool) $Value);
                break;
            default:
                throw new Exception('Unbekannter Ident: ' . $Ident);
        }
    }

    // ======================== Öffentliche Funktionen =======================

    public function PowerOn(): bool
    {
        return $this->SetPower(true);
    }

    public function PowerOff(): bool
    {
        return $this->SetPower(false);
    }

    /**
     * Ist das Display nicht erreichbar, geht zum Einschalten ein Magic Packet
     * hinaus. "ka 01" bleibt trotzdem in der Warteschlange: manche Geräte
     * wecken per WoL nur die Netzwerkkarte und brauchen den Befehl danach.
     */
    public function SetPower(bool $Power): bool
    {
        if ($this->IsOnline()) {
            return $this->SetTarget('Power', 'ka', $Power ? '01' : '00', $Power, $this->PowerTimeout());
        }

        if (!$Power) {
            $this->LogMessage('Nicht ausgeschaltet: Display ist nicht erreichbar.', KL_MESSAGE);
            return false;
        }

        if (!$this->WakeOnLan()) {
            $this->LogMessage(
                'Nicht eingeschaltet: Display ist nicht erreichbar und Wake-on-LAN war nicht möglich.',
                KL_MESSAGE
            );
            return false;
        }

        $this->SetPendingValue('Power', true, $this->GetValue('Power'), $this->PowerTimeout());
        $this->SetValue('Power', true);
        $this->Enqueue([['ka', '01', $this->PowerTimeout()]], true);

        return true;
    }

    /**
     * Eingangscode laut Handbuch, z. B. 0x90 = HDMI 1, 0xC0 = DisplayPort.
     * Die PC-Varianten (0xA0, 0xD0, 0x70 …) schalten auf denselben Anschluss,
     * aber mit PC-Bildverarbeitung.
     */
    public function SetInput(int $Input): bool
    {
        if ($Input < 0 || $Input > 0xFF) {
            return false;
        }

        return $this->SetTarget('Input', 'xb', sprintf('%02x', $Input), $Input);
    }

    /**
     * Schaltet nur das Bild ab, das Display bleibt an und erreichbar.
     */
    public function SetScreenOff(bool $Off): bool
    {
        return $this->SetTarget('ScreenOff', 'kd', $Off ? '01' : '00', $Off);
    }

    public function SetBacklight(int $Level): bool
    {
        if ($Level < 0 || $Level > 100) {
            return false;
        }

        return $this->SetTarget('Backlight', 'mg', sprintf('%02x', $Level), $Level);
    }

    public function SetVolume(int $Volume): bool
    {
        if ($Volume < 0 || $Volume > 100) {
            return false;
        }

        return $this->SetTarget('Volume', 'kf', sprintf('%02x', $Volume), $Volume);
    }

    /**
     * Achtung, im Protokoll verdreht: "ke 00" schaltet stumm, "ke 01" laut.
     */
    public function SetMute(bool $Mute): bool
    {
        return $this->SetTarget('Mute', 'ke', $Mute ? '00' : '01', $Mute);
    }

    /**
     * Taste der IR-Fernbedienung, Code laut Handbuch (z. B. 0x43 = Einstellungen).
     */
    public function SendKey(int $Code): bool
    {
        if ($Code < 0 || $Code > 0xFF) {
            return false;
        }

        return $this->SendRaw('mc', sprintf('%02x', $Code));
    }

    /**
     * Beliebiges Kommando, z. B. SendRaw('dz', 'ff'). Die Antwort steht im
     * Debug-Fenster der Instanz.
     */
    public function SendRaw(string $Command, string $Data): bool
    {
        $Command = strtolower(trim($Command));
        $Data    = strtolower(trim($Data));

        if (!preg_match('/^[a-z]{2}$/', $Command) || !preg_match('/^[0-9a-f]{2}( [0-9a-f]{2,12})*$/', $Data)) {
            $this->LogMessage('Ungültiges Kommando: "' . $Command . ' ' . $Data . '"', KL_WARNING);
            return false;
        }

        if (!$this->IsParentConnected()) {
            return false;
        }

        $this->Enqueue([[$Command, $Data, $this->PendingTimeout()]], true);

        return true;
    }

    /**
     * Schaltet Wake-on-LAN im Display ein ("fw 01"). Ohne das ist ein
     * ausgeschaltetes Display je nach Einstellung nicht mehr im Netz.
     */
    public function EnableWakeOnLan(): bool
    {
        return $this->SendRaw('fw', '01');
    }

    public function WakeOnLan(): bool
    {
        $mac = $this->GetMAC();
        if ($mac === '') {
            $this->SendDebug('WoL', 'MAC-Adresse unbekannt', 0);
            return false;
        }

        $bytes  = (string) hex2bin(str_replace(':', '', $mac));
        $packet = str_repeat("\xFF", 6) . str_repeat($bytes, 16);

        // Broadcast für den Normalfall, dazu direkt an die bekannte Adresse,
        // falls Broadcasts im Netz gefiltert werden.
        $targets = ['255.255.255.255'];
        $host    = trim($this->ReadPropertyString('Host'));
        if ($host !== '') {
            $targets[] = $host;
        }

        $sent = 0;
        foreach ($targets as $target) {
            if ($this->SendUdp($target, 9, $packet)) {
                $sent++;
            }
        }

        $this->SendDebug('WoL', 'Magic Packet an ' . $mac . ' (' . $sent . ' von ' . count($targets) . ' Zielen)', 0);

        return $sent > 0;
    }

    /**
     * Wird vom Timer gerufen: Erreichbarkeit bewerten, Verbindung pflegen
     * und eine neue Abfragerunde einreihen, sobald die vorige durch ist.
     */
    public function Poll()
    {
        $parentId = $this->GetParentId();
        if ($parentId === 0) {
            $this->SetDeviceStatus(self::STATUS_NO_SOCKET);
            return;
        }

        $this->ExpirePending();
        $this->RefreshOnline();
        $this->MaintainConnection($parentId);

        if (!$this->QueueIsIdle()) {
            $this->ProcessQueue();
            return;
        }

        $ttl   = 2 * $this->CurrentPollInterval();
        $round = [['ka', 'ff', $ttl]];

        // Im Standby antwortet das Display auf alles andere mit NG.
        if ($this->GetValue('Power')) {
            foreach (['xb', 'kd', 'mg', 'kf', 'ke'] as $command) {
                $round[] = [$command, 'ff', $ttl];
            }

            $count = (int) $this->GetBuffer('pollCount');
            $this->SetBuffer('pollCount', (string) ($count + 1));
            if ($count % 6 === 0) {
                $round[] = ['dn', 'ff', $ttl];
                $round[] = ['sv', '02 ff', $ttl];
            }

            // Geräteinfos nur, bis sie einmal da sind.
            $info = $this->GetJsonBuffer('info');
            foreach (['fy', 'fz', 'dz', 'di'] as $command) {
                if (!isset($info[$command])) {
                    $round[] = [$command, 'ff', $ttl];
                }
            }
        }

        $this->Enqueue($round);
    }

    /**
     * Schickt das nächste Kommando, sobald das vorige beantwortet oder
     * abgelaufen ist. Läuft über den QueueTimer und nach jeder Antwort.
     */
    public function ProcessQueue()
    {
        $this->WithLock(function () {
            $now      = microtime(true);
            $inflight = $this->GetJsonBuffer('inflight');

            if ($inflight !== []) {
                $ageMs = ($now - (float) $inflight['since']) * 1000;
                if ($ageMs < $this->ResponseTimeout()) {
                    $this->SetTimerInterval('QueueTimer', max(50, (int) ($this->ResponseTimeout() - $ageMs)));
                    return;
                }
                $this->SendDebug('TX', 'Keine Antwort auf "' . $inflight['cmd'] . ' ' . $inflight['data'] . '"', 0);
                $this->SetJsonBuffer('inflight', []);
            }

            // Abgelaufene Einträge verwerfen: ein Befehl, der minutenlang auf
            // eine Verbindung gewartet hat, soll nicht plötzlich noch wirken.
            $queue = array_values(array_filter($this->GetJsonBuffer('queue'), function ($item) use ($now) {
                return ($now - (float) $item['ts']) * 1000 < (int) $item['ttl'];
            }));

            if ($queue === [] || !$this->IsParentConnected()) {
                $this->SetJsonBuffer('queue', $queue);
                $this->SetTimerInterval('QueueTimer', 0);
                return;
            }

            $item = array_shift($queue);
            $this->SetJsonBuffer('queue', $queue);

            $frame = $item['cmd'] . ' ' . $this->SetIdHex() . ' ' . $item['data'];
            try {
                $this->SendDataToParent(json_encode([
                    'DataID' => self::GUID_SEND,
                    'Buffer' => $frame . "\r"
                ]));
            } catch (Exception $e) {
                $this->SendDebug('TX', 'Senden fehlgeschlagen: ' . $e->getMessage(), 0);
                $this->SetTimerInterval('QueueTimer', 0);
                return;
            }

            $this->SendDebug('TX', $frame, 0);
            $this->SetJsonBuffer('inflight', ['cmd' => $item['cmd'], 'data' => $item['data'], 'since' => $now]);
            $this->SetTimerInterval('QueueTimer', $this->ResponseTimeout());
        });
    }

    public function ReceiveData($JSONString)
    {
        $data  = json_decode($JSONString, true);
        $chunk = (string) ($data['Buffer'] ?? '');
        if ($chunk === '') {
            return;
        }

        $this->SendDebug('RX', $chunk, 0);

        $buffer = $this->GetBuffer('rx') . $chunk;

        while (preg_match('/([a-z]) ([0-9a-fA-F]{2,3}) (OK|NG)([^x]*)x/', $buffer, $match, PREG_OFFSET_CAPTURE)) {
            $buffer = substr($buffer, $match[0][1] + strlen($match[0][0]));
            $this->HandleReply($match[1][0], $match[3][0] === 'OK', trim($match[4][0]));
        }

        // Notbremse gegen einen mitwachsenden Puffer bei Dauermüll.
        if (strlen($buffer) > 512) {
            $buffer = substr($buffer, -64);
        }

        $this->SetBuffer('rx', $buffer);
    }

    // ============================= Empfang =================================

    private function HandleReply(string $letter, bool $ok, string $data): void
    {
        $this->SetBuffer('lastResponse', (string) microtime(true));
        $this->SetDeviceStatus(self::STATUS_OK);
        $this->LearnMAC();

        $inflight = [];
        $this->WithLock(function () use ($letter, &$inflight) {
            $current = $this->GetJsonBuffer('inflight');
            if ($current !== [] && $current['cmd'][1] === $letter) {
                $inflight = $current;
                $this->SetJsonBuffer('inflight', []);
            }
        });

        if ($inflight === []) {
            // Verspätete Antwort auf ein schon abgelaufenes Kommando. Sie
            // belegt, dass das Display lebt, ist aber keinem Wert zuzuordnen.
            $this->SendDebug('RX', 'Antwort "' . $letter . '" passt zu keinem offenen Kommando', 0);
            return;
        }

        $command = (string) $inflight['cmd'];
        $isQuery = substr((string) $inflight['data'], -2) === 'ff';

        if (!$ok) {
            $this->SendDebug('RX', 'Abgelehnt (NG): ' . $command . ' ' . $inflight['data'], 0);
            if (!$isQuery && isset(self::COMMAND_IDENTS[$command])) {
                $this->RejectPending(self::COMMAND_IDENTS[$command]);
            }
        } else {
            $this->ApplyReply($command, strtolower($data));
        }

        $this->ProcessQueue();
    }

    private function ApplyReply(string $command, string $data): void
    {
        switch ($command) {
            case 'ka':
                if ($this->IsHexByte($data)) {
                    $this->ApplyDeviceValue('Power', hexdec($data) === 1);
                }
                break;

            case 'xb':
                if ($this->IsHexByte($data)) {
                    $code = (int) hexdec($data);
                    $this->EnsureInputAssociation($code);
                    $this->ApplyDeviceValue('Input', $code);
                }
                break;

            case 'kd':
                if ($this->IsHexByte($data)) {
                    $this->ApplyDeviceValue('ScreenOff', hexdec($data) === 1);
                }
                break;

            case 'mg':
                if ($this->IsHexByte($data)) {
                    $this->ApplyDeviceValue('Backlight', (int) hexdec($data));
                }
                break;

            case 'kf':
                if ($this->IsHexByte($data)) {
                    $this->ApplyDeviceValue('Volume', (int) hexdec($data));
                }
                break;

            case 'ke':
                if ($this->IsHexByte($data)) {
                    $this->ApplyDeviceValue('Mute', hexdec($data) === 0);
                }
                break;

            case 'dn':
                if ($this->IsHexByte($data)) {
                    $this->SetValueIfChanged('Temperature', (float) hexdec($data));
                }
                break;

            case 'sv':
                // "0201" = Abfrage 02 (Signal), Ergebnis 01 (Signal vorhanden)
                if (strlen($data) >= 4 && substr($data, 0, 2) === '02') {
                    $this->SetValueIfChanged('Signal', substr($data, 2, 2) === '01');
                }
                break;

            case 'fy':
                $this->StoreInfo('fy', $data);
                $this->SetValueIfChanged('Serial', strtoupper($data));
                break;

            case 'fz':
                $this->StoreInfo('fz', $data);
                $this->SetValueIfChanged('Firmware', $data);
                break;

            case 'dz':
            case 'di':
                $this->StoreInfo($command, $data);
                $this->UpdateVideoWall();
                break;
        }
    }

    /**
     * "dz" liefert Kachelmodus an/aus, Spalten und Zeilen ("010202" = 2 × 2),
     * "di" die Position dieses Displays in der Wand.
     */
    private function UpdateVideoWall(): void
    {
        $info = $this->GetJsonBuffer('info');
        $tile = (string) ($info['dz'] ?? '');
        if (strlen($tile) < 6 || !ctype_xdigit($tile)) {
            return;
        }

        if (substr($tile, 0, 2) === '00') {
            $this->SetValueIfChanged('VideoWall', 'aus');
            return;
        }

        $text = hexdec(substr($tile, 2, 2)) . ' × ' . hexdec(substr($tile, 4, 2));
        $id   = (string) ($info['di'] ?? '');
        if ($this->IsHexByte($id)) {
            $text .= ', Kachel ' . hexdec($id);
        }

        $this->SetValueIfChanged('VideoWall', $text);
    }

    private function StoreInfo(string $command, string $data): void
    {
        $info = $this->GetJsonBuffer('info');
        $info[$command] = $data;
        $this->SetJsonBuffer('info', $info);
    }

    // ============================ Soll/Ist =================================

    /**
     * Stellt einen Wert. Die Variable zeigt den Sollwert sofort, sonst springt
     * die Bedienoberfläche auf den alten Wert zurück und erst der nächste Poll
     * zieht sie nach. Ist das Kommando nicht zustellbar, bleibt sie stehen.
     */
    private function SetTarget(string $ident, string $command, string $data, $value, int $timeoutMs = 0): bool
    {
        if (!$this->IsOnline() || !$this->IsParentConnected()) {
            $this->LogMessage('„' . $ident . '“ nicht gesetzt: Display ist nicht erreichbar.', KL_MESSAGE);
            return false;
        }

        if ($timeoutMs <= 0) {
            $timeoutMs = $this->PendingTimeout();
        }

        $this->SetPendingValue($ident, $value, $this->GetValue($ident), $timeoutMs);
        $this->SetValue($ident, $value);
        $this->Enqueue([[$command, $data, $timeoutMs]], true);

        return true;
    }

    private function SetPendingValue(string $ident, $value, $previous, int $timeoutMs): void
    {
        $pending = $this->GetJsonBuffer('pending');
        // Steht schon ein Sollwert aus, bleibt dessen Ausgangswert gültig.
        if (isset($pending[$ident])) {
            $previous = $pending[$ident]['previous'];
        }
        $pending[$ident] = [
            'value'    => $value,
            'previous' => $previous,
            'since'    => microtime(true),
            'timeout'  => $timeoutMs
        ];
        $this->SetJsonBuffer('pending', $pending);
        $this->UpdatePollSpeed();
    }

    /**
     * Übernimmt einen vom Display gemeldeten Wert.
     *
     * Steht für den Ident ein Sollwert aus, bleibt die Variable darauf stehen,
     * bis das Display ihn bestätigt. Meldet es einen dritten Wert, wurde am
     * Gerät oder per Fernbedienung bedient, und der gewinnt sofort; die ersten
     * 1,5 s bleiben ausgenommen, weil beim Umschalten Zwischenwerte kommen.
     */
    private function ApplyDeviceValue(string $ident, $value): void
    {
        $pending = $this->GetJsonBuffer('pending');

        if (!isset($pending[$ident])) {
            $this->SetValueIfChanged($ident, $value);
            return;
        }

        $entry     = $pending[$ident];
        $elapsedMs = (microtime(true) - (float) $entry['since']) * 1000;

        $confirmed = ($value === $entry['value']);
        $overruled = ($elapsedMs >= 1500 && $entry['previous'] !== null && $value !== $entry['previous']);

        if (!$confirmed && !$overruled) {
            return;
        }

        unset($pending[$ident]);
        $this->SetJsonBuffer('pending', $pending);
        $this->UpdatePollSpeed();
        $this->SetValueIfChanged($ident, $value);
    }

    /**
     * Das Display hat den Wert abgelehnt (NG): alter Wert zurück.
     */
    private function RejectPending(string $ident): void
    {
        $pending = $this->GetJsonBuffer('pending');
        if (!isset($pending[$ident])) {
            return;
        }

        $previous = $pending[$ident]['previous'];
        unset($pending[$ident]);
        $this->SetJsonBuffer('pending', $pending);
        $this->UpdatePollSpeed();

        if ($previous !== null) {
            $this->SetValue($ident, $previous);
        }
        $this->LogMessage('„' . $ident . '“ vom Display abgelehnt, alter Wert wiederhergestellt.', KL_MESSAGE);
    }

    /**
     * Sollwerte, die das Display in ihrer Frist nicht bestätigt hat, werden
     * zurückgenommen. Typischer Fall: Einschalten per Wake-on-LAN, aber das
     * Display ist nie im Netz aufgetaucht.
     */
    private function ExpirePending(): void
    {
        $pending = $this->GetJsonBuffer('pending');
        $now     = microtime(true);
        $changed = false;

        foreach ($pending as $ident => $entry) {
            if (($now - (float) $entry['since']) * 1000 < (int) $entry['timeout']) {
                continue;
            }
            unset($pending[$ident]);
            $changed = true;
            if ($entry['previous'] !== null) {
                $this->SetValue($ident, $entry['previous']);
            }
            $this->LogMessage('„' . $ident . '“ vom Display nicht bestätigt, alter Wert wiederhergestellt.', KL_MESSAGE);
        }

        if ($changed) {
            $this->SetJsonBuffer('pending', $pending);
            $this->UpdatePollSpeed();
        }
    }

    // ========================== Warteschlange ==============================

    /**
     * $items: Liste aus [Kommando, Daten, Gültigkeit in ms].
     *
     * Stellbefehle ($priority) kommen nach vorn, vor die laufende Abfragerunde.
     * Ein noch nicht gesendeter Stellbefehl desselben Kommandos wird dabei
     * ersetzt: es zählt der letzte Wert.
     */
    private function Enqueue(array $items, bool $priority = false): void
    {
        $this->WithLock(function () use ($items, $priority) {
            $queue = $this->GetJsonBuffer('queue');
            $now   = microtime(true);
            $new   = [];

            foreach ($items as $item) {
                $entry = ['cmd' => $item[0], 'data' => $item[1], 'ttl' => (int) $item[2], 'ts' => $now];
                if ($priority) {
                    $queue = array_values(array_filter($queue, function ($queued) use ($entry) {
                        return !($queued['cmd'] === $entry['cmd'] && substr($queued['data'], -2) !== 'ff');
                    }));
                }
                $new[] = $entry;
            }

            $queue = $priority ? array_merge($new, $queue) : array_merge($queue, $new);
            $this->SetJsonBuffer('queue', $queue);
        });

        $this->ProcessQueue();
    }

    private function QueueIsIdle(): bool
    {
        return $this->GetJsonBuffer('queue') === [] && $this->GetJsonBuffer('inflight') === [];
    }

    // ===================== Verbindung und Erreichbarkeit ===================

    /**
     * Online heißt: das Display hat in den letzten drei Abfragetakten
     * (mindestens 15 s) geantwortet.
     */
    private function RefreshOnline(): void
    {
        $silentMs = (microtime(true) - (float) $this->GetBuffer('lastResponse')) * 1000;
        $limit    = max(3 * $this->ReadPropertyInteger('QueryInterval'), 15000);

        $this->SetDeviceStatus($silentMs < $limit ? self::STATUS_OK : self::STATUS_UNREACHABLE);
    }

    /**
     * Hält den Client Socket am Leben, mit 30 s Abstand zwischen zwei
     * Versuchen. Zwei Fälle:
     *  - Socket getrennt (Display war aus): neu verbinden, sobald der Port
     *    wieder annimmt.
     *  - Socket verbunden, aber das Display schweigt: Sitzung neu aufbauen.
     *    Bei den Samsung-Displays blieb eine Sitzung so stehen, eine frische
     *    antwortete sofort; bei LG ist das nicht ausgeschlossen.
     */
    private function MaintainConnection(int $parentId): void
    {
        $now = microtime(true);
        if ($now - (float) $this->GetBuffer('lastReconnect') < 30) {
            return;
        }

        if (!(bool) IPS_GetProperty($parentId, 'Open')) {
            return;
        }

        if ($this->IsParentConnected()) {
            $silentMs = ($now - (float) $this->GetBuffer('lastResponse')) * 1000;
            if ($silentMs < 3 * $this->ReadPropertyInteger('QueryInterval')) {
                return;
            }
            $this->SendDebug('Socket', 'Verbunden, aber seit ' . round($silentMs / 1000) . ' s stumm: neu aufbauen', 0);
        } else {
            // Bewusst TCP statt Sys_Ping: das braucht CAP_NET_RAW und liefert
            // auf der Catan C1 grundsätzlich false.
            if (!$this->TestTcp(trim($this->ReadPropertyString('Host')), $this->GetPort(), 500)) {
                return;
            }
            $this->SendDebug('Socket', 'Port nimmt wieder an: neu verbinden', 0);
        }

        $this->SetBuffer('lastReconnect', (string) $now);
        $this->SetJsonBuffer('inflight', []);
        $this->SetBuffer('rx', '');

        $this->CallSilenced(function () use ($parentId) {
            IPS_SetProperty($parentId, 'Open', false);
            IPS_ApplyChanges($parentId);
            IPS_SetProperty($parentId, 'Open', true);
            IPS_ApplyChanges($parentId);
        });
    }

    private function ConfigureParent(int $parentId, string $host, int $port): void
    {
        $changed = false;

        if ((string) IPS_GetProperty($parentId, 'Host') !== $host) {
            IPS_SetProperty($parentId, 'Host', $host);
            $changed = true;
        }
        if ((int) IPS_GetProperty($parentId, 'Port') !== $port) {
            IPS_SetProperty($parentId, 'Port', $port);
            $changed = true;
        }
        if (!(bool) IPS_GetProperty($parentId, 'Open')) {
            IPS_SetProperty($parentId, 'Open', true);
            $changed = true;
        }

        if ($changed) {
            $this->CallSilenced(function () use ($parentId) {
                IPS_ApplyChanges($parentId);
            });
        }
    }

    /**
     * Setzt Instanzstatus und Online-Variable gemeinsam.
     */
    private function SetDeviceStatus(int $status): void
    {
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
        $this->SetValueIfChanged('Online', $status === self::STATUS_OK);
    }

    private function IsOnline(): bool
    {
        return (bool) $this->GetValue('Online');
    }

    private function GetParentId(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);

        return (int) ($instance['ConnectionID'] ?? 0);
    }

    private function IsParentConnected(): bool
    {
        $parentId = $this->GetParentId();
        if ($parentId === 0 || !IPS_InstanceExists($parentId)) {
            return false;
        }

        return (int) IPS_GetInstance($parentId)['InstanceStatus'] === self::STATUS_OK;
    }

    private function TestTcp(string $host, int $port, int $timeoutMs): bool
    {
        if ($host === '') {
            return false;
        }

        $errno  = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, max(0.1, $timeoutMs / 1000));
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    private function SendUdp(string $host, int $port, string $payload): bool
    {
        $context = stream_context_create(['socket' => ['so_broadcast' => true]]);
        $errno   = 0;
        $errstr  = '';
        $socket  = @stream_socket_client('udp://' . $host . ':' . $port, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return false;
        }
        $written = @fwrite($socket, $payload);
        fclose($socket);

        return $written === strlen($payload);
    }

    // ============================== MAC ====================================

    private function GetMAC(): string
    {
        $configured = $this->NormalizeMAC($this->ReadPropertyString('MAC'));

        return ($configured !== '') ? $configured : $this->ReadAttributeString('LearnedMAC');
    }

    /**
     * Das Protokoll liefert keine MAC. Nach einer Antwort steht das Display
     * aber in der ARP-Tabelle der Box, von dort wird sie einmal übernommen.
     * Geht nur unter Linux (SymBox, Catan); sonst die MAC von Hand eintragen.
     */
    private function LearnMAC(): void
    {
        if ($this->GetMAC() !== '' || !is_readable('/proc/net/arp')) {
            return;
        }

        $last = (float) $this->GetBuffer('macTried');
        if (microtime(true) - $last < 600) {
            return;
        }
        $this->SetBuffer('macTried', (string) microtime(true));

        $ip = gethostbyname(trim($this->ReadPropertyString('Host')));
        foreach (file('/proc/net/arp', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if (count($columns) < 4 || $columns[0] !== $ip) {
                continue;
            }
            $mac = $this->NormalizeMAC($columns[3]);
            if ($mac !== '' && $mac !== '00:00:00:00:00:00') {
                $this->WriteAttributeString('LearnedMAC', $mac);
                $this->SendDebug('MAC', $mac . ' aus der ARP-Tabelle übernommen', 0);
            }
            return;
        }
    }

    private function NormalizeMAC(string $mac): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac));
        if (strlen($hex) !== 12) {
            return '';
        }

        return implode(':', str_split($hex, 2));
    }

    // ============================= Profile =================================

    private function GetProfileName(string $suffix): string
    {
        return 'LGD.' . $this->InstanceID . '.' . $suffix;
    }

    /**
     * Anlegen bedingt, konfigurieren immer: so erreichen spätere Korrekturen
     * auch bestehende Profile.
     */
    private function EnsureProfiles(): void
    {
        // Keine Signalfarben: Blau für den Normalzustand, Grau für „weg“.
        $online = $this->GetProfileName('Online');
        if (!IPS_VariableProfileExists($online)) {
            IPS_CreateVariableProfile($online, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileIcon($online, 'Network');
        IPS_SetVariableProfileAssociation($online, false, 'Offline', '', 0x9E9E9E);
        IPS_SetVariableProfileAssociation($online, true, 'Online', '', 0x3A7BD5);

        $signal = $this->GetProfileName('Signal');
        if (!IPS_VariableProfileExists($signal)) {
            IPS_CreateVariableProfile($signal, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileIcon($signal, 'TV');
        IPS_SetVariableProfileAssociation($signal, false, 'Kein Signal', '', 0x9E9E9E);
        IPS_SetVariableProfileAssociation($signal, true, 'Signal', '', 0x3A7BD5);

        $input = $this->GetProfileName('Input');
        if (!IPS_VariableProfileExists($input)) {
            IPS_CreateVariableProfile($input, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileIcon($input, 'TV');
        IPS_SetVariableProfileValues($input, 0, 0, 0);
        foreach ($this->InputList() as $code => $name) {
            IPS_SetVariableProfileAssociation($input, $code, $name, '', -1);
        }
    }

    private function InputList(): array
    {
        $list  = self::INPUTS;
        $extra = json_decode($this->ReadAttributeString('ExtraInputs'), true);
        foreach (is_array($extra) ? $extra : [] as $code) {
            if (!isset($list[(int) $code])) {
                $list[(int) $code] = sprintf('Eingang 0x%02X', (int) $code);
            }
        }
        ksort($list);

        return $list;
    }

    private function EnsureInputAssociation(int $code): void
    {
        if (isset($this->InputList()[$code])) {
            return;
        }

        $extra   = json_decode($this->ReadAttributeString('ExtraInputs'), true);
        $extra   = is_array($extra) ? $extra : [];
        $extra[] = $code;
        $this->WriteAttributeString('ExtraInputs', json_encode(array_values(array_unique($extra))));

        IPS_SetVariableProfileAssociation($this->GetProfileName('Input'), $code, sprintf('Eingang 0x%02X', $code), '', -1);
    }

    // ============================== Hilfen =================================

    private function ResetRuntime(): void
    {
        $this->SetBuffer('rx', '');
        $this->SetJsonBuffer('queue', []);
        $this->SetJsonBuffer('inflight', []);
        // Offene Sollwerte aus einer früheren Sitzung sind wertlos.
        $this->SetJsonBuffer('pending', []);
        $this->SetJsonBuffer('info', []);
        $this->SetBuffer('pollCount', '0');
        $this->SetBuffer('pollInterval', '0');
        // Karenz: nicht schon vor der ersten Antwort als offline melden.
        $this->SetBuffer('lastResponse', (string) microtime(true));
        $this->SetBuffer('lastReconnect', '0');
        $this->SetBuffer('macTried', '0');
    }

    /**
     * Solange ein Sollwert aussteht, wird schneller abgefragt.
     */
    private function UpdatePollSpeed(): void
    {
        $target = $this->CurrentPollInterval();
        if ((int) $this->GetBuffer('pollInterval') === $target) {
            return;
        }

        $this->SetTimerInterval('PollTimer', $target);
        $this->SetBuffer('pollInterval', (string) $target);
    }

    private function CurrentPollInterval(): int
    {
        $normal = max(1000, $this->ReadPropertyInteger('QueryInterval'));
        $fast   = max(500, $this->ReadPropertyInteger('QueryFastInterval'));

        return ($this->GetJsonBuffer('pending') === []) ? $normal : min($fast, $normal);
    }

    private function ResponseTimeout(): int
    {
        return max(300, $this->ReadPropertyInteger('ResponseTimeoutMs'));
    }

    private function PendingTimeout(): int
    {
        return max(1000, $this->ReadPropertyInteger('PendingTimeoutMs'));
    }

    private function PowerTimeout(): int
    {
        return max(5000, $this->ReadPropertyInteger('PowerTimeoutMs'));
    }

    private function GetPort(): int
    {
        $port = $this->ReadPropertyInteger('Port');

        return ($port >= 1 && $port <= 65535) ? $port : 9761;
    }

    private function SetIdHex(): string
    {
        return sprintf('%02x', max(1, min(1000, $this->ReadPropertyInteger('SetID'))));
    }

    private function IsHexByte(string $value): bool
    {
        return strlen($value) === 2 && ctype_xdigit($value);
    }

    private function GetJsonBuffer(string $name): array
    {
        $value = json_decode((string) $this->GetBuffer($name), true);

        return is_array($value) ? $value : [];
    }

    private function SetJsonBuffer(string $name, array $value): void
    {
        $this->SetBuffer($name, json_encode($value));
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        $old = $this->GetValue($ident);
        if (is_float($old) || is_float($value)) {
            if (round((float) $old, 3) === round((float) $value, 3)) {
                return;
            }
        } elseif ($old === $value) {
            return;
        }

        $this->SetValue($ident, $value);
    }

    /**
     * ReceiveData und die Timer laufen in eigenen Threads und teilen sich
     * Warteschlange und Inflight-Puffer. Klappt die Sperre nicht, fällt der
     * Schritt aus; der nächste Timerlauf holt ihn nach.
     */
    private function WithLock(callable $fn): bool
    {
        $key = 'LGD_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($key, 500)) {
            $this->SendDebug('Lock', 'Sperre nicht erhalten', 0);
            return false;
        }
        try {
            $fn();
        } finally {
            IPS_SemaphoreLeave($key);
        }

        return true;
    }

    private function CallSilenced(callable $fn): void
    {
        set_error_handler(function () {
            return true;
        });
        try {
            $fn();
        } catch (Exception $e) {
            $this->SendDebug('Fehler', $e->getMessage(), 0);
        } finally {
            restore_error_handler();
        }
    }
}

# LG Signage Display

IP-Symcon-Modul für LG-Signage-Displays (webOS) über das LAN-Steuerprotokoll auf TCP-Port 9761.
Gebaut für das Videowand-Display **LG 55VX1D-B**. Das Protokoll ist bei den LG-Signage-Geräten
dieser Generation gleich, andere Modelle sollten deshalb ebenfalls funktionieren.

## Einrichtung

1. Modul über Module Control hinzufügen: `https://github.com/JLDFACE/Symcon-LG`
2. Instanz „LG Display“ über die Konsole anlegen. Dabei entsteht der Client Socket automatisch.
   Wer die Instanz per Skript anlegt, muss den Client Socket selbst erzeugen und mit
   `IPS_ConnectInstance` verbinden.
3. IP-Adresse und **Set-ID** eintragen. Die Set-ID steht im Menü des Displays; in einer Videowand
   hat jedes Display eine eigene Set-ID und bekommt eine eigene Instanz.
4. Damit sich ein ausgeschaltetes Display wieder einschalten lässt, im Display **Wake-on-LAN**
   aktivieren, entweder im Menü oder über die Schaltfläche „Wake-on-LAN im Display einschalten“.
   Die MAC-Adresse liest das Modul nach der ersten Antwort aus der ARP-Tabelle der Box (nur unter
   Linux). Alternativ lässt sie sich von Hand eintragen.

## Variablen

| Ident | Name | Typ | Kommando | Bemerkung |
|---|---|---|---|---|
| `Online` | Online | bool | – | Display hat in den letzten drei Abfragetakten (mind. 15 s) geantwortet |
| `Power` | Power | bool | `ka` | schaltbar, offline per Wake-on-LAN |
| `Input` | Eingang | int | `xb` | schaltbar, Codes laut Handbuch, unbekannte kommen ins Profil |
| `ScreenOff` | Bildschirm aus | bool | `kd` | schaltbar, nur das Bild aus, das Display bleibt an |
| `Backlight` | Hintergrundbeleuchtung | int 0–100 | `mg` | schaltbar |
| `Volume` | Lautstärke | int 0–100 | `kf` | schaltbar |
| `Mute` | Stumm | bool | `ke` | schaltbar (im Protokoll verdreht: `00` = stumm) |
| `Signal` | Signal | bool | `sv 02` | am aktuellen Eingang liegt ein Signal an |
| `Temperature` | Temperatur | float | `dn` | Innentemperatur in °C |
| `VideoWall` | Videowand | string | `dz`, `di` | z. B. „2 × 2, Kachel 3“ oder „aus“ |
| `Serial` | Seriennummer | string | `fy` | |
| `Firmware` | Firmware | string | `fz` | |

## Funktionen

```php
LGD_PowerOn(int $id): bool
LGD_PowerOff(int $id): bool
LGD_SetPower(int $id, bool $Power): bool
LGD_SetInput(int $id, int $Input): bool          // 0x90 HDMI 1, 0xA0 HDMI 1 (PC), 0xC0 DisplayPort …
LGD_SetScreenOff(int $id, bool $Off): bool
LGD_SetBacklight(int $id, int $Level): bool
LGD_SetVolume(int $id, int $Volume): bool
LGD_SetMute(int $id, bool $Mute): bool
LGD_SendKey(int $id, int $Code): bool            // IR-Code laut Handbuch
LGD_SendRaw(int $id, string $Command, string $Data): bool   // z. B. ('dz', 'ff')
LGD_WakeOnLan(int $id): bool
LGD_EnableWakeOnLan(int $id): bool
LGD_Poll(int $id)
```

## Verhalten

- **Ein Kommando gleichzeitig.** Die Antwort trägt nur den zweiten Kommandobuchstaben, und der ist
  nicht eindeutig (`fz` und `dz` antworten beide mit `z`). Das nächste Kommando geht erst raus,
  wenn das vorige beantwortet oder abgelaufen ist. Stellbefehle überholen die laufende Abfragerunde.
- **Soll/Ist wie beim PJLink- und Samsung-Modul.** Ein gestellter Wert erscheint sofort, die
  Abfrage überschreibt ihn nicht, bis das Display ihn bestätigt. Bei NG oder ohne Bestätigung
  innerhalb der Frist kommt der alte Wert zurück. Meldet das Display einen dritten Wert, wurde am
  Gerät bedient, und der gewinnt.
- **Im Standby** wird nur `ka` abgefragt, alles andere lehnt das Display dann ab.
- **Verbindung:** Der Client Socket wird alle 30 s neu verbunden, sobald der Port wieder annimmt,
  und neu aufgebaut, wenn er zwar verbunden ist, das Display aber schweigt. Erreichbarkeit wird per
  TCP geprüft, nicht per `Sys_Ping` (geht auf der Catan C1 nicht).

## Noch nicht am Gerät geprüft

Das Modul ist nach dem LG-Handbuch gebaut und mit `tests/lgdisplay_test.php` gegen nachgebildete
Antworten getestet, aber noch nicht an einem echten 55VX1D-B gelaufen. Offen sind vor allem:

- ob Seriennummer und Firmware als Klartext kommen (laut Handbuch „ASCII“)
- welche Eingangscodes das Gerät meldet, besonders für den internen Player
- ob das Display im Standby im Netz bleibt und wie lange es nach dem Einschalten per Wake-on-LAN braucht
- ob es mehr als eine Steuerverbindung auf 9761 zulässt

## Tests

```sh
php tests/lgdisplay_test.php
```

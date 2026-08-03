<?php
/* ═══════════════════════════════════════════════════════════════
   Adopcja Serca - automatyczne przypomnienia o zaległych wpłatach.
   Uruchamiać z crona:  php adopcja/cron-przypomnienia.php
   Podgląd bez wysyłki:  php adopcja/cron-przypomnienia.php --dry
   ───────────────────────────────────────────────────────────────
   Zasady ustalone z fundacją 2026-08-03:
   - próg: zaległość od 2 miesięcy (bieżący miesiąc NIE liczy się jako zaległy),
   - ponawianie co 14 dni, dopóki zaległość trwa,
   - wpłata przerywa cykl sama z siebie: po zaksięgowaniu zaległość spada
     poniżej progu i darczyńca wypada z listy,
   - jeden mail na darczyńcę, nawet gdy zalega przy kilku dzieciach,
   - kopia każdego maila idzie do fundacji.
   Darczyńcy bez adresu e-mail są raportowani do ręcznego kontaktu.
  ═══════════════════════════════════════════════════════════════ */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail-przypomnienie.php';

const PRZYPOMNIENIE_PROG_MIESIECY = 2;    // od ilu zaległych miesięcy wysyłamy
const PRZYPOMNIENIE_PONOWIENIE_DNI = 14;  // co ile dni ponawiamy

$dry = in_array('--dry', $argv, true);
$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');   // zegar PHP, nie NOW() - baza bywa w innej strefie

echo '[' . $now . '] przypomnienia o zaległościach' . ($dry ? ' (PODGLĄD, nic nie wysyłam)' : '') . "\n";

try {
    adopt_db_ensure_schema();
    $pdo = payu_db();

    // ── kto zalega (adopcje aktywne/oczekujące, ze znanym startem) ──
    $ads = array_filter(adopt_adoption_list_all(),
        fn($a) => in_array($a['status'], ['pending', 'active'], true) && $a['start_month'] !== null);
    $pays = adopt_payments_by_adoptions(array_column($ads, 'id'));

    $perDonor = [];
    foreach ($ads as $a) {
        $miss = adopt_arrears($a['start_month'], $a['end_month'], $pays[(int)$a['id']] ?? [], $today);
        if (count($miss) < PRZYPOMNIENIE_PROG_MIESIECY) continue;
        $perDonor[(int)$a['donor_id']][] = [
            'adoption_id'   => (int)$a['id'],
            'child_name'    => $a['child_name'],
            'child_number'  => $a['child_number'],
            'months'        => $miss,
            'amount_grosze' => (int)$a['amount_grosze'],
        ];
    }
    if (!$perDonor) { echo "  nikt nie przekracza progu " . PRZYPOMNIENIE_PROG_MIESIECY . " miesięcy - koniec\n"; exit(0); }

    // ── kiedy ostatnio pisaliśmy (blokada ponawiania) ──
    $lastSent = [];
    foreach ($pdo->query('SELECT donor_id, MAX(sent_at) AS ost FROM adopt_reminders GROUP BY donor_id') as $r) {
        $lastSent[(int)$r['donor_id']] = $r['ost'];
    }

    $wyslane = 0; $pominieteCzas = 0; $bezMaila = []; $bledy = 0;
    foreach ($perDonor as $donorId => $items) {
        $donor = adopt_donor_get($donorId);
        if (!$donor) continue;

        $mies = array_sum(array_map(fn($i) => count($i['months']), $items));
        $kwota = array_sum(array_map(fn($i) => count($i['months']) * $i['amount_grosze'], $items));
        $opis = $donor['full_name'] . ' - ' . $mies . ' mies., ' . number_format($kwota / 100, 0, ',', ' ') . ' zł';

        if (trim((string)($donor['email'] ?? '')) === '') { $bezMaila[] = $opis; continue; }

        $ost = $lastSent[$donorId] ?? null;
        if ($ost !== null) {
            $dni = (int)floor((strtotime($now) - strtotime($ost)) / 86400);
            if ($dni < PRZYPOMNIENIE_PONOWIENIE_DNI) {
                echo "  pomijam: $opis (pisaliśmy $dni dni temu)\n";
                $pominieteCzas++;
                continue;
            }
        }

        if ($dry) { echo "  wysłałbym: $opis <" . $donor['email'] . ">\n"; $wyslane++; continue; }

        if (adopt_mail_arrears_reminder($donor, $items)) {
            $st = $pdo->prepare(
                'INSERT INTO adopt_reminders (donor_id, sent_at, months_total, amount_grosze, detail)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([$donorId, $now, $mies, $kwota, mb_substr($opis, 0, 500)]);
            echo "  wysłane: $opis <" . $donor['email'] . ">\n";
            $wyslane++;
        } else {
            echo "  BŁĄD wysyłki: $opis\n";
            $bledy++;
        }
    }

    echo '  podsumowanie: ' . ($dry ? 'do wysłania' : 'wysłanych') . " $wyslane, "
       . "pominiętych (za wcześnie na ponowienie) $pominieteCzas, bez e-maila " . count($bezMaila)
       . ($bledy ? ", błędów $bledy" : '') . "\n";
    foreach ($bezMaila as $b) echo "  BEZ E-MAILA (kontakt ręczny): $b\n";
    exit($bledy > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . "\n");
    exit(1);
}

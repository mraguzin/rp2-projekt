<?php
// Nedeterministički konačni automat
use Helpers\Color;

class Cvor {
    //public $stanje; // ime
    public $zavrsno;
    public $x, $y; // koordinate na canvasu

    //public const BOJA = new Color(128, 128, 128); // siva
    //public const BOJA_RUBA = new Color(0, 0, 0); // crna
    public const RADIJUS = 10.0;
    public const MARGINA = 5.0;

    public function __construct($zavrsno, $x, $y)
    {
        $this->zavrsno = $zavrsno;
        $this->x = $x;
        $this->y = $y;
    }

    public static function izJSON($json) {
        $obj = json_decode($json, true);
        $cvor = new Cvor($obj['zavrsno'], $obj['x'], $obj['y']);

        return $cvor;
    }
}

class NKA {
    public $cvorovi = []; // hash mapa, po imenu čvora tj. stanju
    public $listaSusjednosti = []; // kao gore, dakle asociramo ime sa susjedima, kako bismo izbjegli probleme kod brisanja čvorova i sl.
    public $pocetniCvor; // ime
    private $id; // ovo mora biti jedinstveni ID ovog automata i ekvivalentnog mu regexa; treba biti 6 random generiranih hex znamenki koje već ne postoje
    // u bazi, što provjeravamo iz posebne ID tablice
    private static $brojStanja = 0;
    private $zavrsniCvorovi = [];

    public const EPSILON_PRIJELAZ = 1;
    public const SIGMA_PRIJELAZ = 2; // prijelaz za bilo koji znak abecede (.)

    public static function izJSON($json) { // očekuje JSON serijalizirani format ove klase; ideja je da klijentski JS kod serijalizira nacrtani graf s
        // canvasa u JSON i asinkrono pošalje ovoj metodi na serveru koja potom klijentu vraća odgovarajući JSON regexa tj. samo njegov text
        $obj = json_decode($json, true); // TODO: treba li tu posebno loviti bilo što loše što se može dogoditi ako nismo dobili važeći json ili
        // pustiti da se JS nosi sa statusnim kodom odgovora odnosno tipom odgovora iz kojeg se može deducirati greška?
        $nka = new NKA();
        $nka->id = $obj['id']; // BITNO: ako je id null, to znači da korisnik nije ulogiran i server mu nije dodijelio jedinstveni id automata, pa
        // sada neće biti moguće ni spremanje automata/regexa u bazu
        $nka->pocetniCvor = $obj['pocetniCvor'];
        foreach ($obj['cvorovi'] as $ime => $cvor) {
            $nka->dodajCvor($ime, Cvor::izJSON($cvor), $obj['listaSusjednosti'][$ime]);
        }

        return $nka;
    }

    private static function generirajImeCvora() {
        return '$q_{' . (self::$brojStanja++) . '}$';
    }

    public static function izRegexa($regex) {
        self::$brojStanja = 0;
        return self::izRegexaRekurzivno($regex);
    }

    private static function spojiGrafove($iz, $u) {
        if ($iz !== null) {
            $u->listaSusjednosti = array_merge($u->listaSusjednosti, $iz->listaSusjednosti);
            $u->cvorovi = array_merge($u->cvorovi, $iz->cvorovi);
            $u->zavrsniCvorovi = array_merge($u->zavrsniCvorovi, $iz->zavrsniCvorovi);
        }
    }

    private static function izRegexaRekurzivno($regex) { // FIXME: x,y relativno pozicioniranje ovdje je totalno broken
        if ($regex === null)
            return null;

        if ($regex->oznaka === Regex::P_UNIJA) {
            $pocetniCvor = new Cvor(false, 0, 0);
            $ime = self::generirajImeCvora();
            $nka = new NKA();
            $lijeviAutomat = self::izRegexaRekurzivno($regex->lijevo);
            $desniAutomat = self::izRegexaRekurzivno($regex->desno);
            $nka->dodajCvor($ime, $pocetniCvor, []);
            $nka->uciniPocetnim($ime);

            if ($lijeviAutomat !== null)
                $nka->dodajPrijelaze($ime, [[$lijeviAutomat->pocetniCvor, self::EPSILON_PRIJELAZ]]);
            if ($desniAutomat !== null)
                $nka->dodajPrijelaze($ime, [[$desniAutomat->pocetniCvor, self::EPSILON_PRIJELAZ]]);

            $zavrsniCvor = new Cvor(true, 0, 0);
            $ime = self::generirajImeCvora();
            $nka->dodajCvor($ime, $zavrsniCvor, []);
            if ($lijeviAutomat !== null) {
            foreach ($lijeviAutomat->zavrsniCvorovi as $lijeviZavrsni) {
                $lijeviAutomat->uciniNezavrsnim($lijeviZavrsni);
                $lijeviAutomat->dodajPrijelaze($lijeviZavrsni, [[$ime, self::EPSILON_PRIJELAZ]]);
            }
        }
            if ($desniAutomat !== null) {
                foreach ($desniAutomat->zavrsniCvorovi as $desniZavrsni) {
                    $desniAutomat->uciniNezavrsnim($desniZavrsni);
                    $desniAutomat->dodajPrijelaze($desniZavrsni, [[$ime, self::EPSILON_PRIJELAZ]]);
                }
            }

            self::spojiGrafove($lijeviAutomat, $nka);
            self::spojiGrafove($desniAutomat, $nka);

            return $nka;
        }

        // TODO ...
    }

    public function dodajCvor($stanje, $cvor, $prijelazi) {
        if (key_exists($cvor, $this->cvorovi))
            throw new LogicException('Ovo stanje već postoji u automatu!');
        $this->cvorovi[$stanje] = $cvor;
        $this->listaSusjednosti[$stanje] = $prijelazi;
        if ($cvor->zavrsno)
            $this->zavrsniCvorovi[$stanje] = 1;
    }

    public function dodajPrijelaze($stanje, $prijelazi) {
        foreach ($prijelazi as $prijelaz){
            $this->listaSusjednosti[$stanje][] = $prijelaz; // TODO: bacanje posebne greške ako dodajemo nepostojećeg susjeda?
        }
    }

    public function pomakniCvor($stanje, $noviX, $noviY) {
        $this->cvorovi[$stanje]->x = $noviX;
        $this->cvorovi[$stanje]->y = $noviY; //                  TODO: -||-
    }

    public function ukloniCvor($stanje) {
        unset($this->cvorovi[$stanje]);
        foreach ($this->listaSusjednosti as $ime => $cvorovi) {
            $idx = array_search($stanje, $cvorovi);
            if ($idx !== false) {
                unset($cvorovi[$idx]);
                $this->listaSusjednosti[$ime] = array_values($cvorovi);
            }
        }

        unset($this->listaSusjednosti[$stanje]);
    }

    // JS funkcije trebaju stavljati sve obavljene akcije u red po tipu: uklanjanje, dodavanje. Onda se jednostavno pri izlasku iz odgovarajućeg
    // "edit mode"-a šalje serveru jedna naredba koja objedinjuje sve obavljene akcije istog tipa i server onda obavlja niz ovih poziva
    public function ukloniPrijelaz($stanje, $prijelaz) {
        $idx = array_search($prijelaz, $this->listaSusjednosti[$stanje]);
        unset($this->listaSusjednosti[$stanje][$idx]);
        $this->listaSusjednosti[$stanje][$idx] = array_values($this->listaSusjednosti[$stanje][$idx]);
    }

    public function uciniZavrsnim($stanje) {
        $this->cvorovi[$stanje]->zavrsno = true;
        $this->zavrsniCvorovi[$stanje] = true;
    }

    public function uciniNezavrsnim($stanje) {
        $this->cvorovi[$stanje]->zavrsno = false;
        unset($this->zavrsniCvorovi[$stanje]);
    }

    public function iskljuciPocetno() {
        $this->pocetniCvor = null;
    }

    public function uciniPocetnim($stanje) {
        $this->pocetniCvor = $stanje;
    }
}
?>
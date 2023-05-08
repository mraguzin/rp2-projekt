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
        return '$q_{' . (NKA::$brojStanja++) . '}$';
    }

    private static function jeLiGrupa($tip) {
        return ($tip === Regex::GRUPA || $tip === Regex::GRUPA_PLUS || $tip === Regex::GRUPA_UPITNIK || $tip === Regex::GRUPA_ZVIJEZDA);
    }

    private static function kleene($inicijalnoStanje, $regex, $nka, $x0, $y0) {
        switch ($regex->tipGrupe) {
            case Regex::GRUPA_PLUS:
                $sljedeceStanje = new Cvor(false, $x0 + Cvor::MARGINA + Cvor::RADIJUS, $y0 + Cvor::MARGINA + Cvor::RADIJUS);
                [$nka, $x0, $y0, $prvoStanje, $zadnjeStanje] = NKA::izRegexa($regex, $nka, $inicijalnoStanje, $x0, $y0);
                $ime = NKA::generirajImeCvora();
                $nka->dodajCvor($ime, $sljedeceStanje, []);
                $nka->dodajPrijelaze($zadnjeStanje, [[$sljedeceStanje, NKA::EPSILON_PRIJELAZ]]);
                $inicijalnoStanje = $sljedeceStanje;
            case Regex::GRUPA_ZVIJEZDA: // namjerni fallthrough!
                [$nka, $x0, $y0, $prvoStanje, $zadnjeStanje] = NKA::izRegexa($regex, $nka, $inicijalnoStanje, $x0, $y0);
                $nka->dodajPrijelaze($prvoStanje, [[$zadnjeStanje, NKA::EPSILON_PRIJELAZ]]);
                $nka->dodajPrijelaze($zadnjeStanje, [[$prvoStanje, NKA::EPSILON_PRIJELAZ]]);
                return [$x0, $y0, $prvoStanje, $zadnjeStanje];
            case Regex::GRUPA_UPITNIK:
                [$nka, $x0, $y0, $prvoStanje, $zadnjeStanje] = NKA::izRegexa($regex, $nka, $inicijalnoStanje, $x0, $y0);
                $nka->dodajPrijelaze($prvoStanje, [[$zadnjeStanje, NKA::EPSILON_PRIJELAZ]]);
                return [$x0, $y0, $prvoStanje, $zadnjeStanje];
            case Regex::GRUPA:
                [$nka, $x0, $y0, $prvoStanje, $zadnjeStanje] = NKA::izRegexa($regex, $nka, $inicijalnoStanje, $x0, $y0);
                return [$x0, $y0, $prvoStanje, $zadnjeStanje];
        }
    }

    public static function izRegexa($regex, $nka=null, $inicijalnoStanje=null, $x=0, $y=0) { // FIXME: x,y relativno pozicioniranje ovdje je totalno broken
        if (!isset($nka)) {
            $inicijalniPoziv = true;
            NKA::$brojStanja = 0;
            $nka = new NKA();
            $prviCvor = new Cvor(false, $x += Cvor::MARGINA + Cvor::RADIJUS, $y += Cvor::MARGINA + Cvor::RADIJUS);
            $ime = NKA::generirajImeCvora();
            $nka->dodajCvor($ime, $prviCvor, []);
            $nka->uciniPocetnim($ime);
            $inicijalnoStanje = $ime;
        }

        if ($regex->korijen === Regex::ZNAK || $regex->korijen === Regex::SVI_ZNAKOVI) {
            $zavrsno = new Cvor(false, $x + 10, $y);
            $ime = NKA::generirajImeCvora();
            $nka->dodajCvor($ime, $zavrsno, []);
            if ($regex->korijen === Regex::ZNAK)
                $nka->dodajPrijelaze($inicijalnoStanje, [[$ime, $regex->znak]]);
            else
                $nka->dodajPrijelaze($inicijalnoStanje, [[$ime, NKA::SIGMA_PRIJELAZ]]); // pazi, ovdje posebno (int-om) označavamo da je riječ o proizvoljnom
                // znaku abecede, pa se frontend mora korektno nositi s detekcijom kada je tu string (tj. znak) a kada int

            return [$nka, $x + 10, $y, $inicijalnoStanje, $ime];
        }
        
        if ($regex->korijen === Regex::UNIJA) {
            $lijevo = new Cvor(false, $x + 10, $y + 15);
            $ime = NKA::generirajImeCvora();
            $nka->dodajCvor($ime, $lijevo, []);
            $inicijalnoStanje = $ime;
        }

        $prosloStanje = $inicijalnoStanje;
        $prosliX = $x; $prosliY = $y;
        foreach ($regex->lijevaDjeca as $lijevoDijete) {   
            if (($lijevoDijete->korijen === Regex::ZNAK || $lijevoDijete->korijen === Regex::SVI_ZNAKOVI) && $lijevoDijete->tipGrupe === Regex::NEMA_GRUPE)
                //$nka->dodajPrijelaze($prosloStanje, [[$ime, $lijevoDijete->znak]]);
                [$x, $y, $prvoStanje, $prosloStanje] = NKA::izRegexa($lijevoDijete, $nka, $prosloStanje, $x + 10, $y);
            else {// if (NKA::jeLiGrupa($lijevoDijete->tipGrupe)) {
                //$pocetakGrupe = new Cvor(false, $x + 10, $y);
                [$x, $y, $prvoStanje, $prosloStanje] = NKA::kleene($prosloStanje, $lijevoDijete, $nka, $x + 10, $y);
                if ($prosloStanje !== $prvoStanje)
                    $nka->dodajPrijelaze($prosloStanje, [[$prvoStanje, NKA::EPSILON_PRIJELAZ]]); // TODO: minimizacija: moguće je eliminirati ovo stanje
                // jer prosloStanje može obaviti sve te iste prijelaze, samo premjesti i izbriši
            }
        }

        $x = $prosliX; $y = $prosliY;
        if ($regex->korijen === Regex::UNIJA) {
            $imePocetka = NKA::generirajImeCvora();
            $imeKraja = NKA::generirajImeCvora();
            $prosliX = $x + 10; $prosliY = $y + 10;
            $krajUnije = new Cvor(false, $prosliX, $prosliY); // sredi lijevi dio unije, da epsilon-prelazi na kraj unije; zatim isto za desni dio
            $pocetakUnije = new Cvor(false, $x + 10, $y - 15);
            $nka->dodajCvor($imePocetka, $pocetakUnije, []);
            $nka->dodajCvor($imeKraja, $krajUnije, []);
            $nka->dodajPrijelaze($imePocetka, [[$inicijalnoStanje, NKA::EPSILON_PRIJELAZ]]);
            $nka->dodajPrijelaze($prosloStanje, [[$imeKraja, NKA::EPSILON_PRIJELAZ]]);
            $desno = new Cvor(false, $x + 10, $y - 15); // TODO: na kraju treba centrirati crtež jer neki elementi mogu ispasti van granica; frontend
            // također može skalirati sve ako ne stane u neku zadanu površinu, a mi ovdje još moramo riješiti sudaranje čvorova...
            $ime = NKA::generirajImeCvora();
            $nka->dodajCvor($ime, $desno, []);
            //$nka->dodajPrijelaze($imePocetka, [[$ime, 'ε']]);
            [$nka, $x, $y, $prvoStanje, $zadnjeStanje] = NKA::izRegexa($regex->desnoDijete, $nka, $ime, $prosliX + 10, $prosliY - 15); // rekurzija udesno
            $nka->dodajPrijelaze($imePocetka, [[$prvoStanje, NKA::EPSILON_PRIJELAZ]]);
            $nka->dodajPrijelaze($zadnjeStanje, [[$imeKraja, NKA::EPSILON_PRIJELAZ]]);

            $x = $prosliX; $y = $prosliY;
            $prosloStanje = $imeKraja;
            $inicijalnoStanje = $imePocetka;
        }

        if ($inicijalniPoziv)
            $nka->uciniZavrsnim($prosloStanje);

        return [$nka, $x, $y, $inicijalnoStanje, $prosloStanje];
    }

    public function dodajCvor($stanje, $cvor, $prijelazi) {
        if (key_exists($cvor, $this->cvorovi))
            throw new LogicException('Ovo stanje već postoji u automatu!');
        $this->cvorovi[$stanje] = $cvor;
        $this->listaSusjednosti[$stanje] = $prijelazi;
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
    }

    public function uciniNezavrsnim($stanje) {
        $this->cvorovi[$stanje]->zavrsno = false;
    }

    public function iskljuciPocetno() {
        $this->pocetniCvor = null;
    }

    public function uciniPocetnim($stanje) {
        $this->pocetniCvor = $stanje;
    }
}
?>
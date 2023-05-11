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
        $this->x = self::clamp($x, self::RADIJUS);
        //$this->y = self::clamp($y, self::RADIJUS);
    }

    private static function clamp($val, $min) {
        if ($val < $min)
            return $min;
        else
            return $val;
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
    private static $trenutniX = 0, $trenutniY = 0;

    public const EPSILON_PRIJELAZ = 1;

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
        self::$maxX = self::$minY = 0;
        return self::izRegexaRekurzivno($regex);
    }

    private static $unija;
    private static function brojUnija($stablo) {
        // Prebroji koliko ukupno ima unija u ovom (pod)stablu. Korisno za određivanje potrebnog vertikalnog odmaka podautomata u konstrukciji unije.
        self::$unija = 0;
        self::_brojUnija($stablo);
        return self::$unija;
    }

    private static function _brojUnija($stablo) {
        if ($stablo === null)
            return;

        switch ($stablo->oznaka) {
            case Regex::P_UNIJA:
                ++self::$unija; // namjerni fallthrough!
            case Regex::P_GRUPA:
            case Regex::P_GRUPA_PLUS:
            case Regex::P_GRUPA_UPITNIK:
            case Regex::P_GRUPA_ZVIJEZDA:
                self::_brojUnija($stablo->lijevo);
                self::_brojUnija($stablo->desno);
                break;

            case Regex::P_NIZ_GRUPA:
                self::_brojUnija($stablo->desno);
                break;
        }
    }

    private static function spojiGrafove($iz, $u) {
        if ($iz !== null) {
            $u->listaSusjednosti = array_merge($u->listaSusjednosti, $iz->listaSusjednosti);
            $u->cvorovi = array_merge($u->cvorovi, $iz->cvorovi);
            $u->zavrsniCvorovi = array_merge($u->zavrsniCvorovi, $iz->zavrsniCvorovi);
        }
    }

    private static function izNizaZnakova($regex) {
        $niz = $regex->ispod;
        $nka = new NKA();
        $ime = self::generirajImeCvora();
        $prosloStanje = [$ime, new Cvor(true, self::$trenutniX, self::$trenutniY)];
        $nka->dodajCvor($prosloStanje[0], $prosloStanje[1], []);
        $nka->uciniPocetnim($ime);
        foreach ($niz as $znak) {
            $nka->uciniNezavrsnim($prosloStanje[0]);
            $novoStanje = [self::generirajImeCvora(), new Cvor(true, self::$trenutniX, self::$trenutniY)];
            $nka->dodajCvor($novoStanje[0], $novoStanje[1], []);
            $nka->dodajPrijelaze($prosloStanje[0], [[$novoStanje[0], $znak]]);
        }

        return $nka;
    }

    private static function konkatenirajAutomate($prvi, $drugi) {
        $nka = new NKA();

        if ($prvi !== null) {
            $nka->uciniPocetnim($prvi->pocetniCvor);
            if ($drugi !== null) {
                foreach ($prvi->zavrsniCvorovi as $prviZavrsni) {
                    $prvi->uciniNezavrsnim($prviZavrsni);
                    $prvi->dodajPrijelaze($prviZavrsni, [[$drugi->pocetniCvor, self::EPSILON_PRIJELAZ]]);
                }
            }
        } else if ($drugi !== null) {
            $nka->uciniPocetnim($drugi->pocetniCvor);
        }

        self::spojiGrafove($prvi, $nka);
        self::spojiGrafove($drugi, $nka);

        return $nka;
    }

    private static function izRegexaRekurzivno($regex, $forsirajOznaku = null) { // FIXME: x,y relativno pozicioniranje ovdje je totalno broken
        if ($regex === null)
            return null;

        if ($forsirajOznaku !== null)
            $regex->oznaka = $forsirajOznaku;

        if ($regex->oznaka === Regex::P_UNIJA) {
            $pocetniCvor = new Cvor(false, self::$trenutniX, self::$trenutniY);
            $ime = self::generirajImeCvora();
            $nka = new NKA();
            $nka->dodajCvor($ime, $pocetniCvor, []);
            $nka->uciniPocetnim($ime);

            $unijaLijevo = self::brojUnija($regex->lijevo);
            $gornjiY = self::$trenutniY + Cvor::RADIJUS*2 + (Cvor::MARGINA) * $unijaLijevo;
            $unijaDesno = self::brojUnija($regex->desno);
            $donjiY = self::$trenutniY - Cvor::RADIJUS*2 - (Cvor::MARGINA) * $unijaDesno;
            
            $stariX = self::$trenutniX;
            $stariY = self::$trenutniY;
            self::$trenutniY = $gornjiY;
            $lijeviAutomat = self::izRegexaRekurzivno($regex->lijevo);
            self::$trenutniX = $stariX;
            self::$trenutniY = $donjiY;
            $desniAutomat = self::izRegexaRekurzivno($regex->desno);
            $maxX = max($lijeviAutomat->maxX, $desniAutomat->maxX);
            self::$trenutniX = $maxX;
            self::$trenutniY = $stariY;

            if ($lijeviAutomat !== null)
                $nka->dodajPrijelaze($ime, [[$lijeviAutomat->pocetniCvor, self::EPSILON_PRIJELAZ]]);
            if ($desniAutomat !== null)
                $nka->dodajPrijelaze($ime, [[$desniAutomat->pocetniCvor, self::EPSILON_PRIJELAZ]]);

            $zavrsniCvor = new Cvor(true, self::$trenutniX, self::$trenutniY);
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
        } else if ($regex->oznaka === Regex::P_GRUPA) {
            $lijeviAutomat = self::izRegexaRekurzivno($regex->lijevo);
            $desniAutomat = self::izRegexaRekurzivno($regex->desno);
            
            return self::konkatenirajAutomate($lijeviAutomat, $desniAutomat);
        } else if ($regex->oznaka === Regex::P_GRUPA_ZVIJEZDA) {
            $lijeviAutomat = self::izRegexaRekurzivno($regex->lijevo);
            $desniAutomat = self::izRegexaRekurzivno($regex->desno);
            if ($lijeviAutomat === null) {
                return $desniAutomat;
            }

            $dodatniCvor = new Cvor(true, self::$trenutniX, self::$trenutniY);
            $ime = self::generirajImeCvora();

            foreach ($lijeviAutomat->zavrsniCvorovi as $lijeviZavrsni) {
                $lijeviAutomat->dodajPrijelaze($lijeviZavrsni, [[$ime, self::EPSILON_PRIJELAZ]]);
                $lijeviAutomat->uciniNezavrsnim($lijeviZavrsni);
            }

            $lijeviAutomat->dodajCvor($ime, $dodatniCvor, [[$lijeviAutomat->pocetniCvor, self::EPSILON_PRIJELAZ]]);
            $lijeviAutomat->dodajPrijelaze($lijeviAutomat->pocetniCvor, [[$ime, self::EPSILON_PRIJELAZ]]);

            return self::konkatenirajAutomate($lijeviAutomat, $desniAutomat);
        } else if ($regex->oznaka === Regex::P_GRUPA_UPITNIK) {
            $lijeviAutomat = self::izRegexaRekurzivno($regex->lijevo);
            $desniAutomat = self::izRegexaRekurzivno($regex->desno);
            if ($lijeviAutomat === null) {
                return $desniAutomat;
            }

            $dodatniCvor = new Cvor(true, self::$trenutniX, self::$trenutniY);
            $ime = self::generirajImeCvora();
            $lijeviAutomat->dodajPrijelaze($lijeviAutomat->pocetniCvor, [[$ime, self::EPSILON_PRIJELAZ]]);

            foreach ($lijeviAutomat->zavrsniCvorovi as $lijeviZavrsni) {
                $lijeviAutomat->dodajPrijelaze($lijeviZavrsni, [[$ime, self::EPSILON_PRIJELAZ]]);
            }

            return self::konkatenirajAutomate($lijeviAutomat, $desniAutomat);
        } else if ($regex->oznaka === Regex::P_GRUPA_PLUS) {
            $lijeviAutomat = self::izRegexaRekurzivno($regex->lijevo);
            $desniAutomat = self::izRegexaRekurzivno($regex, Regex::P_GRUPA_ZVIJEZDA);
            if ($lijeviAutomat === null) {
                return $desniAutomat;
            }

            return self::konkatenirajAutomate($lijeviAutomat, $desniAutomat);
        } else if ($regex->oznaka === Regex::P_NIZ) {
            return self::izNizaZnakova($regex);
        } else if ($regex->oznaka === Regex::P_NIZ_GRUPA) {
            $lijeviAutomat = self::izNizaZnakova($regex->lijevo);
            $desniAutomat = self::izRegexaRekurzivno($regex->desno);

            return self::konkatenirajAutomate($lijeviAutomat, $desniAutomat);
        } else {
            throw new RuntimeException('Neočekivani token: ' . $regex->oznaka);
        }
    }

    private static $minY = 100000;
    private static $maxX = -1;

    public function dodajCvor($stanje, $cvor, $prijelazi) {
        if (key_exists($cvor, $this->cvorovi))
            throw new LogicException('Ovo stanje već postoji u automatu!');
        $this->cvorovi[$stanje] = $cvor;
        self::$trenutniX += Cvor::RADIJUS*2 + Cvor::MARGINA;
        if (self::$trenutniY < self::$minY)
            self::$minY = self::$trenutniY;
        if (self::$trenutniX > self::$maxX)
            self::$maxX = self::$trenutniX;

        $this->listaSusjednosti[$stanje] = $prijelazi;
        if ($cvor->zavrsno)
            $this->zavrsniCvorovi[$stanje] = 1;
    }

    public function dodajPrijelaze($stanje, $prijelazi) {
        foreach ($prijelazi as $prijelaz){
            $this->listaSusjednosti[$stanje][] = $prijelaz;
        }
    }

    public function pomakniCvor($stanje, $noviX, $noviY) {
        $this->cvorovi[$stanje]->x = $noviX;
        $this->cvorovi[$stanje]->y = $noviY;
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
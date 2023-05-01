<?php
class Regex {
    private $korijen = Regex::KONKATENACIJA;
    public $znak;
    public $lijevaDjeca = [];
    public $desnoDijete;
    public $tipGrupe = Regex::NEMA_GRUPE; // ovo označava je li trenutni (pod)regex jedan dio većeg grupnog izraza
    // (tj. onog koji je u zagradama) i ako jest, je li potrebno
    // tražiti posljednjeg člana grupe kako bismo saznali je li grupa pod zvijezdom, upitnikom itd.
    //private $djeca = [];
    private static $pomak = 0;
    private $ulaz; // ulazni regex string

    public const ZNAK          = 0; // znak abecede

    public const GRUPA          = 4; // čisto unutar zagrada (donje tri varijacije znače da je grupa pod tim znakom)
    public const GRUPA_ZVIJEZDA = 5;
    public const GRUPA_PLUS     = 6;
    public const GRUPA_UPITNIK  = 7;
    public const NEMA_GRUPE     = 8;
    public const GRUPA_POCETNI  = 9; // sve što nije jedan od gornjih završnih klasifikatora grupe, dakle još ne znamo i moramo dalje ići na idući regex dio

    public const UNIJA         = 10;
    public const SVI_ZNAKOVI   = 11;
    public const KONKATENACIJA = 12;

    private function __construct($ulaz, $uZagradi=false)
    {
        $this->ulaz = $ulaz;
        if (count($ulaz) !== 0)
            $this->parsiraj($uZagradi);
    }

    public static function izTeksta($tekst) {
        Regex::$pomak = 0;
        return new Regex(mb_str_split($tekst));
    }

    public static function kaoZnak($tip, $znak) {
        $regex = new Regex([]);
        $regex->korijen = $tip;
        $regex->znak = $znak;

        return $regex;
    }

    private function dajZnak($peek=false) {
        if ($this->pomak === count($this->ulaz))
            return -1;
        else {
            if ($peek)
                return $this->ulaz[$this->pomak];
            else
                return $this->ulaz[$this->pomak++];
        }
    }

    private function odrediTipGrupe()
    {
        switch ($this->dajZnak()) {
            case '*':
                $this->tipGrupe = Regex::GRUPA_ZVIJEZDA;
                break;

            case '+':
                $this->tipGrupe = Regex::GRUPA_PLUS;
                break;

            case '?':
                $this->tipGrupe = Regex::GRUPA_UPITNIK;
                break;

            default:
            $this->pomak--;
            if ($this->korijen == Regex::ZNAK)
                $this->tipGrupe = Regex::NEMA_GRUPE;
            else
                $this->tipGrupe = Regex::GRUPA;
        }
    }

    private function parsiraj($uZagradi = false)
    {
        if ($uZagradi)
            $this->tipGrupe = Regex::GRUPA_POCETNI;

        while (true) {
            $znak = $this->dajZnak();

            if ($znak === '(') {
                $this->lijevaDjeca[] = new Regex($this->ulaz, true);
            } else if ($znak === ')') {
                if (!$uZagradi)
                    throw new DomainException('Uneseni tekst nije važeći regularan izraz jer zatvarate neotvorenu zagradu!');

                $this->odrediTipGrupe();  //TODO: optimizacija kod zatvaranja grupe: jednostavno promijeni samo početni tip grupe i neka on označava CIJELI BLOK!
                return;
            } else if ($znak === '|') {
                $this->korijen = Regex::UNIJA;
                $this->desnoDijete = new Regex($this->ulaz, $this->pomak, $uZagradi);
            } else if ($znak === '*' || $znak === '+' || $znak === '?') { // beskorisno, ali dopustivo
                continue;
            } else if ($znak === -1) {
                if ($uZagradi)
                    throw new DomainException('Kraj teksta bez zatvaranja otvorene zagrade!');

                return;
            } else {
                if ($znak === '.')
                    $dijete = Regex::kaoZnak(Regex::SVI_ZNAKOVI, '.');
                else if ($znak === '\\') {
                    $sljedeci = $this->dajZnak();
                    if ($sljedeci === -1)
                        throw new DomainException('Kraj teksta prije specifikacije doslovnog znaka (nakon \\)!');
                    $dijete = Regex::kaoZnak(Regex::ZNAK, $sljedeci);
                } else
                    $dijete = Regex::kaoZnak(Regex::ZNAK, $znak);

                $dijete->odrediTipGrupe();
            }
        }
    }
}
?>
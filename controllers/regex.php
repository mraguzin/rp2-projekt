<?php
class Regex {
    private $korijen = Regex::IZRAZ;
    public $lijevaDjeca = [];
    public $desnoDijete;
    //private $djeca = [];
    private static $pomak = 0;
    private $ulaz; // ulazni regex string

    public const ZNAK          = 0; // znak abecede
    public const ZNAK_ZVIJEZDA = 1;
    public const ZNAK_PLUS     = 2;
    public const ZNAK_UPITNIK  = 3;

    public const GRUPA          = 4; // unutar zagrada
    public const GRUPA_ZVIJEZDA = 5;
    public const GRUPA_PLUS     = 6;
    public const GRUPA_UPITNIK  = 7;

    public const UNIJA       = 8;
    public const SVI_ZNAKOVI = 9;

    public const IZRAZ = 10;

    private function __construct($ulaz, $pomak, $uZagradi=false)
    {
        $this->pomak = $pomak;
        //$this->ulaz = mb_str_split($tekst);
        $this->ulaz = $ulaz;
        $this->parsiraj($uZagradi);
    }

    public static function izTeksta($tekst) {
        Regex::$pomak = 0;
        return new Regex(mb_str_split($tekst), 0);
    }

    private function dajZnak($peek=false) {
        // pojedi bjeline, ne znače nam ništa
        while ($this->pomak < count($this->ulaz) &&
                IntlChar::isspace($this->ulaz[$this->pomak++]));
                 // FIXME: možda će trebati ovo zamijeniti nečim jednostavnijim ako rp2 nema konfiguriranu ovu ekstenziju
        if ($this->pomak === count($this->ulaz))
            return -1;
        else {
            $this->pomak--;
            return $this->ulaz[$this->pomak];
            if (!$peek)
                $this->pomak++;
        }
    }

    private function parsiraj($uZagradi=false) {
        $znak = $this->dajZnak();

        if ($znak === '(') {
            $this->lijevaDjeca[] = new Regex($this->ulaz, $this->pomak, true);
        } else if ($znak === ')') {
            if (!$uZagradi)
                throw new DomainException('Uneseni tekst nije važeći regularan izraz jer zatvarate neotvorenu zagradu!');
            $sljedeci = $this->dajZnak();
            if ($sljedeci === '*')
                $this->korijen = Regex::GRUPA_ZVIJEZDA;
            else if ($sljedeci === '+')
                $this->korijen = Regex::GRUPA_PLUS;
            else if ($sljedeci === '?')
                $this->korijen = Regex::GRUPA_UPITNIK;
            else {
                $this->pomak--;
                $this->korijen = Regex::GRUPA;
            }

            return;
        } else if ($znak === '|') {
            $this->korijen = Regex::UNIJA;
            $this->desnoDijete = new Regex($this->ulaz, $this->pomak, $uZagradi);
        } else if ($znak === '*' || $znak === '+' || $znak === '?') { // beskorisno, ali dopustivo
            continue;
        } else if ($znak === -1) {
            if ($uZagradi)
                throw new DomainException('Kraj teksta bez zatvaranja otvorene zagrade!');
        } else {
            if ($znak === '.')
                $this->korijen = Regex::SVI_ZNAKOVI;
            else
                $this->korijen = $znak;
            
            a
        }
    }
}
?>
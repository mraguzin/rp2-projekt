<?php
class Regex {
    public $oznaka;
    public $lijevo, $desno;

    private $id; // jedinstveni ID ovog regexa i ekvivalentnog mu automata

    public function __get($name)
    {
        if ($name === 'ispod')
            return $this->lijevo;
        else
            return $this->$name;
    }

    public const T_UNIJA          = 0;
    public const T_OTV            = 1;
    public const T_ZAT            = 2;
    public const T_OTV_PLUS       = 3;
    public const T_ZAT_PLUS       = 4;
    public const T_OTV_ZVIJEZDA   = 5;
    public const T_ZAT_ZVIJEZDA   = 6;
    public const T_OTV_UPITNIK    = 7;
    public const T_ZAT_UPITNIK    = 8;
    public const T_NIZ            = 9; // niz znakova (bar 1)
    public const T_ZNAK_PLUS      = 10;
    public const T_ZNAK_ZVIJEZDA  = 11;
    public const T_ZNAK_UPITNIK   = 12;
    public const EPSILON          = 13;
    public const SVI_ZNAKOVI      = 14;

    public const P_UNIJA                = 0;
    public const P_GRUPA                = 1;
    public const P_GRUPA_PLUS           = 2;
    public const P_GRUPA_ZVIJEZDA       = 3;
    public const P_GRUPA_UPITNIK        = 4;
    public const P_NIZ                  = 5;
    public const P_PREFIX_ZNAK_PLUS     = 6; // lijevo dijete ima oznaku koja je jednaka paru vrijednosti [T,Z] za TZ*/TZ+/TZ?
    public const P_PREFIX_ZNAK_ZVIJEZDA = 7;
    public const P_PREFIX_ZNAK_UPITNIK  = 8;
    public const P_ZNAK_PLUS            = 9; // kao gore, samo što sada nema T ispred tj. lijevo dijete je samo jedan znak
    public const P_ZNAK_ZVIJEZDA        = 10;
    public const P_ZNAK_UPITNIK         = 11;
    public const P_NIZ_GRUPA            = 12; // T(S1)S1 itd. sve varijante zagrada—ovo je nužno radi mogućnosti nastavljanja niza udesno zagradom!
    public const P_NIZ_GRUPA_PLUS       = 13;
    public const P_NIZ_GRUPA_ZVIJEZDA   = 14;
    public const P_NIZ_GRUPA_UPITNIK    = 15;

    public static function fromString($regex) {
        $ulaz = mb_str_split($regex);
        $tokeni = self::lex($ulaz);
        return self::parsiraj($tokeni);
    }

    private static function noviToken($tip, $vrijednost = null) {
        return [$tip, $vrijednost];
    }

    private static function tipTokena($token) {
        return $token[0];
    }

    private static function vrijednostTokena($token) {
        return $token[1];
    }

    private static function lex($ulaz) {
        $i = 0;
        $tokeni = [];
        $stog = new SplStack();
        $ulaz[] = self::EPSILON;
        $trenutniNiz = [];

        for ($j = 0; $j < count($ulaz); ++$j) {
            $znak = $ulaz[$j];
            if (array_search($znak, explode(' ', '( ) * + ? |')) !== false && !empty($trenutniNiz)) {
                $tokeni[] = self::noviToken(self::T_NIZ, $trenutniNiz);
                $trenutniNiz = [];
                ++$i;
            }

            switch ($znak) {
                case '(':
                    $stog->push($i++);
                    $tokeni[] = self::noviToken(self::T_OTV);
                    break;

                case ')':
                    $otvarajuca = $stog->pop();
                    $sljedeci = $ulaz[$j + 1];
                    if ($sljedeci === '*') {
                        ++$j;
                        $tokeni[$otvarajuca] = self::noviToken(self::T_OTV_ZVIJEZDA);
                        $tokeni[] = self::noviToken(self::T_ZAT_ZVIJEZDA);
                    } else if ($sljedeci === '+') {
                        ++$j;
                        $tokeni[$otvarajuca] = self::noviToken(self::T_OTV_PLUS);
                        $tokeni[] = self::noviToken(self::T_ZAT_PLUS);
                    } else if ($sljedeci === '?') {
                        ++$j;
                        $tokeni[$otvarajuca] = self::noviToken(self::T_OTV_UPITNIK);
                        $tokeni[] = self::noviToken(self::T_ZAT_UPITNIK);
                    } else {
                        $tokeni[] = self::noviToken(self::T_ZAT);
                    }

                    ++$i;
                    break;

                case '|':
                    $tokeni[] = self::noviToken(self::T_UNIJA);
                    ++$i;
                    break;

                case self::EPSILON:
                    break;

                case '*':
                case '+':
                case '?':
                    throw new RuntimeException("Ilegalno pojavljivanje $znak u regexu");
                    break;

                default:
                if ($znak === '.')
                    $vrijednost = self::SVI_ZNAKOVI;
                else if ($znak === '\\')
                    $vrijednost = $ulaz[++$j];
                else
                    $vrijednost = $znak;
                
                if ($vrijednost === self::EPSILON)
                    throw new RuntimeException('Neočekivan kraj niza nakon \\');

                $sljedeci = $ulaz[$j + 1];
                if ($sljedeci === '*' || $sljedeci === '+' || $sljedeci === '?') {
                    if (!empty($trenutniNiz)) {
                        $tokeni[] = self::noviToken(self::T_NIZ, $trenutniNiz);
                        $trenutniNiz = [];
                        ++$i;
                    }
                }

                if ($sljedeci === '*') {
                    $tokeni[] = self::noviToken(self::T_ZNAK_ZVIJEZDA, $vrijednost);
                    ++$i;
                } else if ($sljedeci === '+') {
                    $tokeni[] = self::noviToken(self::T_ZNAK_PLUS, $vrijednost);
                    ++$i;
                } else if ($sljedeci === '?') {
                    $tokeni[] = self::noviToken(self::T_ZNAK_UPITNIK, $vrijednost);
                    ++$i;
                } else {
                    $trenutniNiz[] = $vrijednost;
                }               

            }
        }

        if (!empty($trenutniNiz))
            $tokeni[] = self::noviToken(self::T_NIZ, $trenutniNiz);
        $tokeni[] = self::noviToken(self::EPSILON);
        
        return $tokeni;
    }

    private static function ocekuj(&$tokeni, $tip, $nuzno = false) {
        if (!is_array($tip)) {
            $i = 1;
            $test = (self::tipTokena($tokeni[0]) === $tip);
        }
        else {
            $test = true;
            $i = 0;
            foreach ($tip as $t) {
                $test = $test && (self::tipTokena($tokeni[$i++]) === $t);
            }
        }

        if ($nuzno) {
            if (!$test)
                throw new RuntimeException('Očekivan token tipa ' . $tip);
            $tokeni = array_slice($tokeni, $i);
        }
            
        return $test;
    }

    public static function parsiraj($tokeni) {
        if (empty($tokeni))
            throw new RuntimeException('Nemam što parsirati');

        $stablo = self::S($tokeni);
        if (empty($tokeni) || $tokeni[0] === self::EPSILON)
            return $stablo;
        else
            throw new RuntimeException('Ulaz nije važeći regex');
    }

    private static function novoStablo($oznaka, $lijevo, $desno) {
        $stablo = new Regex();
        $stablo->oznaka = $oznaka;
        $stablo->lijevo = $lijevo;
        $stablo->desno = $desno;

        return $stablo;
    }

    private static function S(&$tokeni) {
        $stablo1 = self::S1($tokeni);
        if (self::ocekuj($tokeni, self::T_UNIJA)) {
            $tokeni = array_slice($tokeni, 1);
            $stablo2 = self::S($tokeni);
            
            return self::novoStablo(self::P_UNIJA, $stablo1, $stablo2);
        } else {
            return $stablo1;
        }
    }

    private static function S1(&$tokeni) {
        if (self::ocekuj($tokeni, self::T_OTV)) {
            $tokeni = array_slice($tokeni, 1);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT, true);
            $stablo2 = self::S1($tokeni);
            
            return self::novoStablo(self::P_GRUPA, $stablo1, $stablo2);
        } else if (self::ocekuj($tokeni, self::T_OTV_ZVIJEZDA)) {
            $tokeni = array_slice($tokeni, 1);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT_ZVIJEZDA, true);
            $stablo2 = self::S1($tokeni);
            
            return self::novoStablo(self::P_GRUPA_ZVIJEZDA, $stablo1, $stablo2);
        } else if (self::ocekuj($tokeni, self::T_OTV_PLUS)) {
            $tokeni = array_slice($tokeni, 1);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT_PLUS, true);
            $stablo2 = self::S1($tokeni);
            
            return self::novoStablo(self::P_GRUPA_PLUS, $stablo1, $stablo2);
        } else if (self::ocekuj($tokeni, self::T_OTV_UPITNIK)) {
            $tokeni = array_slice($tokeni, 1);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT_UPITNIK, true);
            $stablo2 = self::S1($tokeni);
            
            return self::novoStablo(self::P_GRUPA_UPITNIK, $stablo1, $stablo2);
        } else if (self::ocekuj($tokeni, self::T_ZNAK_ZVIJEZDA)) {
            $znak = self::vrijednostTokena($tokeni[0]);
            $tokeni = array_slice($tokeni, 1);
            $podstablo = self::S1($tokeni);

            return self::novoStablo(self::P_ZNAK_ZVIJEZDA, $znak, $podstablo);
        } else if (self::ocekuj($tokeni, self::T_ZNAK_PLUS)) {
            $znak = self::vrijednostTokena($tokeni[0]);
            $tokeni = array_slice($tokeni, 1);
            $podstablo = self::S1($tokeni);

            return self::novoStablo(self::P_ZNAK_PLUS, $znak, $podstablo);
        } else if (self::ocekuj($tokeni, self::T_ZNAK_UPITNIK)) {
            $znak = self::vrijednostTokena($tokeni[0]);
            $tokeni = array_slice($tokeni, 1);
            $podstablo = self::S1($tokeni);

            return self::novoStablo(self::P_ZNAK_UPITNIK, $znak, $podstablo);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_ZNAK_ZVIJEZDA])) {
            $niz = self::vrijednostTokena($tokeni[0]);
            $znak = self::vrijednostTokena($tokeni[1]);
            $tokeni = array_slice($tokeni, 2);
            $podstablo = self::S1($tokeni);

            return self::novoStablo(self::P_PREFIX_ZNAK_ZVIJEZDA, [$niz, $znak], $podstablo);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_ZNAK_PLUS])) {
            $niz = self::vrijednostTokena($tokeni[0]);
            $znak = self::vrijednostTokena($tokeni[1]);
            $tokeni = array_slice($tokeni, 2);
            $podstablo = self::S1($tokeni);

            return self::novoStablo(self::P_PREFIX_ZNAK_PLUS, [$niz, $znak], $podstablo);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_ZNAK_UPITNIK])) {
            $niz = self::vrijednostTokena($tokeni[0]);
            $znak = self::vrijednostTokena($tokeni[1]);
            $tokeni = array_slice($tokeni, 2);
            $podstablo = self::S1($tokeni);

            return self::novoStablo(self::P_PREFIX_ZNAK_UPITNIK, [$niz, $znak], $podstablo);

        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_OTV])) {
            $niz = self::vrijednostTokena($tokeni[0]);
            $tokeni = array_slice($tokeni, 2);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT, true);
            $stablo2 = self::S1($tokeni);

            return self::novoStablo(self::P_NIZ_GRUPA, [$niz, $stablo1], $stablo2);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_OTV_ZVIJEZDA])) {
            $niz = self::vrijednostTokena($tokeni[0]);
            $tokeni = array_slice($tokeni, 2);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT_ZVIJEZDA, true);
            $stablo2 = self::S1($tokeni);

            return self::novoStablo(self::P_NIZ_GRUPA_ZVIJEZDA, [$niz, $stablo1], $stablo2);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_OTV_PLUS])) {
            $niz = self::vrijednostTokena($tokeni[0]);
            $tokeni = array_slice($tokeni, 2);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT_PLUS, true);
            $stablo2 = self::S1($tokeni);

            return self::novoStablo(self::P_NIZ_GRUPA_PLUS, [$niz, $stablo1], $stablo2);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_OTV_UPITNIK])) {
            $niz = self::vrijednostTokena($tokeni[0]);
            $tokeni = array_slice($tokeni, 2);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT_UPITNIK, true);
            $stablo2 = self::S1($tokeni);

            return self::novoStablo(self::P_NIZ_GRUPA_UPITNIK, [$niz, $stablo1], $stablo2);
        } else if (self::ocekuj($tokeni, self::T_NIZ)) { //TODO: možemo imati granu za epsilon i uniju kao validne za return null, ali ostali onda
            // mogu prijaviti informativniju grešku o parsiranju oko toga što ne valja
            $tokeni = array_slice($tokeni, 1);

            return self::novoStablo(self::P_NIZ, self::vrijednostTokena($tokeni[0]), null);
        }

        return null;
    }
}
?>
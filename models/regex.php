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

    private const T_UNIJA          = 0;
    private const T_OTV            = 1;
    private const T_ZAT            = 2;
    private const T_OTV_PLUS       = 3;
    private const T_ZAT_PLUS       = 4;
    private const T_OTV_ZVIJEZDA   = 5;
    private const T_ZAT_ZVIJEZDA   = 6;
    private const T_OTV_UPITNIK    = 7;
    private const T_ZAT_UPITNIK    = 8;
    private const T_NIZ            = 9; // niz znakova T (bar 1)
    private const T_ZNAK_PLUS      = 10;
    private const T_ZNAK_ZVIJEZDA  = 11;
    private const T_ZNAK_UPITNIK   = 12;
    private const EPSILON          = 13;
    private const SVI_ZNAKOVI      = 14;

    public const P_UNIJA                = 0;
    public const P_GRUPA                = 1;
    public const P_GRUPA_PLUS           = 2;
    public const P_GRUPA_ZVIJEZDA       = 3;
    public const P_GRUPA_UPITNIK        = 4;
    public const P_NIZ                  = 5;
    public const P_NIZ_GRUPA            = 12; // T(S1)S1 itd. sve varijante zagrada—ovo je nužno radi mogućnosti nastavljanja niza udesno zagradom!

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

            return self::novoStablo(self::P_GRUPA_ZVIJEZDA, self::novoStablo(self::T_NIZ, [$znak], null), $podstablo);
        } else if (self::ocekuj($tokeni, self::T_ZNAK_PLUS)) {
            $znak = self::vrijednostTokena($tokeni[0]);
            $tokeni = array_slice($tokeni, 1);
            $podstablo = self::S1($tokeni);

            return self::novoStablo(self::P_GRUPA_PLUS, self::novoStablo(self::T_NIZ, [$znak], null), $podstablo);
        } else if (self::ocekuj($tokeni, self::T_ZNAK_UPITNIK)) {
            $znak = self::vrijednostTokena($tokeni[0]);
            $tokeni = array_slice($tokeni, 1);
            $podstablo = self::S1($tokeni);

            return self::novoStablo(self::P_GRUPA_UPITNIK, self::novoStablo(self::T_NIZ, [$znak], null), $podstablo);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_ZNAK_ZVIJEZDA])) {
            $niz = self::vrijednostTokena($tokeni[0]);
            $znak = self::vrijednostTokena($tokeni[1]);
            $tokeni = array_slice($tokeni, 2);
            $podstablo = self::S1($tokeni);
            $stablo = self::novoStablo(self::P_GRUPA_ZVIJEZDA, self::novoStablo(self::T_NIZ, [$znak], null), $podstablo);

            return self::novoStablo(self::P_NIZ_GRUPA, $niz, $stablo);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_ZNAK_PLUS])) {
            $stabloNiza = self::novoStablo(self::P_NIZ, self::vrijednostTokena($tokeni[0]), null);
            $znak = self::vrijednostTokena($tokeni[1]);
            $tokeni = array_slice($tokeni, 2);
            $podstablo = self::S1($tokeni);
            $stablo = self::novoStablo(self::P_GRUPA_PLUS, self::novoStablo(self::T_NIZ, [$znak], null), $podstablo);

            return self::novoStablo(self::P_NIZ_GRUPA, $stabloNiza, $stablo);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_ZNAK_UPITNIK])) {
            $stabloNiza = self::novoStablo(self::P_NIZ, self::vrijednostTokena($tokeni[0]), null);
            $znak = self::vrijednostTokena($tokeni[1]);
            $tokeni = array_slice($tokeni, 2);
            $podstablo = self::S1($tokeni);
            $stablo = self::novoStablo(self::P_GRUPA_UPITNIK, self::novoStablo(self::T_NIZ, [$znak], null), $podstablo);

            return self::novoStablo(self::P_NIZ_GRUPA, $stabloNiza, $stablo);

        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_OTV])) {
            $stabloNiza = self::novoStablo(self::P_NIZ, self::vrijednostTokena($tokeni[0]), null);
            $tokeni = array_slice($tokeni, 2);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT, true);
            $stablo2 = self::S1($tokeni);
            $stablo = self::novoStablo(self::P_GRUPA, $stablo1, $stablo2);

            return self::novoStablo(self::P_NIZ_GRUPA, $stabloNiza, $stablo);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_OTV_ZVIJEZDA])) {
            $stabloNiza = self::novoStablo(self::P_NIZ, self::vrijednostTokena($tokeni[0]), null);
            $tokeni = array_slice($tokeni, 2);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT_ZVIJEZDA, true);
            $stablo2 = self::S1($tokeni);
            $stablo = self::novoStablo(self::P_GRUPA_ZVIJEZDA, $stablo1, $stablo2);

            return self::novoStablo(self::P_NIZ_GRUPA, $stabloNiza, $stablo);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_OTV_PLUS])) {
            $stabloNiza = self::novoStablo(self::P_NIZ, self::vrijednostTokena($tokeni[0]), null);
            $tokeni = array_slice($tokeni, 2);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT_PLUS, true);
            $stablo2 = self::S1($tokeni);
            $stablo = self::novoStablo(self::P_GRUPA_PLUS, $stablo1, $stablo2);

            return self::novoStablo(self::P_NIZ_GRUPA, $stabloNiza, $stablo);
        } else if (self::ocekuj($tokeni, [self::T_NIZ, self::T_OTV_UPITNIK])) {
            $stabloNiza = self::novoStablo(self::P_NIZ, self::vrijednostTokena($tokeni[0]), null);
            $tokeni = array_slice($tokeni, 2);
            $stablo1 = self::S1($tokeni);
            self::ocekuj($tokeni, self::T_ZAT_UPITNIK, true);
            $stablo2 = self::S1($tokeni);
            $stablo = self::novoStablo(self::P_GRUPA_UPITNIK, $stablo1, $stablo2);

            return self::novoStablo(self::P_NIZ_GRUPA, $stabloNiza, $stablo);
        } else if (self::ocekuj($tokeni, self::T_NIZ)) { //TODO: možemo imati granu za epsilon i uniju kao validne za return null, ali ostali onda
            // mogu prijaviti informativniju grešku o parsiranju oko toga što ne valja
            $tokeni = array_slice($tokeni, 1);

            return self::novoStablo(self::P_NIZ, self::vrijednostTokena($tokeni[0]), null);
        }

        return null;
    }

    // Vraća minimiziran regex AST
    public function minimiziraj() {
        switch ($this->oznaka) {
            case self::P_GRUPA:
                if ($this->desno === null) {
                    if ($this->lijevo === null)
                        return null;
                    else
                        return $this->lijevo->minimiziraj();
                } else {
                    if ($this->lijevo !== null) {
                        $this->lijevo = $this->lijevo->minimiziraj();
                        $this->desno = $this->desno->minimiziraj();
                    } else {
                        return $this->desno->minimiziraj();
                    }
                }
                break;

            case self::P_GRUPA_PLUS:
            case self::P_GRUPA_UPITNIK:
            case self::P_GRUPA_ZVIJEZDA:
                if ($this->lijevo !== null)
                    $this->lijevo = $this->lijevo->minimiziraj();
                else
                    return $this->desno;
                if ($this->desno !== null)
                    $this->desno = $this->desno->minimiziraj();
                break;

            case self::P_UNIJA:
                if ($this->lijevo === null && $this->desno === null) {
                    return null;
                }

                if ($this->lijevo === null)
                    return $this->desno->minimiziraj();
                else
                    return $this->lijevo->minimiziraj();
                break;

            case self::P_NIZ_GRUPA:
                if ($this->desno !== null)
                    $this->desno = $this->desno->minimiziraj();
                break;
        }

        return $this;
    }
}
?>
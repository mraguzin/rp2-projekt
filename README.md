Projekt je web aplikacija za pretvorbu regularnih izraza u (nedeterminističke) konačne automate i obratno.
Pritom je moguće uključiti i dodatne optimizacije/minimizacije dobivenog automata, kako bi on bio što pregledniji. Regularni izrazi i automati se mogu
dijeliti između korisnika registriranih na servis, preko jedinstvenog linka koji predstavlja obje ekvivalentne reprezentacije. Registracija je *nužna*
kako bi se takav link mogao otvoriti; ako posjetitelj nije ulogiran, dolazi na *landing page* gdje mu se nude odgovarajuće opcije. Tako spremljeni
automati/regularni izrazi se mogu mijenjati samo od strane njihovog originalnog autora, pri čemu inicijalno dobiveni link i dalje ostaje važeći i 
ukazuje na izmijenjeni objekt.

Korisnik može započeti rad tekstualnim unosom regularnom izraza koji se potom parsira i pretvori u automat ili pak može prvo nacrtati svoj automat koji će onda
biti pretvoren u odgovarajući regularni izraz. Dobiveni RI se može kopirati klikom na gumb, a dobiveni automat spremiti u obliku PNG slike na korisnikovo
računalo. Autentikacija korisnika je moguća i preko vanjskih servisa poput Googlea, Facebooka itd. (to be decided...)

Neki tehnički detalji: koristimo MVC, JS je na frontendu za (interaktivno) crtanje automata na canvasu i neke druge GUI radnje, a PHP & MariaDB na backendu
za izvođenje samih algoritama i spremanje objekata (što uključuje i korisnike) u bazu. Relevantna teorija vezana za ovo područje je u Sipseru i, što se
minimizacijskih algoritama tiče, u Aho & Ullman i knjizi An Introduction to Formal Languages and Automata (Peter Linz).

# Moguće dodatne stvari
* mogućnost spremanja dobivenog automata u nekakvu (standardnu?) tekstualnu reprezentaciju grafa i mogućnost stvaranja automata iz takve reprezentacije;
* mogućnost učitavanja riječi za koju se testira ako pripada regularnom jeziku kojeg opisuje regularni izraz;
* parser koji zanemaruje moguće probleme u ulaznom regexu, a to praktički mogu biti samo krivo sparene (ili uopće ne sparene) zagrade i pretpostavlja što je korisnik htio reći;
* dodati mogućnost specificiranja klasa regexa, poput [A-Z] i sl.
* ?...
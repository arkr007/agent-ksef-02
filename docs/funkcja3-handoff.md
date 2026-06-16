# Funkcja 3 - Handoff

## Cel funkcji

Funkcja 3 ma porownywac dane z pliku `JPK` z fakturami pobranymi na biezaco z `KSeF` dla wybranego miesiaca.

Aktualne zalozenia:

- porownanie odbywa sie osobno dla dokumentow `kosztowych` i `sprzedazowych`,
- podstawowym kluczem porownania jest `numer dokumentu`,
- porownanie kwot odbywa sie na `kwocie brutto`,
- dla faktur KSeF w `PLN` porownywana jest kwota bezposrednio,
- dla faktur KSeF w walutach obcych kwota jest przeliczana do `PLN`,
- zgodnosc kwot jest uznawana przy roznicy wzglednej `< 1%` liczonej wzgledem kwoty z `JPK`.

## Status na dzien 2026-06-16

Rozwoj funkcji 3 zostal swiadomie zawieszony na obecnym etapie.

To juz dziala:

- ekran `Porownanie JPK`,
- upload pliku `XML`,
- parsowanie `JPK_PKPIR`,
- awaryjne wsparcie dla wariantow z `SprzedazWiersz` i `ZakupWiersz`,
- pobieranie live danych `KSeF` dla wybranego miesiaca,
- rozdzielenie KSeF na `cost` i `sale`,
- porownanie `JPK vs KSeF`,
- eksport wyniku do `CSV`,
- obsluga faktur walutowych przez przeliczenie do `PLN`,
- cache metadanych `KSeF` w sesji,
- cache kursow `NBP` w sesji.

## Najwazniejsze decyzje funkcjonalne

### Zrodlo danych KSeF

Porownanie nie opiera sie na lokalnym archiwum faktur zapisanych dawniej w bazie.

Zalozenie jest takie:

- uzytkownik wybiera miesiac,
- aplikacja pobiera na biezaco dane z `KSeF` dla tego miesiaca,
- dopiero ten swiezy zbior jest porownywany z `JPK`.

### Typy dokumentow

Porownanie wykonujemy osobno dla:

- `cost`,
- `sale`.

Nie mieszamy dokumentow kosztowych i sprzedazowych miedzy soba.

### Kwoty walutowe

Aktualna implementacja:

- jezeli `currency = PLN`, do porownania idzie `gross_amount` bez przeliczenia,
- jezeli `currency != PLN`, aplikacja pobiera kursy srednie `NBP`,
- kurs wybierany jest jako ostatnie dostepne notowanie nie pozniejsze niz `issue_date`,
- jezeli taki kurs nie zostanie znaleziony, jest fallback do sredniej miesiecznej dla waluty i miesiaca porownania,
- do porownania uzywana jest kwota `comparison_gross_amount` w `PLN`.

### Tolerancja zgodnosci kwot

Aktualna tolerancja:

- zgodnosc kwot: roznica wzgledna `< 1%`,
- procent liczony jest wzgledem kwoty z `JPK`.

Przyklad:

- `100,00` vs `100,99` -> zgodne,
- `100,00` vs `101,00` -> niezgodne.

## Obecny przeplyw

1. Uzytkownik wgrywa plik `JPK XML` i wybiera miesiac.
2. Parser rozpoznaje wpisy ksiegowe z `JPK`.
3. Aplikacja pobiera z `KSeF` metadane dokumentow `cost` i `sale` dla wskazanego miesiaca.
4. Aplikacja mapuje dane `KSeF` do wspolnego formatu.
5. Dla faktur walutowych aplikacja wylicza `comparison_gross_amount` w `PLN`.
6. Matcher wylicza statusy porownania.
7. Wynik jest pokazywany w tabeli i moze byc wyeksportowany do `CSV`.

## Statusy porownania

Aktualnie uzywane statusy:

- `BOTH`
- `NUMBER_MATCH_AMOUNT_DIFF`
- `AMOUNT_MATCH_NUMBER_DIFF`
- `ONLY_KSEF`
- `ONLY_JPK`

Znaczenie:

- `BOTH`: zgodny typ, numer i kwota w granicy tolerancji,
- `NUMBER_MATCH_AMOUNT_DIFF`: numer zgodny, kwota poza tolerancja,
- `AMOUNT_MATCH_NUMBER_DIFF`: kwota zgodna w granicy tolerancji, ale inny numer,
- `ONLY_KSEF`: dokument jest tylko w KSeF,
- `ONLY_JPK`: dokument jest tylko w JPK.

## Kluczowe pliki

### Routing i skladanie zaleznosci

- `C:\Users\Dell\Documents\Playground\agent_ksef_v03\public\index.php`

### Kontroler funkcji 3

- `C:\Users\Dell\Documents\Playground\agent_ksef_v03\src\Controller\AccountingCompareController.php`

### Parser JPK

- `C:\Users\Dell\Documents\Playground\agent_ksef_v03\src\Service\JpkAccountingParser.php`

### Mapper danych KSeF

- `C:\Users\Dell\Documents\Playground\agent_ksef_v03\src\Service\InvoiceMapper.php`

### Matcher porownania

- `C:\Users\Dell\Documents\Playground\agent_ksef_v03\src\Service\InvoiceMatcher.php`

### Kursy NBP

- `C:\Users\Dell\Documents\Playground\agent_ksef_v03\src\Service\NbpExchangeRateService.php`

### Klient KSeF

- `C:\Users\Dell\Documents\Playground\agent_ksef_v03\src\Service\KsefClient.php`

### Widok funkcji 3

- `C:\Users\Dell\Documents\Playground\agent_ksef_v03\templates\accounting_compare.php`

## Szczegoly parsera JPK

Parser jest dostosowany glownie do `JPK_PKPIR`.

Najwazniejsze mapowanie:

- `PKPIRWiersz`
- `K_1` -> `row_lp`
- `K_2` -> `event_date`
- `K_3A` -> podstawowy `document_number`
- `K_3B` -> pomocniczy identyfikator, trafia do `notes`
- `K_5A` -> `contractor_name`
- `K_5B` -> `contractor_address`
- `K_6` -> `business_event_description`

Heurystyka typu i kwoty:

- `sale`: priorytet pol `K_9`, `K_8`, `K_7`
- `cost`: priorytet pol `K_14`, `K_13`, `K_12`, `K_11`, `K_10`

## Znane ograniczenia i ryzyka

### 1. Jakosc metadanych KSeF

Porownanie korzysta glownie z metadanych `KSeF`, bez masowego pobierania wszystkich XML-i.

To jest swiadoma decyzja wydajnosciowa, ale oznacza, ze:

- jakosc porownania zalezy od tego, co realnie wraca w metadanych,
- przy nietypowych strukturach niektore pola moga wymagac dopelnienia kolejnymi aliasami w `InvoiceMapper`.

### 2. Waluty

Obecna implementacja przelicza po kursie `NBP`, ale wymaga dalszej walidacji na realnych danych biznesowych.

Do sprawdzenia w praktyce:

- czy data kursu jest zgodna z oczekiwaniem ksiegowym,
- czy fallback do sredniej miesiecznej jest akceptowalny,
- czy wszystkie waluty faktycznie sa obslugiwane przez API `NBP`.

### 3. Numer dokumentu

Porownanie opiera sie na numerze dokumentu i kwocie brutto.

Mozliwe przyszle problemy:

- rozne formaty numerow miedzy `JPK` i `KSeF`,
- korekty,
- numery techniczne vs numery handlowe,
- duplikaty o tej samej kwocie.

### 4. Siec i API NBP

W czasie ostatnich prac nie bylo mozliwosci potwierdzenia live wywolan do `api.nbp.pl` z poziomu tego srodowiska CLI.

Implementacja jest gotowa, ale dzialanie live powinno byc potwierdzone przez test w aplikacji pod `XAMPP`.

## Ostatnie istotne zmiany

W ostatnim cyklu prac zostalo zrobione:

- zmiana z `PDF` na `JPK`,
- przebudowa parsera pod `JPK_PKPIR`,
- uszczelnienie komunikatow i eksportu `CSV`,
- dodanie przeliczenia walut do `PLN`,
- zmiana tolerancji zgodnosci z `< 0,10 PLN` na `< 1%`.

## Co sprawdzic po wznowieniu prac

Najpierw warto wykonac testy reczne:

1. Wczytac realny plik `JPK_PKPIR`.
2. Sprawdzic miesiac z fakturami `PLN`.
3. Sprawdzic miesiac z fakturami walutowymi.
4. Zweryfikowac, czy:
   - kwota `KSeF` do porownania jest pokazana w `PLN`,
   - widac kwote oryginalna i kurs,
   - statusy zmieniaja sie zgodnie z tolerancja `< 1%`.
5. Zweryfikowac eksport `CSV`.

## Najbardziej prawdopodobne nastepne kroki

Jesli wznowimy rozwoj funkcji 3, sensowna kolejnosc jest taka:

1. Potwierdzenie live, ze kursy `NBP` zwracaja sie poprawnie dla walutowych faktur z `KSeF`.
2. Walidacja, czy procentowa tolerancja `< 1%` jest biznesowo prawidlowa dla wszystkich przypadkow.
3. Dodanie do tabeli jawnego pola z procentowa roznica.
4. Dopracowanie opisow statusow i uzasadnien.
5. Ewentualne rozszerzenie matcher-a o dodatkowe reguly dla korekt i nietypowych numerow.

## Jak wznowic temat

Przy powrocie do funkcji 3 najlepiej zaczac nowy prompt w tej formie:

`Wroc do funkcji 3 i oprzyj sie na pliku docs/funkcja3-handoff.md.`

Mozna tez dopisac od razu cel:

`Wroc do funkcji 3 i oprzyj sie na pliku docs/funkcja3-handoff.md. Najpierw zweryfikuj obsluge walut i testy live NBP.`

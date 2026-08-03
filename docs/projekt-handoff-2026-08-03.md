# Projekt - Handoff 2026-08-03

## Cel dokumentu

Ten handoff opisuje aktualny stan projektu po pracach na branchu `codex/production-accountant-jpk`.
Ma pozwolic szybko wznowic prace bez odtwarzania calego kontekstu rozmowy.

## Zasady wspolpracy

- projekt jest prowadzony etapowo,
- po wiekszych zmianach uzytkownik testuje i akceptuje kolejny krok,
- commity i push-e maja byc robione na biezaco,
- opisy commitow maja byc po polsku,
- przed utrata kontekstu maja byc przygotowywane kolejne handoffy w katalogu projektu.

## Repo i branch

- repo: `arkr007/agent-ksef-02`
- katalog roboczy: `C:\Users\Dell\Documents\Playground\agent_ksef_v03`
- aktywny branch: `codex/production-accountant-jpk`
- stan lokalny przy zapisie handoffu: czysty
- ostatni kontrolny push: wykonany, wynik `Everything up-to-date`

## Najwazniejsze ostatnie commity

- `ba6c765` `Poprawa retry i timeoutow analizy PDF`
- `d5cfeee` `Poprawa ponownej analizy zakresow PDF`
- `920de68` `Wydluzenie czasu wykonania pakietu ksiegowej`
- `1b3f29f` `Poprawa limitu uploadu w pakiecie ksiegowej`
- `52b93bc` `Przebudowa pakietu na jednorazowy wybor plikow`

## Aktualny kierunek produktu

Na tym branchu aplikacja jest upraszczana do wersji roboczo-produkcyjnej, w ktorej najwazniejsze sa:

- `Pakiet dla ksiegowej`
- `Porownanie JPK`

W praktyce najwiecej ostatnich prac dotyczylo modulu `Pakiet dla ksiegowej`.

## Stan funkcji 4 - Pakiet dla ksiegowej

### Co jest zrobione

- ekran dziala w uproszczonym przeplywie produkcyjnym,
- wybor danych jest jednorazowy z poziomu przegladarki:
  - plik `stali_wystawcy.csv`,
  - folder z PDF,
  - miesiac KSeF,
- po wykonaniu przebiegu formularz wraca do stanu poczatkowego,
- wynik koncowy jest prezentowany jako zestawienie i eksport `CSV`,
- eksport opiera sie na danych z aktualnej sesji,
- poprawiono limity uploadu i czasy wykonania po stronie aplikacji,
- parser PDF przestal zostawiac bardzo szerokie zakresy typu `4-95` jako jeden blok recznej weryfikacji,
- parser ma teraz retry na mniejszych blokach oraz probe zejscia do pojedynczych stron,
- dla timeoutow AI dodano:
  - mniejszy rozmiar blokow (`4` strony),
  - retry po timeoutcie,
  - ponowna probe AI dla pojedynczej strony z czesciowo rozpoznanymi danymi, ale bez kwoty,
  - timeout OpenAI podniesiony pomocniczo z `180` do `240` sekund.

### Co nadal jest problemem

Glowny realny problem modulu nie polega juz na samym grupowaniu stron, tylko na niestabilnosci rozpoznawania AI dla czesci dokumentow.

Na testowym pliku:

- `ARTNOVA 06.26.pdf`

wciaz wystepowaly strony trafiajace do `Do recznej weryfikacji` z komunikatami typu:

- `Blad komunikacji z OpenAI API: Operation timed out after 180006 milliseconds with 0 bytes received`

Dotyczylo to m.in. dokumentow:

- `Google Cloud Poland Sp. z o.o.` - strona `83`
- `Anthropic Ireland, Limited` - strona `86`
- `Anthropic, PBC` - strona `90`
- wczesniej tez `Adobe` na stronie `7`

### Aktualna diagnoza

- czesc stron nie jest gubiona przez logike dopasowania,
- problemem jest to, ze OpenAI nie zwraca odpowiedzi na czas dla niektorych prob rozpoznania,
- po timeoutcie parser spada do fallbacku tekstowego,
- fallback czesto znajduje:
  - wystawce,
  - numer,
  - czasem date,
- ale nadal gubi kwoty, przez co dokument trafia do recznej weryfikacji.

### Kolejne sensowne kroki

1. Zweryfikowac po ostatnich poprawkach, czy:
   - `Adobe` strona `7`,
   - `Google` strona `83`,
   - `Anthropic` strony `86` i `90`
   przestaly wpadac do szerokich zbiorczych zakresow i czy przynajmniej sa obrabiane jako pojedyncze strony.
2. Jesli dalej brakuje kwot mimo retry AI:
   - dolozyc reguly ekstrakcji kwot dla konkretnych ukladov faktur,
   - zaczac od `Anthropic`, `Google`, `Adobe`.
3. Rozwazyc dalsze odchudzenie requestow do OpenAI:
   - mniej stron w jednym zapytaniu,
   - mniej obrazow przy retry.

## Stan funkcji 3 - Porownanie JPK

Funkcja 3 ma osobny handoff:

- `docs/funkcja3-handoff.md`

Aktualny stan wysokopoziomowy:

- modul istnieje i dziala,
- porownanie jest prowadzone wzgledem danych KSeF i pliku JPK,
- temat zostal czasowo odsuniety na rzecz prac nad `Pakietem dla ksiegowej`,
- wczesniejsza sciezka PDF dla funkcji 3 zostala zarzucona na rzecz JPK.

## Stan helpera lokalnego i sciezek lokalnych

- w repo istnieje dokument `docs/local-helper.md`,
- helper lokalny byl rozwijany wczesniej jako mechanizm wyboru sciezek,
- dla aktualnej wersji `Pakietu dla ksiegowej` glowny przeplyw nie polega juz na helperze,
- obecny preferowany model to jednorazowy wybor plikow z poziomu formularza w przegladarce.

## Istotne pliki

- `src/Controller/AccountantPackageController.php`
- `src/Service/AccountantPackageSummaryService.php`
- `src/Service/PdfInvoiceCandidateParser.php`
- `src/Service/OpenAiHelper.php`
- `templates/accountant_package.php`
- `public/assets/app.js`
- `docs/funkcja3-handoff.md`
- `docs/local-helper.md`

## Najwazniejsze decyzje uzytkownika

- rozwijamy projekt etapowo, z testami i akceptacja po kazdym wiekszym kroku,
- commity i push-e maja byc robione na biezaco,
- opisy commitow maja byc po polsku,
- trzeba regularnie tworzyc handoffy w repo, zanim kontekst rozmowy zacznie byc skracany,
- dla wersji produkcyjnej obecny nacisk jest na:
  - `Pakiet dla ksiegowej`,
  - `Porownanie JPK`,
- lokalne foldery i dane wrazliwe maja byc obslugiwane ostroznie; preferowany jest lokalny lub maksymalnie kontrolowany przeplyw danych.

## Otwarte ryzyka

- niestabilnosc lub timeouty OpenAI dla czesci stron PDF,
- fallback tekstowy nadal bywa za slaby do wyciagania kwot,
- najnowszy ogolny handoff sprzed tego pliku (`docs/projekt-handoff-2026-06-21.md`) jest juz historyczny i nie opisuje aktualnego brancha.

## Punkt wznowienia

Jesli wracamy do projektu z tego miejsca, najbezpieczniej zaczac od:

1. uruchomienia testu modulu `Pakiet dla ksiegowej` na `ARTNOVA 06.26.pdf`,
2. sprawdzenia stron `7`, `83`, `86`, `90`,
3. oceny, czy po ostatnich retry AI nadal brakuje glownie kwot,
4. jesli tak, przejscia do regul ekstrakcji kwot dla konkretnych dostawcow.

## Sugestia promptu wznowienia

`Wroc do projektu i oprzyj sie na pliku docs/projekt-handoff-2026-08-03.md. Najpierw zweryfikuj skutecznosc modulu Pakiet dla ksiegowej dla pliku ARTNOVA 06.26.pdf i stron 7, 83, 86, 90.`

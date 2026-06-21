# Projekt - Handoff 2026-06-21

## Cel dokumentu

Ten plik ma pozwolic szybko wznowic prace nad projektem po przerwie, bez odtwarzania calego kontekstu z rozmowy.

Najwazniejsze zalozenie na ten moment:

- projekt jest rozwijany etapowo,
- po kazdym etapie uzytkownik testuje funkcje i akceptuje przejscie dalej,
- commity i push-e maja byc robione na biezaco, z opisami po polsku.

## Repo i branch

- repo: `arkr007/agent-ksef-02`
- lokalny katalog roboczy: `C:\Users\Dell\Documents\Playground\agent_ksef_v03`
- aktywny branch przy tym handoffie: `codex/ollama-local-llm`

## Co jest zrobione

### Fundament aplikacji

Dziala szkielet aplikacji w PHP:

- routing,
- kontrolery,
- widoki,
- konfiguracja,
- polaczenie z baza,
- logowanie i sesje,
- CSRF,
- audit log,
- panel ustawien.

### Modul KSeF

Dziala pobieranie faktur z `KSeF`:

- wybor zakresu dat,
- pobieranie metadanych i dokumentow,
- mapowanie danych,
- zapis do bazy,
- tabela wynikow,
- eksport `CSV`.

W trakcie prac dopracowano m.in.:

- walidacje rachunku,
- kwoty `netto`, `brutto`, `VAT`,
- obsluge pola `do zaplaty`,
- wariant tabelaryczny i bardziej dashboardowy widoku wynikow.

### Modul importu bankowego

Funkcja importu `CSV` i generowania `pain.001.001.09` zostala wdrozona jako osobny modul:

- import pliku,
- walidacja danych,
- podglad,
- eksport przelewow do XML.

### Funkcja 3 - porownanie KSeF z JPK

Ten tor zostal zatrzymany celowo i ma osobny handoff:

- plik: `docs/funkcja3-handoff.md`

Na ten moment:

- porownanie dziala na `JPK XML`,
- dane `KSeF` sa pobierane na biezaco dla wybranego miesiaca,
- obsluzone sa waluty i tolerancja roznicy `< 1%`,
- byly poprawiane limity zapytan do `KSeF`.

### Funkcja 4 - Pakiet dla ksiegowej

To jest aktualnie najbardziej rozwiniety tor AI/PDF.

Dziala:

- wczytanie listy stalych wystawcow z:
  `Desktop\faktury_do_ksiegowej\rob\stali_wystawcy.csv`
- wczytanie faktur kosztowych z `KSeF` dla miesiaca,
- odczyt plikow `PDF` z:
  `Desktop\faktury_do_ksiegowej`
- pomijanie podfolderow,
- przypisywanie dokumentow do stalych wystawcow,
- oznaczanie brakow i kompletow,
- eksport zestawienia,
- akordeony i poprawki UX,
- oznaczanie podejrzanych odczytow jako `do recznej weryfikacji`.

W trakcie prac zmieniono podejscie do PDF:

- najpierw parser tekstowy,
- potem dzielenie na strony,
- potem rozpoznawanie przez `OpenAI` na podstawie obrazow stron.

## Aktualny stan AI

### Providerzy AI

W aplikacji zostala dodana warstwa providerow AI:

- `ollama`
- `hybrid`
- `openai`

Konfiguracja jest w ustawieniach aplikacji.

Domyslny kierunek docelowy ustalony z uzytkownikiem:

- preferowany tryb: `ollama`,
- alternatywa: `hybrid`,
- awaryjnie: `openai`.

Tryby `hybrid` i `openai` maja ostrzezenia, bo moga wysylac wrazliwe dane poza lokalna stacje.

### OpenAI

Po poprawkach promptu i fallbacku:

- tryb `openai` znowu daje bardzo dobra skutecznosc,
- na danych testowych uzytkownik potwierdzil skutecznosc `100%`.

### Ollama

To jest obecnie glowny punkt otwarty.

Zrobione:

- dodana obsluga lokalnego endpointu `Ollama`,
- test polaczenia z modelem,
- walidacja, czy model wspiera `vision`,
- provider `hybrid` z fallbackiem do `OpenAI`,
- ostrzezenia przed przetwarzaniem poza lokalna stacja.

Ustalony problem:

- `qwen3-vl:8b` jest widoczny w `Ollama`,
- model raportuje capability `vision`,
- tekstowo odpowiada poprawnie,
- przy wejsciu obrazowym zwraca bardzo slabe wyniki albo timeout,
- aplikacja po bledzie multimodalnym wpada w fallback tekstowy i potem w reczna weryfikacje.

Przeprowadzone testy wskazaly, ze:

- problem nie wyglada na blad mappera ani parsera,
- problem jest najpewniej po stronie lokalnego wykonania vision na obecnym sprzecie,
- lokalna karta `Quadro P620 2 GB VRAM` jest bardzo slaba dla tego typu modelu vision.

## Najwazniejsze pliki dla aktualnego toru AI/PDF

### Skladanie zaleznosci i routing

- `public/index.php`

### Resolver i providerzy AI

- `src/Service/AiRecognizerResolver.php`
- `src/Service/DocumentAiRecognizerInterface.php`
- `src/Service/HybridAiRecognizer.php`
- `src/Service/OpenAiHelper.php`
- `src/Service/OllamaHelper.php`
- `src/Service/InvoiceAiPromptCatalog.php`

### PDF i rozpoznawanie dokumentow

- `src/Service/PdfPageRenderService.php`
- `src/Service/PdfInboxAnalysisService.php`
- `src/Service/PdfInvoiceCandidateParser.php`
- `src/Controller/AccountantPackageController.php`
- `templates/accountant_package.php`

## Ostatnie istotne commity na branchu

- `798091b` Dodanie warstwy providerow AI dla Ollama
- `25e477c` Wzmocnienie kolorow komunikatow walidacji
- `717c633` Dodanie testu polaczenia z Ollama i ostrzezen AI
- `780fda5` Poprawka miejsca ostrzezenia dla analizy AI
- `4691118` Poprawa fallbacku hybrid i promptu OpenAI
- `b45a198` Walidacja modeli vision dla Ollama

## Realne kierunki dalszych prac

### Kierunek 1 - lokalne AI / sprzet

To jest teraz najbardziej naturalny nastepny krok.

Cel:

- ustalic, jaki sprzet kupic albo jak skonfigurowac lokalne `LLM`, zeby przetwarzanie wrazliwych danych dzialalo lokalnie i skutecznie.

Co zbadac:

1. Jaki model `Ollama` ma sens na docelowej stacji roboczej.
2. Czy docelowy komputer ma miec mocna `GPU`, czy rozwazac CPU-only.
3. Czy dla przetwarzania vision potrzebny jest model mniejszy, ale stabilniejszy niz `qwen3-vl:8b`.
4. Czy rozsadniej rozwijac tryb:
   - `ollama` jako docelowy,
   - `hybrid` jako bezpieczny fallback,
   - `openai` jako tryb awaryjny.

### Kierunek 2 - dokonczenie funkcji 4

Po stronie biznesowej funkcja 4 jest juz daleko, ale nie jest jeszcze zamknieta.

Do rozstrzygniecia:

1. Czy finalnym silnikiem dla PDF ma byc:
   - lokalne `Ollama`,
   - `hybrid`,
   - tymczasowo samo `OpenAI`.
2. Czy rozpoznawanie ma isc:
   - strona po stronie,
   - caly PDF naraz,
   - mieszanie obu strategii.
3. Czy dodac jawny raport diagnostyczny:
   - ktore strony rozpoznal provider AI,
   - ktore strony trafily do fallbacku,
   - z jakiego powodu dokument trafil do recznej weryfikacji.

### Kierunek 3 - wznowienie funkcji 3

Funkcja 3 zostala zawieszona, ale jest gotowa do wznowienia.

Punkt startowy:

- `docs/funkcja3-handoff.md`

Najbardziej logiczne dalsze prace:

1. Potwierdzenie live obslugi walut i kursow `NBP`.
2. Dalsze testy miesiecy z realnymi danymi.
3. Dopracowanie opisow statusow i eksportu.

### Kierunek 4 - lokalne AI jako warstwa wspolna dla wszystkich modulow AI

To jest kierunek architektoniczny, nie tylko poprawka jednego ekranu.

Uzgodnione zalozenie:

- wszystkie obecne i przyszle moduly AI maja wspierac:
  - `ollama`,
  - `hybrid`,
  - `openai`.

To oznacza, ze po ustabilizowaniu lokalnego AI warto uporzadkowac:

1. wspolne komunikaty i ostrzezenia,
2. wspolny test gotowosci providera,
3. wspolne logowanie bledow AI,
4. wspolny sposob oznaczania fallbacku i recznej weryfikacji.

## Rekomendowany punkt powrotu po przerwie

Jesli wracamy do projektu po zakupie lub wyborze sprzetu, najlepszy prompt startowy to:

`Wroc do projektu i oprzyj sie na pliku docs/projekt-handoff-2026-06-21.md. Najpierw zweryfikuj kierunek lokalnego AI dla Ollama.`

Jesli wracamy od razu do porownania JPK:

`Wroc do funkcji 3 i oprzyj sie na pliku docs/funkcja3-handoff.md.`

Jesli wracamy do pakietu dla ksiegowej:

`Wroc do funkcji 4 i oprzyj sie na pliku docs/projekt-handoff-2026-06-21.md. Najpierw zajmij sie lokalnym AI dla PDF.`

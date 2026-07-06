# Wdrozenie produkcyjne - checklista

## Status

Kod na branchu `codex/production-accountant-jpk` jest przygotowywany pod produkcyjna wersje aplikacji ograniczona do:

- `Pakiet dla ksiegowej`
- `Porownanie JPK`

## Krytyczny warunek architektoniczny

Obecna funkcja `Pakiet dla ksiegowej` czyta pliki z lokalnej stacji roboczej:

- `Desktop\\faktury_do_ksiegowej`
- `Desktop\\faktury_do_ksiegowej\\rob\\stali_wystawcy.csv`

To oznacza, ze po przeniesieniu aplikacji na serwer internetowy **nie da sie zachowac obecnego modelu 1:1**, bo serwer WWW nie ma bezposredniego dostepu do pulpitu uzytkownika w przegladarce.

Przed wdrozeniem trzeba wybrac jeden z modeli:

1. `Upload przez przegladarke`
   - uzytkownik wgrywa PDF-y i `stali_wystawcy.csv` przez formularz,
   - pliki trafiaja do katalogu na serwerze.
2. `Wspoldzielony zasob sieciowy`
   - serwer ma dostep do wspolnego katalogu sieciowego,
   - aplikacja czyta pliki z udostepnionej lokalizacji.
3. `Lokalny agent pomocniczy`
   - mala usluga na stacji roboczej wysyla pliki do aplikacji serwerowej,
   - sama aplikacja webowa dziala juz zdalnie.

Bez tej decyzji nie ma sensu robic finalnego wdrozenia internetowego funkcji `Pakiet dla ksiegowej`.

## Co jest potrzebne do wdrozenia

### Serwer

- PHP `8.2+`
- MySQL lub MariaDB
- HTTPS
- mozliwosc ustawienia katalogu publicznego albo przekierowania na `public/`
- zapis do katalogow aplikacji: logi, uploady, eksporty

### Dostepy i dane

- dane do hostingu lub VPS
- dane do bazy danych
- domena lub subdomena
- certyfikat SSL albo mozliwosc jego wystawienia
- docelowe ustawienia `config.php`
- decyzja, jak beda dostarczane pliki dla `Pakietu dla ksiegowej`

## Minimalna kolejnosc prac

1. Wybrac model dostarczania plikow dla `Pakietu dla ksiegowej`.
2. Przygotowac produkcyjny `config.php`.
3. Utworzyc baze i zaimportowac schemat.
4. Wgrac aplikacje na serwer.
5. Skonfigurowac katalog `public/` jako punkt wejscia.
6. Zweryfikowac logowanie, ustawienia, KSeF i eksport CSV.
7. Wykonac test reczny obu modulow:
   - `Pakiet dla ksiegowej`
   - `Porownanie JPK`

## Rekomendacja na teraz

Najpierw trzeba podjac decyzje, czy `Pakiet dla ksiegowej` ma dzialac po uploadzie plikow przez przegladarke.

To jest najbardziej naturalny model dla wersji internetowej i prawdopodobnie bedzie wymaganym kolejnym krokiem rozwojowym.

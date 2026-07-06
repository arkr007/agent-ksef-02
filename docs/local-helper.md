# Lokalny helper wyboru sciezek

## Cel

Helper pozwala wybierac folder PDF i plik `stali_wystawcy.csv` z okna systemowego Windows, bez wpisywania sciezek recznie.

## Pliki

- `tools/local-helper/agent-ksef-helper.ps1`
- `tools/local-helper/agent-ksef-helper.cmd`

## Jak uruchomic

1. Na stacji roboczej uruchom plik:
   - `tools/local-helper/agent-ksef-helper.cmd`
2. Zostaw otwarte okno PowerShell helpera.
3. Wejdz w `Ustawienia -> Lokalizacje folderow lokalnych`.
4. Kliknij:
   - `Wybierz folder`
   - `Wybierz plik CSV`
5. Zapisz ustawienia formularzem aplikacji.

## Endpoint

Domyslny adres helpera:

- `http://127.0.0.1:8765`

Stan helpera:

- `GET /health`

Akcje wyboru:

- `POST /pick-folder`
- `POST /pick-file`

## Uwagi

- Helper dziala lokalnie na tej samej stacji co przegladarka.
- Nie przesyla samych plikow na serwer, zwraca tylko wybrane sciezki.
- To jest pierwszy krok integracji lokalnej. Przy docelowym wdrozeniu internetowym trzeba jeszcze osobno zweryfikowac zachowanie przegladarki dla polaczenia z `localhost`.

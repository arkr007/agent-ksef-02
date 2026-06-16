# Agent KSeF MVP

Aktualny stan obejmuje etapy 1-4:

- szkielet aplikacji w PHP z routingiem i widokami,
- logowanie, sesje, CSRF, audit log i ekran pierwszego administratora,
- ustawienia KSeF, OpenAI i danych płatnika zapisane w bazie,
- pobieranie faktur KSeF po zakresie dat, zapis do bazy, walidację danych płatności i eksport CSV.

## Uruchomienie lokalne

1. Upewnij się, że Apache i MySQL działają w XAMPP.
2. Zaimportuj plik [database/schema.sql](C:\Users\Dell\Documents\Playground\agent_ksef_v03\database\schema.sql).
3. Sprawdź lokalny `config/config.php`.
4. Otwórz `http://localhost/agent_ksef_v03/public/setup-admin`.
5. Utwórz pierwszego użytkownika i zaloguj się.

## Etap 4

Ekran `KSeF` pozwala obecnie:

- podać zakres dat,
- uruchomić pobieranie,
- zapisać wynik do tabel `invoice_fetch_jobs` i `invoices`,
- oznaczyć ostrzeżenia oraz błędy walidacji,
- wyeksportować aktualny zakres do CSV UTF-8 z BOM.

## Ważne ograniczenie

Miejsca zależne od aktualnej dokumentacji KSeF API 2.0 są nadal oznaczone jako `TODO_KSEF_*`.
Przed pierwszym prawdziwym pobraniem trzeba potwierdzić i uzupełnić:

- `ksef.endpoints.invoice_query`
- `ksef.endpoints.invoice_details`
- sposób autoryzacji używany przez KSeF

## Bezpieczeństwo

- sekrety nie powinny trafiać do repozytorium ani do katalogu `public/`,
- tokeny i klucz OpenAI są przechowywane szyfrowane w tabeli `settings`,
- lokalny `config/config.php` służy tylko do pracy na Twoim komputerze.

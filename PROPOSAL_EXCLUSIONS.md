# Propozycja Rozwiązania: Wykluczenia Przedmiotowe (Item Exclusions)

Zgodnie z Twoim życzeniem, przygotowałem szczegółową propozycję techniczną dla funkcjonalności wykluczania graczy z kolejek konkretnych przedmiotów (np. "Soul").

## 1. Zmiany w Bazie Danych (Database Schema)

Aby trwale zapisać informację o wykluczeniu gracza z danego przedmiotu, potrzebujemy nowej tabeli. Nie modyfikujemy istniejących tabel `players` ani `items`, tworzymy relację wiele-do-wielu.

**Nowa Tabela: `item_exclusions`**
- `id` (INT, AUTO_INCREMENT, PK)
- `player_id` (INT, FK -> players.id)
- `item_id` (INT, FK -> items.id)
- `created_at` (TIMESTAMP)

Dodatkowo, aby uniknąć duplikatów, nałożymy unikalny klucz na parę `(player_id, item_id)`.

---

## 2. Logika Backendowa (`functions.php`)

Główna zmiana nastąpi w funkcji `rebuild_all_queues()`, która oblicza pozycje w kolejkach.

**Aktualny Algorytm:**
1. Pobiera wszystkich graczy.
2. Symuluje historię (obecność, dropy).
3. Sortuje kolejkę wg zasad (Attendance, Hard Drop).

**Zmodyfikowany Algorytm:**
1. Na początku pobieramy listę wykluczeń z nowej tabeli `item_exclusions`.
2. Podczas budowania kolejki dla danego przedmiotu (`$queues[$itemId]`), sprawdzamy czy dany gracz (`$pid`) jest na liście wykluczonych.
3. **Zasada Wykluczenia (Exclusion Rule):**
   - Gracze wykluczeni są przenoszeni na sam koniec kolejki (za wszystkich aktywnych, a nawet za zbanowanych - lub razem z nimi, w zależności od preferencji).
   - W tabeli cache `item_queue_positions` dodamy nową kolumnę `is_excluded` (TINYINT), która przyjmie wartość `1` dla tych graczy. Pozwoli to na łatwe stylowanie we frontendzie bez dodatkowych zapytań.

---

## 3. Zmiany we Frontendzie (UI/UX)

### A. Dashboard (`index.php`)
Wyświetlanie kolejki zostanie zaktualizowane o obsługę flagi `is_excluded`.
- **Styl:** Gracze z flagą `is_excluded = 1` otrzymają klasę CSS nadającą im:
  - Mniejszą przezroczystość (`opacity-50`).
  - Kursywę (`italic`).
  - Ciemniejszy kolor tekstu (`text-gray-600`).
- **Pozycja:** Będą zawsze na samym dole listy.

### B. Panel Admina - Zarządzanie (`host.php?view=database`)
W sekcji "Baza Danych" (lub nowej zakładce "Zarządzanie Łupami") dodamy interfejs do zarządzania wykluczeniami.
- **Widok:** Tabela przedmiotów. Przy każdym przedmiocie przycisk "Zarządzaj Wykluczeniami" (Manage Exclusions).
- **Modal/Podstrona:** Po kliknięciu wyświetli się lista wszystkich graczy z checkboxami. Zaznaczenie checkboxa = wykluczenie gracza z tego przedmiotu.
- **Masowe akcje:** Opcja "Zaznacz wszystkich" / "Odznacz wszystkich" dla szybkiej edycji.

### C. Kreator Sesji - Przydział (`host.php?view=wizard`)
Podczas kroku 3 (Assign Loot):
- **Lista rozwijana (Select):** Gracze wykluczeni nie będą widoczni na liście wyboru "zwycięzcy" (lub będą na samym dole, oznaczeni jako "EXCLUDED", ale lepiej ich całkowicie ukryć, by uniknąć pomyłek).
- **Auto-sugestia:** Algorytm sugerujący zwycięzcę (`$suggestedWinnerId`) pominie graczy wykluczonych, nawet jeśli matematycznie byliby pierwsi w kolejce (co i tak nie powinno mieć miejsca dzięki zmianom w `rebuild_all_queues`, ale dla pewności dodamy warunek).

---

## 4. Mapa Drogowa Wdrożenia (Implementation Plan)

1.  **Krok 1 (DB):** Utworzenie tabeli `item_exclusions` i dodanie kolumny `is_excluded` do `item_queue_positions`.
2.  **Krok 2 (Backend):** Aktualizacja `rebuild_all_queues` w `functions.php` o logikę obsługi wykluczeń.
3.  **Krok 3 (Admin UI):** Dodanie interfejsu w `host.php` do dodawania/usuwania wykluczeń.
4.  **Krok 4 (Public UI):** Dostosowanie `index.php` do wyświetlania "wygaszonych" graczy.
5.  **Krok 5 (Wizard):** Filtrowanie wykluczonych graczy w kreatorze sesji.

Czy akceptujesz tę propozycję? Jeśli tak, przystąpię do kodowania (Krok 1).

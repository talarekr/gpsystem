# Ręczne Wznów po retry_exhausted

Audyt aktualnego main po #1513: licznik `attempts` był już lokalny dla pojedynczego `drive()`, nie znaleziono trwałej flagi retry blokującej kolejne wywołanie. Same dane produkcyjne nie dowodzą konkretnej przyczyny kliknięcia bez postępu. Słabe punkty kodu: cichy return dla `working`, brak anulowania oczekiwań starej pętli i obowiązkowy dodatkowy POST resume mimo zdrowego snapshot_running / snapshot_tick. Poprawka usuwa te zależności i pokazuje przebieg kliknięcia.

Wznów resetuje wyłącznie lokalne błędy, flagę wyczerpania, zatrzymanie i timery oczekiwania. Zawieszona lokalna pętla jest najpierw kończona; nowa zaczyna się po jej zakończeniu. Nie zerujemy `working` na ślepo i nie uruchamiamy drugiego fetch podczas aktywnego żądania. Podwójne kliknięcie jest blokowane. Aktywne żądanie, sesja, owner, zmieniony run/attempt i blokada backendu mają jawny blocked_reason.

Po GET statusu zdrowe `snapshot_running`, `next_action=snapshot_tick`, `busy=false` przechodzi bezpośrednio do ticka. Nie wymaga dodatkowego POST resume. Serwerowe snapshot_paused i pozostałe fazy zachowują dotychczasową akcję resume. Pokazywana sekwencja: resume_started, local_retry_state_reset, status_loaded, next_action, tick_scheduled lub blocked.

Test produkcyjnego przypadku: run 8de6e27c-ad7c-470b-830b-b9ecee9446ba, attempt 9cb33c92-3f1e-4db0-b08d-052715aa5f4c, part_categories / cursor435 / processed15759. Pięć fetch failures, retry_exhausted, ręczne Wznów, GET i jeden udany tick dają cursor440 / processed15764. Testy obejmują też reload, double click i utracony timer. Wszystkie żądania są przechwycone lokalnie.

Po wdrożeniu odświeżyć panel i użyć Wznów istniejący eksport. Nie używać Ponów snapshot. Zmiany obejmują wyłącznie Blade/JS oraz testy/dokumentację; bez backendu, granic, formatu, run/attempt, danych, zdjęć, providerów i operacji produkcyjnych.

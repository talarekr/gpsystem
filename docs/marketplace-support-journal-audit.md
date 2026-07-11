# Audyt Dziennika obsługi i read-only support sync

Markery: `marketplace_support_journal_audit_v1`, `marketplace_support_api_capabilities_v1`, `marketplace_support_read_only_diagnostics_v1`, `marketplace_support_normalization_v1`.

## 1. Obecny Dziennik obsługi

- Widok panelu głównego to `app/Filament/Pages/Dashboard.php` z widokiem Blade `filament.pages.dashboard`; aktualna karta dziennika jest renderowana także przez Livewire `app/Livewire/Admin/ShopEvents.php`.
- Zakładki są generowane statycznie jako: `all`, `requires_action`, `messages`, `returns_complaints`.
- `all` i `requires_action` nie korzystają dziś z `shop_events`: pobierają zamówienia z tabeli `orders` o statusie `new`; `requires_action` zawęża do nowych zamówień starszych niż 1 dzień.
- `messages` i `returns_complaints` pobierają model `ShopEvent` z tabeli `shop_events`; typy wiadomości to `customer_message`, `product_question`, a typy zwrotowo-reklamacyjne to `return`, `complaint`.
- Istnieje ogólny model aktywności `ShopEvent`, ale nie istnieje osobny model rozmowy marketplace, wiadomości marketplace, zwrotu marketplace ani reklamacji/sporu.
- Pole `ShopEvent::requires_action` istnieje, lecz obecny dashboard/Livewire nie używa go dla zakładki „Wymaga reakcji”. W tej zakładce regułą jest wyłącznie opóźnione nowe zamówienie.
- Powiązania obecnego wpisu dziennika są luźne: `ShopEvent` ma `source`, `external_reference`, `url` i `payload`, ale nie ma FK do `orders`, `parts`, klienta, konta marketplace ani marketplace order ID.
- Przycisk „Otwórz” dla wpisów z zamówień prowadzi do lokalnego widoku zamówienia Filament. Dla `ShopEvent` prowadzi do wartości `url` z rekordu, bez gwarancji lokalnego szczegółu.
- Nie znaleziono osobnej strony szczegółów rozmowy/zwrotu; obecne szczegóły są albo widokiem zamówienia, albo linkiem zewnętrznym/niestandardowym URL.
- Odświeżanie sekcji dashboardu jest pasywne: Livewire zmienia zakładkę i odczytuje dane z DB na żądanie; nie ma tu uruchamiania importu marketplace.

## 2. Obecna synchronizacja zamówień

- Ręczny endpoint diagnostyczno-importowy: `MarketplaceOrdersSyncController`, `/admin/tools/marketplace/orders-sync`, domyślnie dry-run, `apply` wymaga `confirm=sync-orders`.
- Komendy: `marketplace:sync-orders` oraz scheduler `marketplace:auto-sync-orders`. Auto-sync jest wyłączany flagą `marketplace_order_sync.enabled`.
- Wspólna usługa importu to `MarketplaceOrdersImportService`.
- Allegro: klient `AllegroApiClient` obsługuje refresh tokenu; zamówienia są czytane z `GET /order/checkout-forms`, accept `application/vnd.allegro.public.v1+json`, token OAuth z `MarketplaceAccount.api_credentials`.
- eBay: obecna integracja zamówień używa Sell Fulfillment API `GET /sell/fulfillment/v1/order`, token OAuth z `MarketplaceAccount`, header `X-EBAY-C-MARKETPLACE-ID` (`EBAY_DE`/`EBAY_FR`) i refresh tokenu przez konfigurację OAuth.
- Ovoko: obecny import zamówień używa formularzowego POST do `/v2/get/orders/{dateFrom}/{dateTo}` z `username`, `password`, `user_token`. To jest odczyt danych zamówień, ale technicznie HTTP POST według API Ovoko.
- Paginacja: Allegro/eBay budują query z limitem; eBay Sell Fulfillment obsługuje limit/offset, Allegro checkout-forms limit/offset. Ovoko w aktualnym wzorcu bazuje na zakresie dat.
- Incremental sync: obecny wzorzec opiera się głównie o `since`/zakres dat i deduplikację po marketplace order ID, a nie o webhooki supportowe.
- Idempotencja: import zamówień normalizuje `marketplace_order_id` i upsertuje lokalne zamówienia; dry-run zwraca `would_import` bez zapisu.
- Retry/rate limiting: wykryto refresh po 401; nie ma pełnego wspólnego backoff runnera dla support sync.
- Powiązanie z kontem: `MarketplaceAccount` zawiera `marketplace`, `code`, `api_enabled`, `api_base_url`, zaszyfrowane `api_credentials`, `api_settings`.

## 3. Możliwości API marketplace (oficjalna dokumentacja)

| Marketplace | Wiadomości | Zwroty | Reklamacje/spory | Webhook/event | Uwagi |
|---|---:|---:|---:|---:|---|
| Allegro | Ograniczone / brak potwierdzonego generic Message Center w obecnym kodzie | Tak, obszar customer returns | Tak, obszar disputes | Tak, dzienniki zdarzeń/API status wskazuje domenę disputes | Trzeba potwierdzić scopes dla konkretnej aplikacji przed importem treści. |
| eBay | Tak, ale rozdzielone między legacy member messages/communication i post-order messages | Tak, Post-Order Return API | Tak, Post-Order Inquiry/Case | Tak, Commerce Notifications/Notification API, część tematów może być restricted | Obecny kod używa Sell Fulfillment tylko dla zamówień; Post-Order może wymagać osobnego dostępu. |
| Ovoko | Nie znaleziono publicznie udokumentowanego endpointu | Nie znaleziono publicznie udokumentowanego endpointu supportowego | Nie znaleziono publicznie udokumentowanego endpointu | Nie potwierdzono | Nie wymyślać endpointów; fallback tylko po potwierdzeniu przez Ovoko/opiekuna API. |

### Scope/tokeny

- Allegro: obecny order sync wymaga `allegro:api:orders:read`; dla zwrotów/sporów należy potwierdzić w panelu aplikacji Allegro pełną listę scope dla `customer-returns`/`disputes`. Token bearer OAuth, odświeżany przez istniejącego klienta.
- eBay: obecny order sync używa `https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly`. Post-Order/Communication/Trading mogą wymagać innych uprawnień i zatwierdzenia aplikacji. Marketplace/site: `EBAY_DE`, `EBAY_FR` według `api_settings.marketplace_id`.
- Ovoko: obecny kod używa `username`, `password`, `user_token` i `api_base_url`; brak scope OAuth.

## 4. Proponowany wspólny model

Nie tworzono migracji. Zalecany nowy model zamiast przeciążania `shop_events`:

### `marketplace_support_threads`

`id`, `marketplace`, `marketplace_account_id nullable`, `external_thread_id`, `external_order_id nullable`, `local_order_id nullable`, `customer_name nullable`, `customer_identifier nullable`, `subject nullable`, `type`, `status`, `requires_action`, `unread`, `last_message_at nullable`, `opened_at nullable`, `closed_at nullable`, `source_url nullable`, `raw_payload nullable`, `last_synced_at nullable`, timestamps.

Indeksy: unique `(marketplace, external_thread_id)`, indeksy po `(marketplace, type, status)`, `local_order_id`, `requires_action`, `last_message_at`.

### `marketplace_support_messages`

`id`, `thread_id`, `external_message_id`, `sender_type`, `sender_name nullable`, `body_text nullable`, `body_html nullable`, `sent_at`, `is_read`, `requires_action`, `raw_payload nullable`, timestamps. Unique `(thread_id, external_message_id)`.

### `marketplace_support_attachments`

`id`, `message_id`, `external_attachment_id`, `filename`, `mime_type`, `size`, `source_url nullable`, `local_path nullable`, `raw_payload nullable`. Na etapie 1 nie pobierać plików lokalnie.

## 5. Normalizacja

- `type`: `message`, `return`, `complaint`, `dispute`, `cancellation`, `inquiry`.
- `status`: `open`, `waiting_for_seller`, `waiting_for_buyer`, `resolved`, `closed`, `cancelled`, `unknown`.
- `requires_action=true` tylko dla statusu/API jednoznacznie mówiącego o wymaganej akcji sprzedawcy, np. `WAITING_SELLER_RESPONSE`; nie dla każdego `unread`.
- `unread` jest osobne od `requires_action`.
- `unknown` nie jest traktowane jako wymagające reakcji.

## 6. Dodane endpointy read-only

- `GET /admin/tools/support-sync/allegro/diagnose?json=1`
- `GET /admin/tools/support-sync/ebay/diagnose?json=1`
- `GET /admin/tools/support-sync/ovoko/diagnose?json=1`
- `GET /admin/tools/support-sync/preview?marketplace=allegro&json=1`
- `GET /admin/tools/support-sync/preview?marketplace=ebay&json=1`
- `GET /admin/tools/support-sync/preview?marketplace=ovoko&json=1`

Domyślnie endpointy nie wykonują requestów marketplace. Opcjonalny `probe=1` jest mały, read-only i limitowany do jednego kontrolowanego GET tam, gdzie istnieje bezpieczny endpoint diagnostyczny. Brak zapisu do DB, brak oznaczania przeczytanych, brak odpowiedzi, brak zmian statusów.

## 7. Ryzyka i ograniczenia

- Allegro i eBay nie mają jednego wspólnego modelu supportu; część danych jest w obszarach post-order, część w legacy/oddzielnych API.
- eBay Post-Order/Communication może być legacy/restricted i wymagać zatwierdzenia aplikacji lub innych tokenów niż Sell Fulfillment.
- Ovoko publicznie dokumentuje integrację sprzedażowo-magazynową, ale nie potwierdzono API supportowego dla wiadomości/zwrotów/sporów.
- Załączniki i HTML muszą być traktowane jako niezaufane; HTML sanitizować, plików nie pobierać lokalnie w pierwszym etapie.
- Obecny „Wymaga reakcji” jest zamówieniowy, nie supportowy; integracja UI powinna nastąpić dopiero po stabilnym modelu i importerze.

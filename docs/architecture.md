# Arquitetura

```text
Telegram source
      |
      v
/webhook/telegram.php
      |
      +--> Bot (comandos do cliente)
      |
      +--> OfferProcessor
              |
              +--> source_offers (dedupe)
              |
              +--> AffiliateEngine
              |
              +--> deliveries (fila)
                         |
                         v
                   worker.php
                         |
                         v
                  Telegram destinos
```

## Responsabilidades

- `Bot`: experiência do cliente no Telegram.
- `OfferProcessor`: transforma update em oferta e cria entregas.
- `AffiliateEngine`: decide como personalizar URLs por usuário.
- `Queue`: envio assíncrono, retry e backoff.
- `MercadoPago`: PIX e ativação de assinatura.
- `Database`: persistência.
- `public/webhook`: entradas HTTP externas.

## Evolução recomendada

1. PostgreSQL quando a escala justificar.
2. Redis para fila distribuída.
3. Painel web completo para clientes.
4. OAuth/API oficial de cada programa de afiliados quando disponível.
5. Observabilidade com métricas e alertas.
6. Backup automático do banco.

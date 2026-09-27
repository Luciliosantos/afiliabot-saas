# Afiliabot SaaS

Sistema SaaS em PHP 8.2 para automatizar a publicação de ofertas de afiliados no Telegram.

## Arquitetura

- Telegram Bot API via webhook
- SQLite com WAL
- Worker de fila
- Mercado Pago Pix
- Webhook de pagamento
- Painel web administrativo
- Planos e assinaturas
- Contas de afiliados por cliente
- Destinos por cliente
- Deduplicação de ofertas
- Retry com backoff
- Suporte a texto, foto, vídeo e documento
- Configuração por `.env`
- Nenhuma credencial real no repositório

## Importante sobre links de afiliado

O sistema possui uma camada de adaptadores para transformar links por cliente. A transformação por parâmetros/query string é configurável e **não deve ser tratada como garantia de atribuição de comissão**. Cada programa de afiliados deve ser configurado de acordo com o método de geração de links aceito pela própria plataforma.

O cadastro permite guardar:
- identificador do afiliado;
- template opcional de link;
- ativo/inativo.

O template pode usar `{url}` como URL original e `{id}` como identificador.

## Requisitos

- PHP 8.2+
- extensões `pdo_sqlite`, `curl`, `json`, `mbstring`, `openssl`
- HTTPS para webhook em produção
- Bot com acesso à origem e aos grupos de destino

## Instalação

```bash
git clone SEU_REPOSITORIO
cd afiliabot-saas

cp .env.example .env
nano .env

mkdir -p data/logs data/cache
chmod -R 775 data

php bin/migrate.php
php bin/create_admin.php 123456789
php bin/set_webhook.php
```

O ID passado em `create_admin.php` deve ser o Telegram ID do administrador.

## Rodando o worker

Em desenvolvimento:

```bash
php bin/worker.php
```

Em produção, use Supervisor ou systemd.

Exemplo Supervisor:

```ini
[program:afiliabot-worker]
command=/usr/bin/php /var/www/afiliabot-saas/bin/worker.php
directory=/var/www/afiliabot-saas
autostart=true
autorestart=true
stderr_logfile=/var/www/afiliabot-saas/data/logs/worker.err.log
stdout_logfile=/var/www/afiliabot-saas/data/logs/worker.out.log
stopasgroup=true
killasgroup=true
```

## Web

Document root:

```text
/public
```

Exemplo Nginx:

```nginx
server {
    listen 443 ssl;
    server_name bot.seudominio.com;

    root /var/www/afiliabot-saas/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

## Webhook Telegram

O endpoint é:

```text
https://SEU-DOMINIO.COM/webhook/telegram.php
```

O script `bin/set_webhook.php` usa `APP_URL` e `TELEGRAM_WEBHOOK_SECRET`.

Telegram permite receber updates por webhook e recomenda validar o `secret_token` enviado no header. Consulte a documentação oficial antes da publicação.

## Mercado Pago

Configure no painel do Mercado Pago:
- aplicação;
- credencial de produção;
- Pix;
- Webhook HTTPS.

Webhook:

```text
https://SEU-DOMINIO.COM/webhook/mercadopago.php
```

Defina `MP_WEBHOOK_SECRET`.

O código usa `X-Idempotency-Key` na criação do pagamento e registra o evento recebido.

## Segurança

Nunca coloque tokens no Git.

Se uma credencial real for exposta:
1. revogue;
2. gere outra;
3. atualize `.env`;
4. não reutilize a antiga.

## Comandos

```bash
php bin/migrate.php
php bin/create_admin.php 123456789
php bin/set_webhook.php
php bin/healthcheck.php
php bin/worker.php
```

# BeTalent Multi-Gateway Payments API

API RESTful em Laravel para o desafio prático Back-end da BeTalent.

## Avaliação rápida

Execute os comandos abaixo na raiz do repositório:

```bash
docker compose up -d --build
curl http://localhost:8000/up
docker compose exec app php artisan test
```

Observações do ambiente Docker:

- o container `app` usa uma `APP_KEY` fixa de desenvolvimento via `docker-compose.yml`, então recriar o container não invalida os tokens por rotação acidental de chave
- o MySQL usa volume nomeado (`mysql_data`), então os dados persistem entre `docker compose down` e `docker compose up`
- o seed automático acontece apenas quando o banco está vazio; reiniciar a API não deve sobrescrever usuários e produtos já existentes
- para reiniciar do zero, use `docker compose down -v`

## Requisitos

- Docker e Docker Compose
- Portas livres: `8000`, `3001`, `3002`, `3306`

## Stack

- Laravel 12
- PHP 8.4+
- MySQL 8
- Laravel Sanctum para autenticação por token
- Docker Compose com `app`, `mysql` e `gateway-mock`
- GitHub Actions para validação automatizada

## Arquitetura

- `Domain`: enums e regras de estado
- `Application`: serviços de compra e reembolso
- `Infrastructure`: Eloquent, adapters HTTP dos gateways e seeders
- `HTTP`: controllers, requests, middleware, resources e resposta padronizada

## Decisões arquiteturais

- O valor da compra é sempre calculado no backend para evitar manipulação no cliente.
- O contrato entre a aplicação e os gateways passa por adapters, o que simplifica a adição de novos gateways.
- `gateway_attempts` existe para rastrear fallback, latência e falhas por tentativa.
- `external_id` é obrigatório para confirmar uma cobrança aprovada e permitir reembolso com segurança.
- O gateway salvo na transação representa apenas o gateway vencedor.

## Diagrama simplificado

```text
Client
  -> HTTP Controller
  -> FormRequest
  -> ProcessPaymentService / ProcessRefundService
  -> PaymentGatewayPort
       -> Gateway1Adapter
       -> Gateway2Adapter
  -> ORM / MySQL
```

## Regras de negócio

- Compra pública com múltiplos produtos
- Valor calculado exclusivamente no backend
- Gateways ativos são processados por ordem de prioridade crescente
- Falha em um gateway aciona tentativa no próximo gateway ativo
- A transação só vira `paid` quando existir aprovação com `external_id`
- Reembolso só é permitido para transações `paid`
- Reembolso usa obrigatoriamente o gateway vencedor da compra
- Exceções técnicas em um gateway são registradas como falha e não interrompem o fallback
- `external_id` só é aceito quando retornado pelo gateway; o sistema não faz correlação por listagem externa
- Cliente é criado ou reaproveitado automaticamente pelo email

## Status

### Transação

- `processing`
- `paid`
- `failed`
- `refund_processing`
- `refunded`
- `refund_failed`

### Reembolso

- `processing`
- `refunded`
- `refund_failed`

## Roles

- `ADMIN`: acesso total
- `MANAGER`: gerencia usuários e produtos, sem poder promover usuários para `ADMIN`
- `FINANCE`: gerencia produtos e realiza reembolso
- `USER`: acesso autenticado restante

## Estrutura principal

- `users`
- `gateways`
- `clients`
- `products`
- `transactions`
- `transaction_products`
- `gateway_attempts`
- `refunds`

## Como subir com Docker

Setup rápido:

1. `docker compose up -d --build`
2. Aguarde a API responder em `http://localhost:8000/up`
3. Faça login com um usuário seed
4. Teste compra e reembolso pelas rotas `/api`

```bash
docker compose up -d --build
curl http://localhost:8000/up
```

Serviços:

- API: `http://localhost:8000`
- Gateway 1 mock: `http://localhost:3001`
- Gateway 2 mock: `http://localhost:3002`
- MySQL: `localhost:3306`

Comportamento do bootstrap da API:

- o container espera o MySQL ficar acessível antes de rodar `migrate`
- o seed roda apenas no modo `if-empty`, evitando resetar os dados a cada restart
- a aplicação sobe como `www-data`, não como `root`

## Como rodar os testes

O caminho principal de validação do projeto é via Docker.

```bash
docker compose up -d --build
docker compose exec app php artisan test
```

Se a stack já estiver de pé:

```bash
docker compose exec app php artisan test
```

Para rodar apenas feature tests:

```bash
docker compose exec app php artisan test --testsuite=Feature
```

Se quiser resetar completamente a base persistida do Docker:

```bash
docker compose down -v
docker compose up -d --build
```

No host:

```bash
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit --testdox
```

Se `pdo_sqlite` e `sqlite3` não estiverem habilitados no host, use apenas a execução via Docker.

Se o seu ambiente usar o binário legado, substitua `docker compose` por `docker-compose`.

Arquivo de ambiente:

- Use `.env.example` como base para o fluxo Docker
- Para execução no host, ajuste `DB_HOST` e `GATEWAY_*_BASE_URL` para endereços acessíveis fora da rede do Compose
- No fluxo com Docker, o entrypoint cria `.env` automaticamente se o arquivo não existir

## Credenciais seed

Senha para todos os usuários seed: `password123`

- `admin@betalent.local` (`ADMIN`)
- `manager@betalent.local` (`MANAGER`)
- `finance@betalent.local` (`FINANCE`)
- `user@betalent.local` (`USER`)

Gateways seed:

- `gateway_1` prioridade `1`
- `gateway_2` prioridade `2`

Produtos seed:

- `Notebook Pro` - `549900`
- `Monitor 27` - `129900`
- `Mechanical Keyboard` - `39900`

## Validação manual dos mocks

- `cvv: "010"`: fluxo normal de aprovação
- `cvv: "100"`: falha no Gateway 1 e valida fallback no Gateway 2
- `cvv: "200"`: não deve ser usado para validar fallback, porque pode falhar nos dois gateways

## Autenticação

### Login

`POST /api/login`

Payload:

```json
{
  "email": "admin@betalent.local",
  "password": "password123"
}
```

Resposta:

```json
{
  "data": {
    "token": "plain-text-token",
    "user": {
      "id": 1,
      "name": "Admin User",
      "email": "admin@betalent.local",
      "role": "ADMIN"
    }
  },
  "request_id": "9a3c1d4f-4ad4-4f24-8a76-7b8c0f0d1234"
}
```

Use o token nas rotas privadas:

```text
Authorization: Bearer <token>
```

## Rotas

### Públicas

- `POST /api/login`
- `POST /api/purchases`

### Gateways

- `GET /api/gateways` (`ADMIN`, `MANAGER`, `FINANCE`)
- `PATCH /api/gateways/{gateway}/priority` (`ADMIN`)
- `PATCH /api/gateways/{gateway}/status` (`ADMIN`)

### Usuários

- `GET /api/users` (`ADMIN`, `MANAGER`)
- `POST /api/users` (`ADMIN`, `MANAGER`)
- `GET /api/users/{user}` (`ADMIN`, `MANAGER`)
- `PUT/PATCH /api/users/{user}` (`ADMIN`, `MANAGER`)
- `DELETE /api/users/{user}` (`ADMIN`, `MANAGER`)

### Produtos

- `GET /api/products` (`ADMIN`, `MANAGER`, `FINANCE`)
- `POST /api/products` (`ADMIN`, `MANAGER`, `FINANCE`)
- `GET /api/products/{product}` (`ADMIN`, `MANAGER`, `FINANCE`)
- `PUT/PATCH /api/products/{product}` (`ADMIN`, `MANAGER`, `FINANCE`)
- `DELETE /api/products/{product}` (`ADMIN`, `MANAGER`, `FINANCE`)

### Clientes e compras

- `GET /api/clients` (`ADMIN`, `MANAGER`, `FINANCE`)
- `GET /api/clients/{client}` (`ADMIN`, `MANAGER`, `FINANCE`)
- `GET /api/transactions` (`ADMIN`, `MANAGER`, `FINANCE`)
- `GET /api/transactions/{transaction}` (`ADMIN`, `MANAGER`, `FINANCE`)

Filtros disponíveis:

- `GET /api/transactions?status=paid&per_page=10`
- `GET /api/clients?email=tester`

### Reembolso

- `POST /api/refunds` (`ADMIN`, `FINANCE`)

## Contrato de compra

`POST /api/purchases`

```json
{
  "client": {
    "name": "Tester",
    "email": "tester@email.com"
  },
  "payment": {
    "card_number": "5569000000006063",
    "cvv": "100"
  },
  "items": [
    { "product_id": 1, "quantity": 1 },
    { "product_id": 2, "quantity": 1 }
  ]
}
```

Regras:

- `items` não pode ser vazio
- `quantity` deve ser maior que zero
- produto inativo não pode ser comprado
- `amount` total da transação é calculado no backend
- `unit_amount` e `line_total` ficam congelados no histórico da compra

## Contrato de reembolso

`POST /api/refunds`

```json
{
  "transaction_id": "uuid-da-transacao"
}
```

Regras:

- apenas transações `paid` podem ser reembolsadas
- transações `failed`, `refund_processing` e `refunded` são bloqueadas
- falha de reembolso mantém a transação em `paid` e registra a tentativa como `refund_failed`
- reembolso duplicado não é permitido enquanto existir um reembolso `processing` ou `refunded`

## Resposta de erro

```json
{
  "error": {
    "code": "payment_failed",
    "message": "All gateways failed to process this payment.",
    "details": {
      "transaction_id": "uuid",
      "failure_reason": "Gateway 2 authorization failed"
    }
  },
  "request_id": "9a3c1d4f-4ad4-4f24-8a76-7b8c0f0d1234"
}
```

Códigos usados:

- `validation_error`
- `forbidden`
- `payment_failed`
- `resource_not_found`
- `invalid_credentials`
- `internal_error`

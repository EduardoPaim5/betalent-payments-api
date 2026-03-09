# BeTalent Multi-Gateway Payments API

API RESTful em Laravel para o desafio pratico Back-end da BeTalent.

## Avaliacao rapida

Execute os comandos abaixo na raiz do repositorio:

```bash
docker compose up -d --build
curl http://localhost:8000/up
docker compose exec app php artisan test
```

Se o seu ambiente usar o binario legado, substitua `docker compose` por `docker-compose`.

## Requisitos

- Docker e Docker Compose
- Portas livres: `8000`, `3001`, `3002`, `3306`

## Stack

- Laravel 12
- PHP 8.4+
- MySQL 8
- Laravel Sanctum para autenticacao por token
- Docker Compose com `app`, `mysql` e `gateway-mock`
- GitHub Actions para validacao automatica

## Arquitetura

- `Domain`: enums e regras de estado
- `Application`: servicos de compra e reembolso
- `Infrastructure`: Eloquent, adapters HTTP dos gateways e seeders
- `HTTP`: controllers, requests, middleware, resources e resposta padronizada

## Architectural Decisions

- O valor da compra e sempre calculado no backend para evitar manipulacao no cliente
- O contrato entre a aplicacao e os gateways passa por adapters, o que simplifica a adicao de novos gateways
- `gateway_attempts` existe para rastrear fallback, latencia e falhas por tentativa
- `external_id` e obrigatorio para confirmar uma cobranca aprovada e permitir reembolso com seguranca
- O gateway salvo na transacao representa apenas o gateway vencedor

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

## Regras de negocio

- Compra publica com multiplos produtos
- Valor calculado exclusivamente no backend
- Gateways ativos sao processados por ordem de prioridade crescente
- Falha em um gateway aciona tentativa no proximo gateway ativo
- A transacao so vira `paid` quando existir aprovacao com `external_id`
- Reembolso so e permitido para transacoes `paid`
- Reembolso usa obrigatoriamente o gateway vencedor da compra
- Cliente e criado ou reaproveitado automaticamente pelo email

## Status

### Transacao

- `processing`
- `paid`
- `failed`
- `refunded`
- `refund_failed`

### Reembolso

- `processing`
- `refunded`
- `refund_failed`

## Roles

- `ADMIN`: acesso total
- `MANAGER`: gerencia usuarios e produtos, sem poder promover usuarios para `ADMIN`
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

Setup rapido:

1. `docker compose up -d --build`
2. Aguardar a API responder em `http://localhost:8000/up`
3. Fazer login com um usuario seed
4. Testar compra e reembolso pelas rotas `/api`

```bash
docker compose up -d --build
curl http://localhost:8000/up
```

Servicos:

- API: `http://localhost:8000`
- Gateway 1 mock: `http://localhost:3001`
- Gateway 2 mock: `http://localhost:3002`
- MySQL: `localhost:3306`

## Como rodar os testes

O caminho principal de validacao do projeto e via Docker. Esse e o comando que o avaliador pode executar para validar o criterio de TDD do nivel 3.

Todos os comandos abaixo assumem execucao na raiz do repositorio.

Via Docker:

```bash
docker compose up -d --build
docker compose exec app php artisan test
```

Se a stack ja estiver de pe:

```bash
docker compose exec app php artisan test
```

Para rebuildar a aplicacao antes de rodar novamente:

```bash
docker compose up -d --build
docker compose exec app php artisan test
```

Opcionalmente, para rodar apenas testes de feature:

```bash
docker compose exec app php artisan test --testsuite=Feature
```

No host:

```bash
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit --testdox
```

CI:

- Workflow em `.github/workflows/tests.yml`
- Executa `composer install`, `php artisan key:generate` e `php artisan test`

Arquivo de ambiente:

- Use `.env.example` como base para execucao local
- No fluxo com Docker, o entrypoint cria `.env` automaticamente se o arquivo nao existir

## Credenciais seed

Senha para todos os usuarios seed: `password123`

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

## Validacao manual dos mocks

- `cvv: "010"`: fluxo normal de aprovacao
- `cvv: "100"`: falha no Gateway 1 e valida fallback no Gateway 2
- `cvv: "200"`: nao deve ser usado para validar fallback, porque pode falhar nos dois gateways

## Autenticacao

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

### Publicas

- `POST /api/login`
- `POST /api/purchases`

### Gateways

- `GET /api/gateways`
- `PATCH /api/gateways/{gateway}/priority` (`ADMIN`)
- `PATCH /api/gateways/{gateway}/status` (`ADMIN`)

### Usuarios

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

- `GET /api/clients`
- `GET /api/clients/{client}`
- `GET /api/transactions`
- `GET /api/transactions/{transaction}`

Filtros disponiveis:

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

- `items` nao pode ser vazio
- `quantity` deve ser maior que zero
- produto inativo nao pode ser comprado
- `amount` total da transacao e calculado no backend
- `unit_amount` e `line_total` ficam congelados no historico da compra

## Contrato de reembolso

`POST /api/refunds`

```json
{
  "transaction_id": "uuid-da-transacao"
}
```

Regras:

- apenas transacoes `paid` podem ser reembolsadas
- transacoes `failed`, `refunded` e `refund_failed` sao bloqueadas
- reembolso duplicado nao e permitido porque o status da transacao e atualizado apos o primeiro processamento

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

Codigos usados:

- `validation_error`
- `forbidden`
- `payment_failed`
- `resource_not_found`
- `invalid_credentials`
- `internal_error`

## O que foi validado

- login por Sanctum
- CRUD basico de usuarios e produtos
- ativacao e reordenacao de gateways
- compra com multiplos produtos
- fallback entre gateways
- persistencia de `external_id`
- reembolso no gateway correto
- `request_id` em header e payload das respostas da API
- serializacao controlada com API Resources

## Melhorias futuras

- idempotencia para criacao de compra
- filtros adicionais nas listagens
- observabilidade mais detalhada
- testes de contrato para os gateways externos

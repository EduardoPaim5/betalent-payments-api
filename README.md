# BeTalent Multi-Gateway Payments API

API RESTful em Laravel para o desafio prático Back-end da BeTalent.

## Avaliação rápida

Execute os comandos abaixo na raiz do repositório:

```bash
docker compose up -d --build
curl http://localhost:8000/up
docker compose exec app php artisan test
docker compose exec app php scripts/smoke.php
make test-bruno
```

Observações do ambiente Docker:

- o container gera uma `APP_KEY` local automaticamente quando ela não é fornecida pelo ambiente
- o MySQL usa volume nomeado (`mysql_data`), então os dados persistem entre `docker compose down` e `docker compose up`
- o seed automático acontece apenas quando o banco está vazio; reiniciar a API não deve sobrescrever usuários e produtos já existentes
- `docker compose exec app php artisan test` usa SQLite em memória dentro do processo de testes e não deve limpar o MySQL da stack
- existe um smoke test HTTP real em `scripts/smoke.php` para validar login, compra com fallback, replay idempotente e reembolso
- para reiniciar do zero, use `docker compose down -v`

## Requisitos

- Docker e Docker Compose
- Portas livres: `8000`, `3001`, `3002`, `3306`

## Stack

- Laravel 12
- PHP 8.5
- MySQL 8
- Laravel Sanctum para autenticação por token
- Docker Compose com `app`, `mysql` e `gateway-mock`
- GitHub Actions para validação automatizada

## Arquitetura

- Controllers finos com `FormRequest`, `Resource` e resposta JSON padronizada
- Policies para autorização de recursos e regras de acesso por role
- Services de pagamento separados por responsabilidade: idempotência, itens, criação de transação, tentativas e transições de estado
- Adapters HTTP por gateway com contrato comum (`PaymentGatewayPort`)
- Eloquent/MySQL para persistência de transações, itens, tentativas e reembolsos

## Decisões arquiteturais

- O valor da compra é sempre calculado no backend para evitar manipulação no cliente.
- Compras aceitam `Idempotency-Key` opcional para evitar duplicidade em retries do cliente.
- O fingerprint idempotente é estável e não inclui CVV para não persistir dado sensível de autenticação.
- O contrato entre a aplicação e os gateways passa por adapters, o que simplifica a adição de novos gateways.
- `gateway_attempts` existe para rastrear fallback, latência e falhas por tentativa.
- `external_id` é obrigatório para confirmar uma cobrança aprovada e permitir reembolso com segurança.
- respostas ambíguas do gateway não geram fallback automático para evitar risco de dupla cobrança
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
- Aprovação sem `external_id` encerra o fluxo e exige revisão manual
- Reembolso só é permitido para transações `paid`
- Reembolso usa obrigatoriamente o gateway vencedor da compra
- Exceções técnicas em um gateway são registradas como falha e não interrompem o fallback
- `external_id` só é aceito quando retornado pelo gateway; o sistema não faz correlação por listagem externa
- Cliente é criado ou reaproveitado automaticamente pelo email, preservando o nome já persistido

## Status

### Transação

- `processing`
- `paid`
- `failed`
- `refund_processing`
- `refunded`

### Reembolso

- `processing`
- `refunded`
- `refund_failed`

## Roles

- `ADMIN`: acesso total
- `MANAGER`: gerencia usuários e produtos, sem poder promover usuários para `ADMIN`
- `FINANCE`: gerencia produtos e realiza reembolso
- `USER`: acesso autenticado restante

## Matriz de permissões

As regras abaixo refletem as policies e validações atuais da API.

| Ação | ADMIN | MANAGER | FINANCE | USER | Observação |
|---|---|---|---|---|---|
| `POST /api/login` | Sim | Sim | Sim | Sim | Rota pública |
| `POST /api/purchases` | Sim | Sim | Sim | Sim | Rota pública |
| `GET /api/gateways` | Sim | Sim | Sim | Não | Leitura da configuração |
| `PATCH /api/gateways/{gateway}/priority` | Sim | Não | Não | Não | Apenas `ADMIN` |
| `PATCH /api/gateways/{gateway}/status` | Sim | Não | Não | Não | Apenas `ADMIN` |
| `GET /api/users` | Sim | Sim | Não | Não | `MANAGER` vê `FINANCE`, `USER` e o próprio usuário |
| `POST /api/users` | Sim | Sim | Não | Não | `MANAGER` só pode criar `FINANCE` e `USER` |
| `GET /api/users/{user}` | Sim | Parcial | Não | Não | `MANAGER` só pode ver usuários gerenciáveis e a própria conta |
| `PUT/PATCH /api/users/{user}` | Sim | Parcial | Não | Não | `MANAGER` só pode atualizar `FINANCE` e `USER` |
| `DELETE /api/users/{user}` | Sim | Parcial | Não | Não | `MANAGER` só pode remover `FINANCE` e `USER`; ninguém pode remover a própria conta |
| `GET /api/products` | Sim | Sim | Sim | Não | |
| `POST /api/products` | Sim | Sim | Sim | Não | |
| `GET /api/products/{product}` | Sim | Sim | Sim | Não | |
| `PUT/PATCH /api/products/{product}` | Sim | Sim | Sim | Não | |
| `DELETE /api/products/{product}` | Sim | Sim | Sim | Não | Bloqueado se houver histórico de compra |
| `GET /api/clients` | Sim | Sim | Sim | Não | |
| `GET /api/clients/{client}` | Sim | Sim | Sim | Não | |
| `GET /api/transactions` | Sim | Sim | Sim | Não | |
| `GET /api/transactions/{transaction}` | Sim | Sim | Sim | Não | |
| `POST /api/refunds` | Sim | Não | Sim | Não | Apenas `ADMIN` e `FINANCE` |

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
- o healthcheck do Compose valida `GET /up`, não apenas a porta aberta
- a aplicação sobe como `www-data`, não como `root`

## Como rodar os testes

O caminho principal de validação do projeto é via Docker.

```bash
docker compose up -d --build
docker compose exec app php artisan test
docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS betalent_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON betalent_test.* TO 'betalent'@'%'; FLUSH PRIVILEGES;"
docker compose exec -e RUN_CRITICAL_MYSQL_TESTS=true -e TEST_DB_CONNECTION=mysql -e TEST_DB_HOST=mysql -e TEST_DB_PORT=3306 -e TEST_DB_DATABASE=betalent_test -e TEST_DB_USERNAME=betalent -e TEST_DB_PASSWORD=betalent app php artisan test --testsuite=CriticalMySql
docker compose exec -e RUN_GATEWAY_INTEGRATION_TESTS=true app php artisan test --testsuite=Integration
docker compose exec app php scripts/smoke.php
make test-bruno
```

Se a stack já estiver de pé:

```bash
docker compose exec app php artisan test
```

Para rodar apenas feature tests:

```bash
docker compose exec app php artisan test --testsuite=Feature
```

Para validar os mocks reais dos gateways via suíte de integração opcional:

```bash
docker compose exec -e RUN_GATEWAY_INTEGRATION_TESTS=true app php artisan test --testsuite=Integration
```

Para validar os fluxos críticos também em MySQL real, usando uma base isolada de teste:

```bash
docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS betalent_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON betalent_test.* TO 'betalent'@'%'; FLUSH PRIVILEGES;"
docker compose exec -e RUN_CRITICAL_MYSQL_TESTS=true -e TEST_DB_CONNECTION=mysql -e TEST_DB_HOST=mysql -e TEST_DB_PORT=3306 -e TEST_DB_DATABASE=betalent_test -e TEST_DB_USERNAME=betalent -e TEST_DB_PASSWORD=betalent app php artisan test --testsuite=CriticalMySql
```

Para validar a collection Bruno com variáveis dinâmicas reais (token, IDs e transação criada na hora):

```bash
make test-bruno
```

Opcionalmente, para usar outra URL da API:

```bash
BRUNO_BASE_URL=http://127.0.0.1:8000 make test-bruno
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

Observações:

- a suíte padrão (`php artisan test`) usa SQLite em memória e não altera a base MySQL persistida do Compose
- a suíte `Integration` fica separada porque depende dos mocks HTTP reais estarem acessíveis

### Mapa das suítes de validação

| Suíte / comando | Banco | Integrações reais | Valida |
|---|---|---|---|
| `php artisan test` | SQLite em memória | Não | unit e feature tests rápidos do domínio, autorização, validação, rate limit e fluxos de compra/reembolso com `Http::fake()` |
| `php artisan test --testsuite=CriticalMySql` | MySQL real (`betalent_test`) | Não | fluxos críticos persistidos em MySQL real: cálculo da compra, fallback, replay idempotente, corrida por `idempotency_key` e reembolso |
| `php artisan test --testsuite=Integration` | SQLite em memória | Sim, `gateway-mock` | cenários reais de gateway: aprovação primária, fallback, falha total, falha de autenticação e refund |
| `php scripts/smoke.php` | MySQL da stack | Sim, API HTTP + `gateway-mock` | validação ponta a ponta de login, compra com fallback, replay idempotente, detalhe de transação e reembolso |
| `make test-bruno` (`scripts/validate_bruno_collection.sh`) | MySQL da stack | Sim, API HTTP + `gateway-mock` + execução da collection Bruno | execução real dos requests da collection com variáveis dinâmicas resolvidas automaticamente, incluindo testes/assertions simples nas rotas principais |

Se o seu ambiente usar o binário legado, substitua `docker compose` por `docker-compose`.

Atalhos com `Makefile`:

```bash
make up
make composer-validate
make test
make test-critical-mysql
make test-integration
make test-bruno
make smoke
make verify
```

`make verify` replica a validação principal da entrega localmente: valida o `composer.json`, confere estilo, executa a suíte rápida da aplicação, a suíte crítica contra MySQL real, a suíte de integração contra os mocks reais, o smoke test HTTP e a execução da collection Bruno.

## Cobertura automatizada

O repositório contém testes automatizados para os principais fluxos do desafio.

Unit:

- `tests/Unit/Payments/PaymentIdempotencyServiceTest.php`
- `tests/Unit/Payments/RedactsGatewayPayloadTest.php`

Feature:

- autenticação: `tests/Feature/Auth/LoginTest.php`
- autorização e roles: `tests/Feature/Authorization/InternalDataAuthorizationTest.php`, `tests/Feature/Gateway/GatewayAuthorizationTest.php`, `tests/Feature/User/UserAuthorizationTest.php`
- gateways: `tests/Feature/Gateway/GatewayPriorityTest.php`
- produtos: `tests/Feature/Product/ProductLifecycleTest.php`
- compras: `tests/Feature/Purchase/CreatePurchaseTest.php`, `tests/Feature/Purchase/AllGatewaysFailTest.php`, `tests/Feature/Purchase/InactiveGatewaySkipTest.php`, `tests/Feature/Purchase/MissingExternalIdTest.php`, `tests/Feature/Purchase/IdempotencyRaceConditionTest.php`
- reembolso: `tests/Feature/Refund/RefundTransactionTest.php`
- suporte e bordas operacionais: `tests/Feature/Support/ListValidationTest.php`, `tests/Feature/Support/RateLimitTest.php`, `tests/Feature/Support/RequestIdTest.php`

Critical MySQL:

- `tests/Critical/MySqlCriticalFlowTest.php`

Integration:

- `tests/Integration/GatewayMockIntegrationTest.php`

Smoke HTTP:

- `scripts/smoke.php`

## Nota sobre TDD

O repositório demonstra objetivamente uma suíte de testes cobrindo os comportamentos críticos da aplicação.

O estado atual do código, por si só, não comprova que todo o desenvolvimento ocorreu em TDD. Essa evidência depende do histórico Git e da sequência de commits ou PRs. No estado atual da entrega, a evidência verificável é a cobertura automatizada dos fluxos acima.

## Limites conhecidos do design

- adicionar um novo gateway continua exigindo três passos explícitos: criar o adapter, registrar credenciais/configuração e incluir o adapter no `GatewayResolver` via `AppServiceProvider`
- replays idempotentes podem retornar a transação ainda em `processing` com HTTP `202` quando outro request concorrente já venceu a criação e ainda está concluindo a cobrança; nesse caso o cliente deve repetir a consulta ou buscar o detalhe da transação depois
- a sanitização de payloads de gateway é centralizada e cobre chaves sensíveis conhecidas por nome/padrão, mas não substitui disciplina de logging fora dos pontos que já usam o redactor
- a suíte `php artisan test` é a validação rápida do domínio; as verificações com MySQL real e mocks HTTP reais continuam separadas nas suítes `CriticalMySql` e `Integration`

Arquivo de ambiente:

- Use `.env.example` como base para o fluxo Docker
- Para execução no host, ajuste `DB_HOST` e `GATEWAY_*_BASE_URL` para endereços acessíveis fora da rede do Compose
- No fluxo com Docker, o entrypoint cria `.env` automaticamente se o arquivo não existir
- Os valores `GATEWAY_*` versionados neste repositório são apenas credenciais públicas do `gateway-mock` usado no desafio e não devem ser tratados como segredos de produção

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

## Exemplos de rotas privadas principais

Os exemplos abaixo usam placeholders como `<token>`, `<gateway-id>` e `<transaction-uuid>` para representar valores dinâmicos reais da execução.

### Listar gateways

`GET /api/gateways`

```bash
curl -X GET http://localhost:8000/api/gateways \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <token>"
```

Resposta resumida:

```json
{
  "data": {
    "gateways": [
      {
        "id": 1,
        "code": "gateway_1",
        "name": "Gateway 1",
        "is_active": true,
        "priority": 1,
        "created_at": "<timestamp>",
        "updated_at": "<timestamp>"
      }
    ]
  },
  "request_id": "<request-id>"
}
```

### Alterar prioridade de gateway

`PATCH /api/gateways/{gateway}/priority`

```bash
curl -X PATCH http://localhost:8000/api/gateways/<gateway-id>/priority \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"priority": 1}'
```

Resposta resumida:

```json
{
  "data": {
    "gateway": {
      "id": "<gateway-id>",
      "code": "gateway_2",
      "name": "Gateway 2",
      "is_active": true,
      "priority": 1,
      "created_at": "<timestamp>",
      "updated_at": "<timestamp>"
    }
  },
  "request_id": "<request-id>"
}
```

### Criar usuário

`POST /api/users`

```bash
curl -X POST http://localhost:8000/api/users \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{
    "name": "New Finance User",
    "email": "new.finance@betalent.local",
    "password": "password123",
    "role": "FINANCE"
  }'
```

Resposta resumida:

```json
{
  "data": {
    "user": {
      "id": "<user-id>",
      "name": "New Finance User",
      "email": "new.finance@betalent.local",
      "role": "FINANCE",
      "created_at": "<timestamp>",
      "updated_at": "<timestamp>"
    }
  },
  "request_id": "<request-id>"
}
```

### Criar produto

`POST /api/products`

```bash
curl -X POST http://localhost:8000/api/products \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{
    "name": "Headset Pro",
    "amount": 199900,
    "is_active": true
  }'
```

Resposta resumida:

```json
{
  "data": {
    "product": {
      "id": "<product-id>",
      "name": "Headset Pro",
      "amount": 199900,
      "is_active": true,
      "created_at": "<timestamp>",
      "updated_at": "<timestamp>"
    }
  },
  "request_id": "<request-id>"
}
```

### Consultar transação detalhada

`GET /api/transactions/{transaction}`

```bash
curl -X GET http://localhost:8000/api/transactions/<transaction-uuid> \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <token>"
```

Resposta resumida:

```json
{
  "data": {
    "transaction": {
      "id": "<transaction-uuid>",
      "client_id": "<client-id>",
      "gateway_id": "<gateway-id>",
      "status": "paid",
      "amount": 1000,
      "card_last_numbers": "6063",
      "failure_reason": null,
      "created_at": "<timestamp>",
      "updated_at": "<timestamp>",
      "client": {
        "id": "<client-id>",
        "name": "Tester",
        "email": "tester@email.com",
        "created_at": "<timestamp>",
        "updated_at": "<timestamp>"
      },
      "gateway": {
        "id": "<gateway-id>",
        "code": "gateway_2",
        "name": "Gateway 2",
        "is_active": true,
        "priority": 2,
        "created_at": "<timestamp>",
        "updated_at": "<timestamp>"
      },
      "products": [
        {
          "id": "<product-id>",
          "name": "Notebook Pro",
          "amount": 549900,
          "is_active": true,
          "quantity": 1,
          "unit_amount": 549900,
          "line_total": 549900
        }
      ],
      "attempts": [
        {
          "id": "<attempt-id>",
          "gateway_id": "<gateway-id>",
          "attempt_order": 1,
          "success": false,
          "error_type": "business_error",
          "status_code": 422,
          "message": "declined",
          "latency_ms": 120,
          "created_at": "<timestamp>"
        }
      ],
      "refunds": []
    }
  },
  "request_id": "<request-id>"
}
```

### Reembolsar transação

`POST /api/refunds`

```bash
curl -X POST http://localhost:8000/api/refunds \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{
    "transaction_id": "<transaction-uuid>"
  }'
```

Resposta resumida:

```json
{
  "data": {
    "refund": {
      "id": "<refund-id>",
      "transaction_id": "<transaction-uuid>",
      "gateway_id": "<gateway-id>",
      "status": "refunded",
      "message": "Refund processed",
      "created_at": "<timestamp>",
      "updated_at": "<timestamp>"
    }
  },
  "request_id": "<request-id>"
}
```

## Collection Bruno

Existe uma collection Bruno pronta em `bruno/betalent-payments`.

Ela cobre:

- login
- gateways
- usuários
- produtos
- clientes
- transações
- reembolso

Observação:

- existe validação automatizada da execução da collection via `make test-bruno` (`scripts/validate_bruno_collection.sh`)
- os exemplos principais de uso e payloads também estão documentados neste README
- os arquivos `.bru` possuem validações simples nas rotas principais (`login`, `list gateways`, `list products`, `list transactions`, `create refund`) com checks de status HTTP e estrutura mínima do JSON
- na execução atual de `make test-bruno`, o CLI reporta `Tests: 10` e `Assertions: 10`

Variáveis do ambiente `local`:

- `baseUrl`
- `authToken`
- `gatewayId`
- `userId`
- `productId`
- `clientId`
- `transactionId`
- `newUserEmail`

## Contrato de compra

`POST /api/purchases`

Header opcional para retries seguros:

```text
Idempotency-Key: checkout-123
```

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
- a mesma `Idempotency-Key` com o mesmo payload estável retorna a transação já criada
- a mesma `Idempotency-Key` com payload estável diferente é rejeitada com `validation_error`
- o payload estável considera cliente, itens e fingerprint do cartão; o CVV não entra no hash persistido
- se o cliente corrigir apenas o CVV, deve enviar uma nova `Idempotency-Key`
- alterar o cartão, mesmo mantendo os mesmos últimos 4 dígitos, invalida o replay idempotente
- se um gateway responder com sucesso sem `external_id`, o fluxo é interrompido sem tentar outro gateway

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

## Operações administrativas

- produtos com histórico de compra não podem ser removidos via `DELETE`; nesse caso a API retorna conflito e o caminho correto é desativar o produto
- login e compra pública possuem rate limiting para reduzir abuso básico da API

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
- `unauthenticated`
- `forbidden`
- `payment_failed`
- `resource_not_found`
- `invalid_credentials`
- `internal_error`
